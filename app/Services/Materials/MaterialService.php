<?php

namespace App\Services\Materials;

use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\CustomerMaterialEvent;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Notifications\MaterialsAwaitingReturn;
use App\Services\Audit\AuditLogger;
use App\Services\Messaging\Messenger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer materials: receive and tag, keep track of where they are, give them back — and remind
 * the customer and the house when something should have gone back and hasn't.
 *
 * When is an item "due to go back"?
 *  - on the collect-by date agreed with the customer, if there is one;
 *  - else, if it belongs to an order: once that order has been handed over or cancelled
 *    (leftover fabric, the original garment of an alteration, a style sample…);
 *  - else, once it has been with the house for "unlinked_days" (default 14).
 * The first reminder goes "remind_after_days" after that, then every "repeat_days", at most
 * "max_reminders" times. Staff get a daily in-app list of everything overdue.
 */
class MaterialService
{
    public const MAX_PHOTOS = 6;

    public function __construct(protected Messenger $messenger)
    {
    }

    /* ------------------------------ settings ------------------------------ */

    public function settings(): array
    {
        $t = CurrentTenant::require();
        $s = (array) $t->setting('materials', []);

        return [
            'reminders'         => (bool) ($s['reminders'] ?? true),
            'channels'          => array_values($s['channels'] ?? ['email', 'sms', 'whatsapp']),
            'unlinked_days'     => (int) ($s['unlinked_days'] ?? 14),
            'remind_after_days' => (int) ($s['remind_after_days'] ?? 2),
            'repeat_days'       => (int) ($s['repeat_days'] ?? 7),
            'max_reminders'     => (int) ($s['max_reminders'] ?? 4),
            'staff_digest'      => (bool) ($s['staff_digest'] ?? true),
        ];
    }

    public function saveSettings(array $data): void
    {
        $tenant = CurrentTenant::require();
        $settings = $tenant->settings ?? [];
        $keep = array_intersect_key((array) ($settings['materials'] ?? []), array_flip(['last_digest_on']));
        $settings['materials'] = $keep + [
            'reminders' => (bool) ($data['reminders'] ?? false),
            'channels' => array_values(array_intersect($data['channels'] ?? [], ['email', 'sms', 'whatsapp'])),
            'unlinked_days' => (int) $data['unlinked_days'], 'remind_after_days' => (int) $data['remind_after_days'],
            'repeat_days' => (int) $data['repeat_days'], 'max_reminders' => (int) $data['max_reminders'],
            'staff_digest' => (bool) ($data['staff_digest'] ?? false),
        ];
        $tenant->update(['settings' => $settings]);
        AuditLogger::log('materials.settings_changed', $tenant, after: $settings['materials']);
    }

    /* ------------------------------- receiving ------------------------------ */

    /**
     * $data: customer_id, order_id?, order_line_id?, branch_id?, kind, purpose, description, colour?, quantity?, unit?,
     *        condition_notes?, location?, collect_by?, received_at?
     * @param UploadedFile[] $photos
     */
    public function receive(array $data, User $by, array $photos = []): CustomerMaterial
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $order = $this->orderFor($customer, $data['order_id'] ?? null);

        return DB::transaction(function () use ($data, $by, $photos, $customer, $order) {
            $m = CustomerMaterial::create([
                'number' => DocumentNumber::next('customer_material'), 'customer_id' => $customer->id,
                'order_id' => $order?->id, 'order_line_id' => $order ? $this->lineFor($order, $data['order_line_id'] ?? null) : null,
                'branch_id' => $data['branch_id'] ?? CurrentTenant::membership()?->branch_id ?? $order?->branch_id,
                'kind' => $data['kind'], 'purpose' => $data['purpose'] ?? 'sew', 'description' => $data['description'],
                'colour' => $data['colour'] ?? null, 'quantity' => $data['quantity'] ?? null,
                'unit' => ! empty($data['quantity']) ? ($data['unit'] ?? 'yd') : ($data['unit'] ?? null),
                'condition_notes' => $data['condition_notes'] ?? null, 'location' => $data['location'] ?? null,
                'collect_by' => $data['collect_by'] ?? null, 'status' => 'held',
                'received_at' => ! empty($data['received_at']) ? CarbonImmutable::parse($data['received_at'], $this->tz())->utc() : now(),
                'received_by' => $by->id, 'photos' => $this->storePhotos($photos),
            ]);
            $this->event($m, 'received', $m->summary . ($m->location ? ' · ' . $m->location : ''), $by);
            AuditLogger::log('material.received', $m, after: ['number' => $m->number, 'customer' => $customer->name, 'item' => $m->summary, 'order' => $order?->number]);

            return $m;
        });
    }

    /** Change the details (not the status). */
    public function update(CustomerMaterial $m, array $data, User $by, array $photos = []): CustomerMaterial
    {
        $before = $m->only(['description', 'colour', 'quantity', 'unit', 'kind', 'purpose', 'location', 'collect_by', 'order_id', 'condition_notes']);
        $order = array_key_exists('order_id', $data) ? $this->orderFor($m->customer, $data['order_id']) : $m->order;
        $m->fill(array_intersect_key($data, array_flip(['kind', 'purpose', 'description', 'colour', 'quantity', 'unit', 'condition_notes', 'location', 'collect_by'])));
        if (array_key_exists('order_id', $data)) {
            $m->order_id = $order?->id;
            $m->order_line_id = $order ? $this->lineFor($order, $data['order_line_id'] ?? $m->order_line_id) : null;
        }
        if ($photos) {
            $all = array_merge($m->photos ?? [], $this->storePhotos($photos));
            if (count($all) > self::MAX_PHOTOS) {
                throw ValidationException::withMessages(['photos' => 'Up to ' . self::MAX_PHOTOS . ' photos per item.']);
            }
            $m->photos = $all;
        }
        $dirty = array_keys($m->getDirty());
        $m->save();
        if ($moved = in_array('location', $dirty, true)) {
            $this->event($m, 'moved', 'Now at: ' . ($m->location ?: '—'), $by);
        }
        if (in_array('order_id', $dirty, true) && $m->order_id) {
            $this->event($m, 'linked', $order->number, $by);
        }
        if ($photos) {
            $this->event($m, 'photo', count($photos) . ' photo(s)', $by);
        }
        if (array_diff($dirty, ['location', 'order_id', 'order_line_id', 'photos', 'updated_at'])) {
            $this->event($m, 'updated', null, $by);
        }
        AuditLogger::log('material.updated', $m, $before, $m->only(array_keys($before)));

        return $m;
    }

    /* -------------------------------- moving -------------------------------- */

    /** Into the workroom ("with Bisi") or back to the store shelf. */
    public function move(CustomerMaterial $m, string $to, ?string $location, User $by): CustomerMaterial
    {
        if (! $m->isWithHouse()) {
            throw ValidationException::withMessages(['status' => 'This item is no longer with the house.']);
        }
        $m->update(['status' => $to === 'in_use' ? 'in_use' : 'held', 'location' => $location ?: $m->location]);
        $this->event($m, $to === 'in_use' ? 'in_use' : 'back_in_store', $location, $by);

        return $m;
    }

    /**
     * Give items back. $data: returned_to, returned_to_phone?, note?, quantities? [id => leftover], returned_at?
     * @param Collection<CustomerMaterial> $items
     */
    public function giveBack(Collection $items, array $data, User $by, ?Delivery $delivery = null): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }
        if ($gone = $items->first(fn ($m) => ! $m->isWithHouse())) {
            throw ValidationException::withMessages(['materials' => "{$gone->number} is no longer with the house."]);
        }
        if ($items->pluck('customer_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['materials' => 'Return one customer’s items at a time.']);
        }
        if (! trim((string) ($data['returned_to'] ?? ''))) {
            throw ValidationException::withMessages(['returned_to' => 'Who collected it?']);
        }
        $at = ! empty($data['returned_at']) ? CarbonImmutable::parse($data['returned_at'], $this->tz())->utc() : now();

        return DB::transaction(function () use ($items, $data, $by, $delivery, $at) {
            foreach ($items as $m) {
                $qty = $data['quantities'][$m->id] ?? $data['quantities'][$m->public_id] ?? null;
                $m->update([
                    'status' => 'returned', 'returned_at' => $at, 'returned_by' => $by->id,
                    'returned_to' => $data['returned_to'], 'returned_to_phone' => $data['returned_to_phone'] ?? null,
                    'returned_quantity' => $qty !== null && $qty !== '' ? (float) $qty : null,
                    'return_note' => $data['note'] ?? null, 'delivery_id' => $delivery?->id,
                ]);
                $this->event($m, 'returned', 'To ' . $data['returned_to'] . ($delivery ? ' with ' . $delivery->number : '') . ($m->returned_quantity ? ' · ' . rtrim(rtrim(number_format($m->returned_quantity, 2), '0'), '.') . ' ' . $m->unit . ' left over' : ''), $by);
            }
            AuditLogger::log('material.returned', $items->first()->customer, after: [
                'items' => $items->pluck('number')->all(), 'to' => $data['returned_to'], 'delivery' => $delivery?->number,
            ]);

            return $items;
        });
    }

    /** Nothing to give back (all used in the garment), or lost/damaged with a reason. */
    public function close(CustomerMaterial $m, string $how, string $note, User $by): CustomerMaterial
    {
        if (! $m->isWithHouse()) {
            throw ValidationException::withMessages(['status' => 'This item is no longer with the house.']);
        }
        if ($how === 'written_off' && ! trim($note)) {
            throw ValidationException::withMessages(['note' => 'Say what happened to it.']);
        }
        $m->update(['status' => $how === 'written_off' ? 'written_off' : 'used', 'returned_at' => now(), 'returned_by' => $by->id, 'return_note' => $note ?: null]);
        $this->event($m, $how === 'written_off' ? 'written_off' : 'used', $note ?: null, $by);
        AuditLogger::log('material.' . $m->status, $m, after: ['number' => $m->number, 'note' => $note]);

        return $m;
    }

    public function note(CustomerMaterial $m, string $note, User $by): void
    {
        $this->event($m, 'note', $note, $by);
    }

    /* ------------------------------ reminders ------------------------------- */

    /** When the item should have gone back (house date), or null while it is still needed. */
    public function dueOn(CustomerMaterial $m): ?CarbonImmutable
    {
        if (! $m->isWithHouse()) {
            return null;
        }
        $tz = $this->tz();
        if ($m->collect_by) {
            return CarbonImmutable::parse($m->collect_by->toDateString(), $tz);
        }
        if ($m->order_id) {
            $o = $m->order;
            if (! $o || ! in_array($o->status, ['delivered', 'closed', 'cancelled'], true)) {
                return null;
            }
            $when = $o->status === 'cancelled'
                ? ($o->cancelled_at ?? $o->updated_at)
                : (Delivery::where('order_id', $o->id)->max('handed_over_at') ?? $o->updated_at);

            return CarbonImmutable::parse($when)->timezone($tz)->startOfDay();
        }

        return CarbonImmutable::parse($m->received_at)->timezone($tz)->startOfDay()->addDays($this->settings()['unlinked_days']);
    }

    /** When the next automatic reminder goes, or null (not due, reminders off, or enough sent). */
    public function nextReminder(CustomerMaterial $m): ?CarbonImmutable
    {
        $s = $this->settings();
        $due = $this->dueOn($m);
        if (! $due || ! $s['reminders'] || $m->reminders_sent >= $s['max_reminders']) {
            return null;
        }

        return $m->reminders_sent === 0 || ! $m->last_reminded_at
            ? $due->addDays($s['remind_after_days'])
            : CarbonImmutable::parse($m->last_reminded_at)->timezone($this->tz())->startOfDay()->addDays($s['repeat_days']);
    }

    /** Items the house should already have given back. */
    public function overdue(): Collection
    {
        $today = CarbonImmutable::now($this->tz())->startOfDay();

        return CustomerMaterial::withHouse()->with('customer', 'order')->orderBy('received_at')->get()
            ->filter(fn ($m) => ($d = $this->dueOn($m)) && $d->lte($today))->values();
    }

    /** The reminder text for one customer's items: [subject, text, whatsapp template values]. */
    public function message(Customer $c, Collection $items): array
    {
        $t = CurrentTenant::require();
        $branch = $items->first()->branch;
        $where = $branch ? $branch->name . ($branch->address ? ' (' . $branch->address . ')' : '') : ($t->address ?: $t->name);
        $phone = $branch?->phone ?: $t->phone;
        $list = $items->map(fn ($m) => $m->summary . ' (tag ' . $m->number . ($m->order ? ', order ' . $m->order->number : '') . ')');
        $what = $items->count() === 1 ? 'this item' : 'these items';
        $text = "Hello {$c->first_name},\n\nThis is {$t->name}. We are still keeping {$what} you left with us:\n"
            . $list->map(fn ($l) => '• ' . $l)->implode("\n")
            . "\n\nPlease come and collect " . ($items->count() === 1 ? 'it' : 'them') . " at {$where}"
            . ($phone ? ", or call us on {$phone} to arrange delivery" : '') . ".\n\nThank you.";

        return [
            "{$t->name}: your " . ($items->count() === 1 ? 'item is' : 'items are') . ' ready to collect',
            $text,
            [$c->first_name, $list->implode('; '), $t->name],
        ];
    }

    /** Remind one customer about everything of theirs the house still has. */
    public function remind(Customer $c, array $channels, ?User $by = null, ?Collection $items = null): Collection
    {
        $items ??= CustomerMaterial::withHouse()->where('customer_id', $c->id)->with('order', 'branch')->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['materials' => 'Nothing of this customer’s is with the house.']);
        }
        [$subject, $text, $params] = $this->message($c, $items);
        $logs = $this->messenger->send($c, $channels, $subject, $text, [
            'purpose' => 'material_reminder', 'related' => $items->first(), 'by' => $by, 'whatsapp_params' => $params,
        ]);
        $sent = $logs->where('status', 'sent');
        $how = $sent->isNotEmpty() ? $sent->pluck('channel')->map(fn ($ch) => \App\Models\MessageLog::CHANNELS[$ch])->implode(', ') : 'not sent';
        foreach ($items as $m) {
            if ($sent->isNotEmpty()) {
                $m->forceFill(['reminders_sent' => $m->reminders_sent + 1, 'last_reminded_at' => now()])->save();
            }
            $this->event($m, 'reminded', $how . ($by ? '' : ' (automatic)'), $by, [
                'channels' => $logs->map(fn ($l) => ['channel' => $l->channel, 'status' => $l->status, 'error' => $l->error])->all(),
            ]);
        }

        return $logs;
    }

    /**
     * Automatic run (hourly, between 9am and 7pm house time): reminds customers whose items are due,
     * one message per customer, and sends staff the daily list. Returns the number of customers reminded.
     */
    public function sendDue(?CarbonImmutable $now = null): int
    {
        $tz = $this->tz();
        $now = ($now ?? CarbonImmutable::now())->timezone($tz);
        if ($now->hour < 9 || $now->hour >= 19) {
            return 0;
        }
        $s = $this->settings();
        $today = $now->startOfDay();
        $items = CustomerMaterial::withHouse()->with('customer', 'order', 'branch')->get();

        $count = 0;
        if ($s['reminders'] && $s['channels']) {
            $items->filter(fn ($m) => ($n = $this->nextReminder($m)) && $n->lte($today))
                ->groupBy('customer_id')
                ->each(function (Collection $group) use ($s, &$count) {
                    $c = $group->first()->customer;
                    if (! $c || $c->status === 'merged') {
                        return;
                    }
                    // Remind about everything of theirs still here, not only the items due today.
                    $all = CustomerMaterial::withHouse()->where('customer_id', $c->id)->with('order', 'branch')->get();
                    $this->remind($c, $s['channels'], null, $all);
                    $count++;
                });
        }
        $this->staffDigest($today);

        return $count;
    }

    /** Once a day: tell the people who look after materials what should have gone back. */
    protected function staffDigest(CarbonImmutable $today): void
    {
        $tenant = CurrentTenant::require();
        if (! $this->settings()['staff_digest'] || data_get($tenant->settings, 'materials.last_digest_on') === $today->toDateString()) {
            return;
        }
        $overdue = $this->overdue();
        $settings = $tenant->settings ?? [];
        data_set($settings, 'materials.last_digest_on', $today->toDateString());
        $tenant->update(['settings' => $settings]);
        if ($overdue->isEmpty()) {
            return;
        }
        User::inCurrentTenant()->get()->filter(fn (User $u) => $u->can('Manage customer materials'))
            ->each(fn (User $u) => $u->notify(new MaterialsAwaitingReturn($overdue->count(), $overdue->pluck('customer_id')->unique()->count(), $tenant->id)));
    }

    /* ------------------------------- helpers ------------------------------- */

    protected function orderFor(Customer $customer, mixed $ref): ?Order
    {
        if (! $ref) {
            return null;
        }
        $o = Order::where(fn ($q) => $q->where('id', is_numeric($ref) ? (int) $ref : 0)->orWhere('public_id', $ref)->orWhere('number', $ref))->first();
        if (! $o || $o->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['order_id' => 'That order is not this customer’s.']);
        }

        return $o;
    }

    protected function lineFor(Order $order, mixed $lineId): ?int
    {
        return $lineId ? $order->lines()->whereKey((int) $lineId)->value('id') : null;
    }

    /** @param UploadedFile[] $photos */
    protected function storePhotos(array $photos): array
    {
        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'Up to ' . self::MAX_PHOTOS . ' photos per item.']);
        }
        $dir = 'tenants/' . CurrentTenant::require()->public_id . '/materials';

        return collect($photos)->filter()->map(fn (UploadedFile $f) => $f->storeAs($dir, Str::random(16) . '.' . ($f->extension() ?: 'jpg'), 'public'))->values()->all();
    }

    public function event(CustomerMaterial $m, string $event, ?string $detail = null, ?User $by = null, ?array $meta = null): CustomerMaterialEvent
    {
        return CustomerMaterialEvent::create([
            'customer_material_id' => $m->id, 'event' => $event, 'detail' => $detail ? mb_substr($detail, 0, 255) : null,
            'meta' => $meta, 'user_id' => $by?->id, 'created_at' => now(),
        ]);
    }

    public function tz(): string
    {
        return CurrentTenant::get()?->timezone ?: config('app.timezone');
    }

    /** Remove one photo. */
    public function removePhoto(CustomerMaterial $m, int $index, User $by): void
    {
        $photos = $m->photos ?? [];
        if (! isset($photos[$index])) {
            return;
        }
        Storage::disk('public')->delete($photos[$index]);
        array_splice($photos, $index, 1);
        $m->update(['photos' => $photos]);
        $this->event($m, 'photo', 'Photo removed', $by);
    }
}
