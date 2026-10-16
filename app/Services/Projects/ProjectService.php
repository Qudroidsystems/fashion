<?php

namespace App\Services\Projects;

use App\Models\Customer;
use App\Models\MaterialIssue;
use App\Models\MeasurementSession;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Project;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectExpense;
use App\Models\ProjectMilestone;
use App\Models\ProjectParticipant;
use App\Models\ProjectPaymentSchedule;
use App\Models\ProjectUpdate;
use App\Models\User;
use App\Notifications\ProjectPublished;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Services\Orders\OrderService;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Weddings, events and bulk projects (SRS §5.10, §6.4).
 *
 * Every garment is an order line: on the project's main order (billed to the
 * client) or, for a participant who pays for themselves, on that person's own
 * order. All of them carry orders.project_id, so invoices, payments,
 * production, fittings and handover reuse the order machinery and project
 * totals are plain sums over the orders — nothing is counted twice (FR-PRJ-004).
 */
class ProjectService
{
    /** Starter milestones per project type: [name, days before the event (negative = after)]. */
    public const MILESTONES = [
        'wedding' => [
            ['Contract signed and deposit paid', 90, true], ['Fabrics chosen and bought', 75, true], ['Everyone measured', 60, true],
            ['First fittings', 30, true], ['Final fittings', 10, true], ['Everything ready for collection', 3, true],
        ],
        'event' => [
            ['Deposit paid', 45, true], ['Everyone measured', 35, true], ['Fittings', 14, true], ['Ready for collection', 3, true],
        ],
        'uniform' => [
            ['Order signed off', 60, true], ['Sizes collected', 50, true], ['Sample approved', 40, true], ['Production complete', 7, true], ['Delivered', 0, true],
        ],
        'corporate' => [
            ['Order signed off', 60, true], ['Sizes collected', 50, true], ['Sample approved', 40, true], ['Delivered', 0, true],
        ],
        'group' => [['Everyone measured', 30, true], ['Fittings', 10, true], ['Ready for collection', 3, true]],
        'other' => [['Measurements', 30, true], ['Ready', 3, true]],
    ];

    public function __construct(protected OrderService $orders)
    {
    }

    /* ------------------------------ project ------------------------------ */

    public function create(array $data, User $by, bool $withMilestones = true): Project
    {
        return DB::transaction(function () use ($data, $by, $withMilestones) {
            $project = Project::create(array_merge($this->fields($data), [
                'number' => DocumentNumber::next('project'), 'status' => 'planning', 'created_by' => $by->id,
                'manager_id' => $data['manager_id'] ?? $by->id,
                'branch_id' => $data['branch_id'] ?? \App\Models\Branch::where('is_main', true)->value('id'),
            ]));
            if ($withMilestones) {
                $this->addStarterMilestones($project);
            }
            AuditLogger::log('project.created', $project, after: $project->only(['number', 'name', 'type', 'event_date', 'contract_amount']));

            return $project;
        });
    }

    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            $before = $project->only(['name', 'type', 'customer_id', 'manager_id', 'event_date', 'delivery_date', 'contract_amount', 'deposit_amount', 'venue']);
            if (isset($data['customer_id']) && (int) $data['customer_id'] !== $project->customer_id && $project->orders()->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'The client can’t change once garments are on the project’s order.']);
            }
            $project->update($this->fields($data) + array_intersect_key($data, array_flip(['manager_id', 'branch_id'])));
            $after = $project->only(array_keys($before));
            [$b, $a] = AuditLogger::diff($this->stringify($before), $this->stringify($after));
            if ($a) {
                AuditLogger::log('project.updated', $project, $b, $a);
            }
            // Dates flow to the orders, with a revision on confirmed ones.
            if (array_key_exists('event_date', $a) || array_key_exists('delivery_date', $a) || array_key_exists('deposit_amount', $a)) {
                $project->orders()->whereIn('status', \App\Support\Orders\OrderStatus::EDITABLE)->get()
                    ->each(fn (Order $o) => $this->resave($o, $project, [], 'Project dates or deposit changed'));
            }

            return $project->fresh();
        });
    }

    protected function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'type', 'customer_id', 'event_date', 'delivery_date', 'venue', 'contract_amount', 'deposit_amount',
            'description', 'client_message', 'internal_notes',
        ]));
    }

    protected function stringify(array $a): array
    {
        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $a);
    }

    /** Planning → in progress: confirms every draft order on the project (§6.4 step 23). */
    public function confirm(Project $project, User $by): Project
    {
        if ($project->status !== 'planning') {
            throw ValidationException::withMessages(['project' => 'Only a project in planning can be confirmed.']);
        }
        $drafts = $project->orders()->where('status', 'draft')->get()->filter(fn ($o) => $o->lines()->where('status', '!=', 'cancelled')->exists());
        if ($drafts->isEmpty() && ! $project->orders()->whereNotIn('status', ['draft', 'cancelled'])->exists()) {
            throw ValidationException::withMessages(['project' => 'Add the participants’ garments before confirming the project.']);
        }

        return DB::transaction(function () use ($project, $by, $drafts) {
            $drafts->each(fn (Order $o) => $this->orders->transition($o, 'confirmed', "Project {$project->number} confirmed"));
            $project->update(['status' => 'active', 'confirmed_at' => now()]);
            AuditLogger::log('project.confirmed', $project, after: ['orders' => $drafts->pluck('number')->all()]);

            return $project->fresh();
        });
    }

    public function cancel(Project $project, string $reason, User $by): Project
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project' => 'This project is already ' . strtolower($project->status_label) . '.']);
        }

        return DB::transaction(function () use ($project, $reason) {
            $project->orders()->whereNotIn('status', ['cancelled', 'delivered', 'closed'])->get()
                ->each(fn (Order $o) => $this->orders->cancel($o, "Project cancelled: {$reason}"));
            $project->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason, 'tracking_token_hash' => null, 'tracking_token' => null]);
            AuditLogger::log('project.cancelled', $project, description: $reason);

            return $project->fresh();
        });
    }

    /** Final handover done (§6.4 step 29): every garment delivered or cancelled. */
    public function complete(Project $project): Project
    {
        if ($project->status !== 'active') {
            throw ValidationException::withMessages(['project' => 'Only a project in progress can be completed.']);
        }
        $open = $this->garmentLines($project)->whereNotIn('status', ['delivered', 'cancelled'])->count();
        if ($open) {
            throw ValidationException::withMessages(['project' => "{$open} garment(s) have not been handed over yet."]);
        }
        $project->update(['status' => 'completed', 'completed_at' => now()]);
        AuditLogger::log('project.completed', $project, after: $this->summary($project)['money'] ?? []);

        return $project->fresh();
    }

    /* ---------------------------- participants ---------------------------- */

    public function addParticipant(Project $project, array $data): ProjectParticipant
    {
        $this->assertOpen($project);
        $p = $project->participants()->create(array_intersect_key($data, array_flip(['name', 'phone', 'email', 'role', 'group_name', 'pays', 'notes', 'customer_id']))
            + ['sort_order' => (int) $project->participants()->max('sort_order') + 1]);
        AuditLogger::log('project.participant_added', $project, after: ['name' => $p->name, 'role' => $p->role, 'pays' => $p->pays]);

        return $p;
    }

    /** Many at once from pasted lines: "Name, phone, role" (§6.4 step 24). */
    public function addParticipantsFromText(Project $project, string $text, ?string $group, string $pays): Collection
    {
        $added = collect();
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $row) {
            $parts = array_map('trim', str_getcsv($row));
            if (($parts[0] ?? '') === '') {
                continue;
            }
            $added->push($this->addParticipant($project, ['name' => Str::limit($parts[0], 120, ''), 'phone' => $parts[1] ?? null,
                'role' => $parts[2] ?? null, 'group_name' => $group, 'pays' => $pays]));
        }

        return $added;
    }

    public function updateParticipant(ProjectParticipant $p, array $data): ProjectParticipant
    {
        $data = array_intersect_key($data, array_flip(['name', 'phone', 'email', 'role', 'group_name', 'pays', 'notes']));
        if (isset($data['pays']) && $data['pays'] !== $p->pays && $p->garments()->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['pays' => 'Remove their garments first, then change who pays.']);
        }
        $p->update($data);
        if (isset($data['name'])) {
            OrderLine::where('participant_id', $p->id)->update(['wearer' => $p->name]);
        }

        return $p;
    }

    /** Someone drops out: their unstarted garments come off the orders. */
    public function withdraw(ProjectParticipant $p, string $reason): void
    {
        $started = $p->garments()->whereIn('status', ['in_production', 'ready', 'delivered'])->count();
        if ($started) {
            throw ValidationException::withMessages(['participant' => "{$p->name} has {$started} garment(s) already in production or done."]);
        }
        DB::transaction(function () use ($p, $reason) {
            $p->garments()->where('status', 'pending')->with('order')->get()->groupBy('order_id')
                ->each(fn ($lines) => $this->removeLines($lines->first()->order, $lines->pluck('id')->all(), "{$p->name} withdrew: {$reason}"));
            $p->update(['status' => 'withdrawn']);
            AuditLogger::log('project.participant_withdrawn', $p->project, after: ['name' => $p->name], description: $reason);
        });
    }

    /**
     * A customer record for the participant, so they can be measured, pay and
     * get messages. Reuses an existing customer with the same phone or e-mail.
     */
    public function ensureCustomer(ProjectParticipant $p): Customer
    {
        if ($p->customer_id && ($c = Customer::find($p->customer_id))) {
            return $c;
        }
        $phone = Customer::normalizePhone($p->phone);
        $email = $p->email ? mb_strtolower(trim($p->email)) : null;
        $c = ($phone ? Customer::active()->where('phone_normalized', $phone)->first() : null)
            ?? ($email ? Customer::active()->where('email', $email)->first() : null);
        if (! $c) {
            $parts = preg_split('/\s+/', trim($p->name), 2);
            $c = Customer::create(['first_name' => $parts[0], 'last_name' => $parts[1] ?? null, 'phone' => $p->phone, 'email' => $email,
                'branch_id' => $p->project->branch_id, 'source' => 'project']);
            AuditLogger::log('customer.created', $c, description: "From project {$p->project->number}");
        }
        $p->update(['customer_id' => $c->id]);

        return $c;
    }

    /* ------------------------------ garments ------------------------------ */

    /**
     * Add the same garment for one or more participants (e.g. all bridesmaids).
     * $spec: kind, style_id | product_variant_id | description, options[], quantity, unit_price?, discount?, instructions?
     */
    public function addGarments(Project $project, Collection $participants, array $spec, User $by): Collection
    {
        $this->assertOpen($project);
        $participants = $participants->where('status', 'active');
        if ($participants->isEmpty()) {
            throw ValidationException::withMessages(['participants' => 'Choose who the garment is for.']);
        }

        return DB::transaction(function () use ($project, $participants, $spec, $by) {
            $byOrder = [];
            foreach ($participants as $p) {
                $order = $this->orderFor($project, $p, $by);
                $byOrder[$order->id]['order'] = $order;
                $byOrder[$order->id]['lines'][] = array_merge(array_intersect_key($spec, array_flip([
                    'kind', 'style_id', 'product_variant_id', 'description', 'options', 'quantity', 'unit_price', 'discount', 'instructions',
                ])), ['participant_id' => $p->id, 'wearer' => $p->name]);
            }
            $added = collect();
            foreach ($byOrder as ['order' => $order, 'lines' => $new]) {
                $before = $order->lines()->pluck('id')->all();
                $what = Str::limit(collect($new)->pluck('wearer')->implode(', '), 120);
                $order = $this->resave($order, $project, $new, "Garments added for {$what}");
                $added = $added->merge($order->lines->whereNotIn('id', $before));
            }
            AuditLogger::log('project.garments_added', $project, after: ['count' => $added->count(), 'for' => $participants->pluck('name')->all()]);

            return $added;
        });
    }

    public function removeGarment(OrderLine $line, string $reason): void
    {
        if ($line->status !== 'pending') {
            throw ValidationException::withMessages(['line' => 'Only garments not yet started can be removed. Use an alteration or cancel the order instead.']);
        }
        $this->removeLines($line->order, [$line->id], $reason);
    }

    /** Approved measurements of the participant on their garment (§6.4 step 24). */
    public function linkMeasurement(OrderLine $line, ?int $sessionId): OrderLine
    {
        return $this->orders->updateLine($line, ['measurement_session_id' => $sessionId ?: null]);
    }

    /** The order a participant's garment goes on: the client's main order, or their own. */
    public function orderFor(Project $project, ProjectParticipant $p, User $by): Order
    {
        if ($p->pays === 'self') {
            $customer = $this->ensureCustomer($p);
            $order = $project->orders()->where('customer_id', $customer->id)->whereIn('status', \App\Support\Orders\OrderStatus::EDITABLE)->oldest('id')->first();
        } else {
            $customer = $project->customer;
            $order = $project->orders()->where('customer_id', $project->customer_id)->whereIn('status', \App\Support\Orders\OrderStatus::EDITABLE)->oldest('id')->first();
        }
        if ($order) {
            return $order;
        }
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project' => 'This project is closed.']);
        }
        // A new draft (confirmed straight away when the project already is).
        $order = new Order([
            'number' => DocumentNumber::next('order'), 'status' => 'draft', 'customer_id' => $customer->id, 'project_id' => $project->id,
            'branch_id' => $project->branch_id, 'title' => $p->pays === 'self' ? "{$project->name} — {$p->name}" : $project->name,
            'event_date' => $project->event_date, 'promised_date' => $this->promiseDate($project), 'priority' => 'normal',
            'owner_id' => $project->manager_id ?? $by->id, 'created_by' => $by->id, 'subtotal' => 0, 'discount_total' => 0,
            'order_discount' => 0, 'total' => 0, 'deposit_required' => 0,
        ]);
        $order->save();
        AuditLogger::log('order.created', $order, after: ['number' => $order->number, 'project' => $project->number]);

        return $order;
    }

    protected function promiseDate(Project $project): ?string
    {
        return ($project->delivery_date ?? $project->event_date)?->toDateString();
    }

    /** Re-save an order with its current lines plus $new, keeping everything else as it is. */
    protected function resave(Order $order, Project $project, array $new, string $reason): Order
    {
        $order->load('lines');
        $lines = $order->lines->where('status', '!=', 'cancelled')->map(fn (OrderLine $l) => [
            'id' => $l->id, 'kind' => $l->kind, 'style_id' => $l->style_id, 'product_variant_id' => $l->product_variant_id,
            'description' => $l->description, 'options' => collect($l->options)->pluck('label')->all(), 'quantity' => $l->quantity,
            'unit_price' => $l->unit_price, 'discount' => $l->discount, 'instructions' => $l->instructions,
            'measurement_session_id' => $l->measurement_session_id, 'wearer' => $l->wearer, 'assigned_to' => $l->assigned_to,
            'participant_id' => $l->participant_id,
        ])->values()->all();
        $isMain = $order->customer_id === $project->customer_id;
        $total = null;
        $data = [
            'customer_id' => $order->customer_id, 'branch_id' => $order->branch_id, 'title' => $order->title,
            'event_date' => $project->event_date?->toDateString(), 'promised_date' => $this->promiseDate($project),
            'priority' => $order->priority, 'customer_notes' => $order->customer_notes, 'internal_notes' => $order->internal_notes,
            'owner_id' => $order->owner_id, 'order_discount' => $order->order_discount,
            'deposit_required' => $isMain && $project->deposit_amount > 0 ? $project->deposit_amount : null,
        ];
        $order = $this->orders->saveOrder($order, $data, array_merge($lines, $new), $order->status === 'draft' ? null : $reason);
        if ($order->project_id !== $project->id) {
            $order->forceFill(['project_id' => $project->id])->save();
        }
        // Garments added after confirmation are part of the confirmed order already.
        if ($order->status === 'draft' && $project->status === 'active') {
            $order = $this->orders->transition($order, 'confirmed', "Added to project {$project->number}");
        }

        return $order->fresh('lines');
    }

    protected function removeLines(Order $order, array $ids, string $reason): void
    {
        $project = $order->project;
        $order->load('lines');
        $keep = $order->lines->where('status', '!=', 'cancelled')->whereNotIn('id', $ids);
        if ($keep->isEmpty()) {
            // Nothing left on it: the order is cancelled (kept for the record).
            $this->orders->cancel($order, $reason);

            return;
        }
        $order->setRelation('lines', $keep);
        $lines = $keep->map(fn (OrderLine $l) => [
            'id' => $l->id, 'kind' => $l->kind, 'style_id' => $l->style_id, 'product_variant_id' => $l->product_variant_id,
            'description' => $l->description, 'options' => collect($l->options)->pluck('label')->all(), 'quantity' => $l->quantity,
            'unit_price' => $l->unit_price, 'discount' => $l->discount, 'instructions' => $l->instructions,
            'measurement_session_id' => $l->measurement_session_id, 'wearer' => $l->wearer, 'assigned_to' => $l->assigned_to,
            'participant_id' => $l->participant_id,
        ])->values()->all();
        $this->orders->saveOrder($order, [
            'customer_id' => $order->customer_id, 'branch_id' => $order->branch_id, 'title' => $order->title,
            'event_date' => $order->event_date?->toDateString(), 'promised_date' => $order->promised_date?->toDateString(),
            'priority' => $order->priority, 'customer_notes' => $order->customer_notes, 'internal_notes' => $order->internal_notes,
            'owner_id' => $order->owner_id, 'order_discount' => $order->order_discount,
            'deposit_required' => $project && $order->customer_id === $project->customer_id && $project->deposit_amount > 0 ? $project->deposit_amount : null,
        ], $lines, $order->status === 'draft' ? null : $reason);
    }

    public function garmentLines(Project $project)
    {
        return OrderLine::whereIn('order_id', $project->orders()->select('id'))->whereIn('kind', ['bespoke', 'ready_made', 'service']);
    }

    /* ----------------------- milestones and updates ----------------------- */

    public function addStarterMilestones(Project $project): void
    {
        $event = $project->event_date;
        foreach (self::MILESTONES[$project->type] ?? self::MILESTONES['other'] as $i => [$name, $daysBefore, $visible]) {
            $project->milestones()->create([
                'name' => $name, 'customer_visible' => $visible, 'sort_order' => $i + 1,
                'due_date' => $event ? $event->copy()->subDays($daysBefore)->max(now()->startOfDay())->toDateString() : null,
            ]);
        }
    }

    public function saveMilestone(Project $project, ?ProjectMilestone $m, array $data): ProjectMilestone
    {
        $fields = array_intersect_key($data, array_flip(['name', 'due_date', 'notes', 'customer_visible']));
        if ($m) {
            $m->update($fields);
            if (array_key_exists('customer_visible', $fields) && ! $fields['customer_visible']) {
                $m->update(['published_at' => null, 'published_by' => null]);   // hidden again
            }
        } else {
            $m = $project->milestones()->create($fields + ['sort_order' => (int) $project->milestones()->max('sort_order') + 1]);
        }

        return $m;
    }

    public function completeMilestone(ProjectMilestone $m, User $by, ?string $notes = null, bool $skip = false): ProjectMilestone
    {
        $m->update(['status' => $skip ? 'skipped' : 'done', 'completed_at' => now(), 'completed_by' => $by->id, 'notes' => $notes ?: $m->notes]);
        AuditLogger::log('project.milestone_' . ($skip ? 'skipped' : 'done'), $m->project, after: ['milestone' => $m->name]);
        // A milestone the client can already see: tell them it's done.
        if (! $skip && $m->isPublished()) {
            $this->tell($m->project, $m);
        }

        return $m;
    }

    public function reopenMilestone(ProjectMilestone $m): ProjectMilestone
    {
        $m->update(['status' => 'pending', 'completed_at' => null, 'completed_by' => null]);

        return $m;
    }

    /** Make a milestone visible to the client (needs "Publish project update"). */
    public function publishMilestone(ProjectMilestone $m, User $by): ProjectMilestone
    {
        $m->update(['customer_visible' => true, 'published_at' => now(), 'published_by' => $by->id]);
        AuditLogger::log('project.milestone_published', $m->project, after: ['milestone' => $m->name]);
        $this->tell($m->project, $m);

        return $m;
    }

    public function addUpdate(Project $project, array $data, array $images, User $by): ProjectUpdate
    {
        $paths = collect($images)->filter(fn ($f) => $f instanceof UploadedFile)->take(8)
            ->map(fn (UploadedFile $f) => $f->storeAs('tenants/' . CurrentTenant::get()->public_id . '/projects/' . $project->public_id,
                Str::random(12) . '.' . $f->extension(), 'public'))->values()->all();

        return $project->updates()->create([
            'title' => $data['title'] ?? null, 'body' => $data['body'], 'images' => $paths ?: null,
            'customer_visible' => (bool) ($data['customer_visible'] ?? false), 'status' => 'draft', 'created_by' => $by->id,
        ]);
    }

    public function publishUpdate(ProjectUpdate $u, User $by): ProjectUpdate
    {
        if (! $u->customer_visible) {
            $u->customer_visible = true;
        }
        $u->fill(['status' => 'published', 'published_at' => now(), 'published_by' => $by->id])->save();
        AuditLogger::log('project.update_published', $u->project, after: ['title' => $u->title ?: Str::limit($u->body, 60)]);
        $this->tell($u->project, $u);

        return $u;
    }

    public function unpublishUpdate(ProjectUpdate $u): ProjectUpdate
    {
        $u->update(['status' => 'draft', 'published_at' => null]);

        return $u;
    }

    /** Client by email (with consent), manager in-app (§10 "Project milestone published"). */
    protected function tell(Project $project, ProjectMilestone|ProjectUpdate $item): void
    {
        $project->loadMissing('customer', 'manager');
        $url = $project->trackingOpen() && ($t = $project->trackingToken()) ? route('projects.track', $t) : null;
        DB::afterCommit(function () use ($project, $item, $url) {
            $c = $project->customer;
            if ($c?->email && $c->hasConsent('email', 'transactional')) {
                Notification::route('mail', $c->email)->notify(new ProjectPublished($project, $item, true, $url));
            }
            if ($project->manager && $project->manager->id !== auth()->id()) {
                $project->manager->notify(new ProjectPublished($project, $item, false));
            }
        });
    }

    /* --------------------------- client tracking --------------------------- */

    /** New link (the old one stops working). Returns the plain token. */
    public function enableTracking(Project $project, ?int $days = null): string
    {
        $token = Str::random(40);
        $project->update([
            'tracking_token_hash' => Project::hashToken($token), 'tracking_token' => Crypt::encryptString($token),
            'tracking_expires_at' => $days ? now()->addDays($days) : null,
        ]);
        AuditLogger::log('project.tracking_link_created', $project, after: ['expires' => $project->tracking_expires_at?->toDateString()]);

        return $token;
    }

    public function disableTracking(Project $project): void
    {
        $project->update(['tracking_token_hash' => null, 'tracking_token' => null, 'tracking_expires_at' => null]);
        AuditLogger::log('project.tracking_link_revoked', $project);
    }

    /** Public lookup across houses (no session). */
    public function findByToken(string $token): ?Project
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return Project::withoutTenantScope()->where('tracking_token_hash', Project::hashToken($token))->first();
    }

    /**
     * What the client sees (FR-PRJ-006, scenario 39): published milestones and
     * updates only, garment progress as counts, no prices, notes or staff names.
     */
    public function publicView(Project $project): array
    {
        $lines = $this->garmentLines($project)->where('status', '!=', 'cancelled')->get();

        return [
            'name' => $project->name, 'type' => $project->type_label, 'event_date' => $project->event_date, 'delivery_date' => $project->delivery_date,
            'venue' => $project->venue, 'message' => $project->client_message, 'status' => $project->status,
            'garments' => [
                'total' => $lines->count(),
                'started' => $lines->whereIn('status', ['in_production', 'ready', 'delivered'])->count(),
                'ready' => $lines->whereIn('status', ['ready', 'delivered'])->count(),
                'delivered' => $lines->where('status', 'delivered')->count(),
            ],
            'milestones' => $project->milestones()->where('customer_visible', true)->whereNotNull('published_at')->get(),
            'updates' => $project->updates()->where('customer_visible', true)->where('status', 'published')->limit(20)->get(),
        ];
    }

    /* ------------------------------- money ------------------------------- */

    public function addBudgetLine(Project $project, array $data): ProjectBudgetLine
    {
        return $project->budgetLines()->create(array_intersect_key($data, array_flip(['category', 'description', 'amount'])));
    }

    public function addExpense(Project $project, array $data, ?UploadedFile $receipt, User $by): ProjectExpense
    {
        $path = $receipt?->storeAs('tenants/' . CurrentTenant::get()->public_id . '/projects/' . $project->public_id . '/receipts',
            Str::random(12) . '.' . $receipt->extension(), 'public');
        $e = $project->expenses()->create(array_intersect_key($data, array_flip(['category', 'description', 'amount', 'spent_on', 'paid_to']))
            + ['receipt_path' => $path, 'recorded_by' => $by->id]);
        AuditLogger::log('project.expense_added', $project, after: ['description' => $e->description, 'amount' => $e->amount]);

        return $e;
    }

    public function deleteExpense(ProjectExpense $e): void
    {
        AuditLogger::log('project.expense_removed', Project::find($e->project_id), before: ['description' => $e->description, 'amount' => $e->amount]);
        $e->delete();
    }

    public function addSchedule(Project $project, array $data): ProjectPaymentSchedule
    {
        return $project->schedules()->create(array_intersect_key($data, array_flip(['label', 'due_date', 'amount']))
            + ['sort_order' => (int) $project->schedules()->max('sort_order') + 1]);
    }

    /**
     * Everything FR-PRJ-007 asks for, worked out from the orders, payments,
     * stock ledger and expenses — never stored, so it can't drift.
     */
    public function summary(Project $project): array
    {
        $orders = $project->orders()->with('customer:id,first_name,last_name')->get();
        $live = $orders->where('status', '!=', 'cancelled');
        $clientOrders = $live->where('customer_id', $project->customer_id);
        $lines = $this->garmentLines($project)->where('status', '!=', 'cancelled')->get();

        $value = round($live->sum(fn (Order $o) => $o->chargeable()), 2);
        $planned = round($live->where('status', 'draft')->sum('total'), 2);
        $collected = round($live->sum(fn (Order $o) => $o->paid()), 2);
        $outstanding = round($live->sum(fn (Order $o) => max(0, $o->balance())), 2);
        $clientPaid = round($clientOrders->sum(fn (Order $o) => $o->paid()), 2);

        $materials = round((float) MaterialIssue::where(fn ($q) => $q->where('project_id', $project->id)->orWhereIn('order_id', $orders->pluck('id')))->sum('total_cost'), 2);
        $expenses = round((float) $project->expenses()->sum('amount'), 2);
        $budget = round((float) $project->budgetLines()->sum('amount'), 2);
        $cost = round($materials + $expenses, 2);
        $income = $value ?: $planned;

        // Payment schedule against what the client has paid so far, in date order.
        $tz = CurrentTenant::get()->timezone;
        $today = now($tz)->toDateString();
        $left = $clientPaid;
        $schedule = $project->schedules()->get()->map(function ($s) use (&$left, $today) {
            $covered = min($left, $s->amount);
            $left = round($left - $covered, 2);
            $state = $covered >= $s->amount - 0.004 ? 'paid' : ($covered > 0 ? 'part' : ($s->due_date->toDateString() < $today ? 'overdue' : 'due'));

            return ['row' => $s, 'covered' => $covered, 'state' => $state];
        });

        return [
            'garments' => [
                'total' => $lines->count(),
                'by_status' => $lines->countBy('status')->all(),
                'ready' => $lines->whereIn('status', ['ready', 'delivered'])->count(),
                'delivered' => $lines->where('status', 'delivered')->count(),
                'measured' => $lines->where('kind', 'bespoke')->whereNotNull('measurement_session_id')->count(),
                'bespoke' => $lines->where('kind', 'bespoke')->count(),
                'readiness' => $lines->count() ? (int) floor(100 * $lines->whereIn('status', ['ready', 'delivered'])->count() / $lines->count()) : 0,
            ],
            'money' => [
                'contract' => $project->contract_amount, 'value' => $value, 'planned' => $planned,
                'difference' => $project->contract_amount > 0 ? round(($value ?: $planned) - $project->contract_amount, 2) : null,
                'collected' => $collected, 'outstanding' => $outstanding, 'client_paid' => $clientPaid,
                'deposit' => $project->deposit_amount, 'deposit_paid' => $project->deposit_amount > 0 && $clientPaid >= $project->deposit_amount - 0.004,
            ],
            'costs' => [
                'budget' => $budget, 'materials' => $materials, 'expenses' => $expenses, 'actual' => $cost,
                'remaining_budget' => $budget > 0 ? round($budget - $cost, 2) : null,
                'margin' => round($income - $cost, 2), 'margin_percent' => $income > 0 ? round(100 * ($income - $cost) / $income, 1) : null,
            ],
            'orders' => $orders,
            'schedule' => $schedule,
            'next_milestone' => $project->milestones()->where('status', 'pending')->orderByRaw('due_date is null')->orderBy('due_date')->first(),
            'late_milestones' => $project->milestones()->where('status', 'pending')->whereDate('due_date', '<', $today)->count(),
        ];
    }

    protected function assertOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages(['project' => 'This project is ' . strtolower($project->status_label) . '.']);
        }
    }
}
