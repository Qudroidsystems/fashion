<?php

namespace App\Services\Production;

use App\Models\Customer;
use App\Models\Fitting;
use App\Models\FittingReminder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductionTask;
use App\Models\User;
use App\Notifications\FittingReminderDue;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Fittings (SRS FR-PRD-005, §10, scenario 40). Each booking gets its own
 * reminder rows; moving or cancelling a booking cancels them, so the customer
 * is never reminded about a time that no longer stands.
 */
class FittingService
{
    public function __construct(protected ProductionService $production)
    {
    }

    /** $data: customer_id|order_id, order_line_id?, task_id?, staff_id?, branch_id?, scheduled_at (house local time), duration_minutes?, notes? */
    public function schedule(array $data, User $by): Fitting
    {
        $tz = CurrentTenant::get()->timezone;
        $at = Carbon::parse($data['scheduled_at'], $tz)->utc();
        if ($at->isPast()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a time in the future.']);
        }
        $order = ! empty($data['order_id']) ? Order::findOrFail($data['order_id']) : null;
        $line = ! empty($data['order_line_id']) ? OrderLine::where('order_id', $order?->id)->findOrFail($data['order_line_id']) : null;
        $customerId = $order?->customer_id ?? ($data['customer_id'] ?? null);
        if (! $customerId || ! Customer::whereKey($customerId)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Choose the customer.']);
        }
        if ($order && in_array($order->status, ['draft', 'cancelled', 'closed'], true)) {
            throw ValidationException::withMessages(['order_id' => 'Fittings can only be booked on confirmed orders.']);
        }
        // Link the garment's open fitting stage unless one was chosen.
        $task = ! empty($data['task_id']) ? ProductionTask::where('order_id', $order?->id)->find($data['task_id'])
            : ($line ? ProductionTask::where('order_line_id', $line->id)->where('type', 'fitting')->open()->orderBy('position')->first() : null);

        return DB::transaction(function () use ($data, $by, $at, $order, $line, $customerId, $task) {
            $fitting = Fitting::create([
                'number' => $data['number'] ?? DocumentNumber::next('fitting'), 'customer_id' => $customerId, 'order_id' => $order?->id,
                'order_line_id' => $line?->id ?? $task?->order_line_id, 'task_id' => $task?->id,
                'branch_id' => $data['branch_id'] ?? $order?->branch_id, 'staff_id' => $data['staff_id'] ?? $task?->assigned_to,
                'scheduled_at' => $at, 'duration_minutes' => (int) ($data['duration_minutes'] ?? 30), 'status' => 'scheduled',
                'notes' => $data['notes'] ?? null, 'created_by' => $by->id,
            ]);
            $this->makeReminders($fitting);
            if ($task) {
                $this->production->note($task, 'Fitting booked for ' . $fitting->scheduled_at->timezone(CurrentTenant::get()->timezone)->format('D d M, H:i'));
            }
            AuditLogger::log('fitting.scheduled', $fitting, after: ['at' => $at->toIso8601String(), 'order' => $order?->number]);
            if ($order) {
                $this->production->syncOrder($order->fresh());
            }

            return $fitting;
        });
    }

    /** Move a booking: the old one is kept as "moved" and its reminders are cancelled (scenario 40). */
    public function reschedule(Fitting $fitting, string $when, ?string $reason, User $by): Fitting
    {
        if ($fitting->status !== 'scheduled') {
            throw ValidationException::withMessages(['scheduled_at' => 'Only booked fittings can be moved.']);
        }

        return DB::transaction(function () use ($fitting, $when, $reason, $by) {
            $this->cancelReminders($fitting);
            $fitting->update(['status' => 'rescheduled', 'notes' => trim(($fitting->notes ? $fitting->notes . "\n" : '') . 'Moved: ' . ($reason ?: 'no reason given'))]);
            $new = $this->schedule([
                'order_id' => $fitting->order_id, 'customer_id' => $fitting->customer_id, 'order_line_id' => $fitting->order_line_id,
                'task_id' => $fitting->task_id, 'staff_id' => $fitting->staff_id, 'branch_id' => $fitting->branch_id,
                'scheduled_at' => $when, 'duration_minutes' => $fitting->duration_minutes, 'notes' => $reason,
            ], $by);
            $fitting->update(['rescheduled_to_id' => $new->id]);
            AuditLogger::log('fitting.rescheduled', $fitting, ['at' => $fitting->scheduled_at->toIso8601String()], ['at' => $new->scheduled_at->toIso8601String(), 'fitting' => $new->number], description: $reason);

            return $new;
        });
    }

    public function cancel(Fitting $fitting, string $reason): Fitting
    {
        if ($fitting->status !== 'scheduled') {
            throw ValidationException::withMessages(['fitting' => 'Only booked fittings can be cancelled.']);
        }

        return DB::transaction(function () use ($fitting, $reason) {
            $this->cancelReminders($fitting);
            $fitting->update(['status' => 'cancelled', 'notes' => trim(($fitting->notes ? $fitting->notes . "\n" : '') . 'Cancelled: ' . $reason)]);
            AuditLogger::log('fitting.cancelled', $fitting, description: $reason);
            if ($fitting->order) {
                $this->production->syncOrder($fitting->order->fresh());
            }

            return $fitting;
        });
    }

    public function cancelForOrder(Order $order, string $reason): void
    {
        Fitting::where('order_id', $order->id)->where('status', 'scheduled')->get()->each(function ($f) use ($reason) {
            $this->cancelReminders($f);
            $f->update(['status' => 'cancelled', 'notes' => trim(($f->notes ? $f->notes . "\n" : '') . 'Order cancelled: ' . $reason)]);
        });
    }

    /**
     * What happened at the fitting. Approved finishes the linked fitting stage;
     * alterations become tasks that hold the fitting stage until they are done,
     * after which the stage can be finished or another fitting booked.
     *
     * @param  array  $alterations  [['name','reason','responsible_party','cost_treatment','cost','assigned_to','due_date']]
     */
    public function record(Fitting $fitting, string $status, ?string $outcome, ?string $notes, array $alterations, User $by): Fitting
    {
        if (! in_array($fitting->status, ['scheduled'], true)) {
            throw ValidationException::withMessages(['status' => 'This fitting has already been recorded.']);
        }
        if ($status === 'attended' && ! in_array($outcome, ['approved', 'alterations'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Say whether the garment was approved or needs alterations.']);
        }
        if ($outcome === 'alterations' && ! collect($alterations)->filter(fn ($a) => trim($a['name'] ?? '') !== '')->count()) {
            throw ValidationException::withMessages(['alterations' => 'List the alterations needed.']);
        }

        return DB::transaction(function () use ($fitting, $status, $outcome, $notes, $alterations, $by) {
            FittingReminder::where('fitting_id', $fitting->id)->where('status', 'pending')->update(['status' => 'skipped']);
            $fitting->update([
                'status' => $status, 'outcome' => $status === 'attended' ? $outcome : null, 'recorded_by' => $by->id, 'recorded_at' => now(),
                'notes' => trim(($fitting->notes ? $fitting->notes . "\n" : '') . ($notes ?? '')) ?: null,
            ]);
            $task = $fitting->task;

            if ($status === 'attended' && $outcome === 'alterations' && $fitting->order) {
                foreach ($alterations as $a) {
                    if (trim($a['name'] ?? '') === '') {
                        continue;
                    }
                    $this->production->addFix($fitting->order, $fitting->line, array_merge(['reason' => "Found at fitting {$fitting->number}"], array_filter($a, fn ($v) => $v !== null && $v !== '')), $by, 'alteration', $task?->isOpen() ? $task : null);
                }
            } elseif ($status === 'attended' && $outcome === 'approved' && $task && $task->isOpen() && $task->status !== 'blocked') {
                $this->production->complete($task, $by, 'Fitting approved' . ($notes ? ": {$notes}" : ''));
            } elseif ($task && $task->isOpen()) {
                $this->production->note($task, $status === 'no_show' ? 'Customer did not come to the fitting' : 'Fitting recorded');
            }
            AuditLogger::log('fitting.recorded', $fitting, after: ['status' => $status, 'outcome' => $outcome, 'alterations' => count($alterations)]);
            if ($fitting->order) {
                $this->production->syncOrder($fitting->order->fresh());
            }

            return $fitting->fresh();
        });
    }

    /** Reminder rows for the house's lead times (default 24h and 2h before). */
    protected function makeReminders(Fitting $fitting): void
    {
        $hours = collect(CurrentTenant::get()->setting('fitting_reminder_hours', [24, 2]))->map(fn ($h) => (int) $h)->filter(fn ($h) => $h > 0)->unique();
        foreach ($hours as $h) {
            $at = $fitting->scheduled_at->copy()->subHours($h);
            if ($at->isFuture()) {
                FittingReminder::create(['fitting_id' => $fitting->id, 'remind_at' => $at, 'hours_before' => $h]);
            }
        }
    }

    protected function cancelReminders(Fitting $fitting): void
    {
        FittingReminder::where('fitting_id', $fitting->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    /** Send reminders that are due (runs every five minutes for every house). */
    public function sendDue(): int
    {
        $sent = 0;
        FittingReminder::with('fitting.customer.consents', 'fitting.staff', 'fitting.branch')->where('status', 'pending')
            ->where('remind_at', '<=', now())->orderBy('remind_at')->limit(200)->get()
            ->each(function (FittingReminder $r) use (&$sent) {
                $f = $r->fitting;
                if (! $f || $f->status !== 'scheduled' || $f->scheduled_at->isPast()) {
                    $r->update(['status' => 'skipped']);

                    return;
                }
                // Claim it first so two workers never send the same reminder.
                if (! FittingReminder::whereKey($r->id)->where('status', 'pending')->update(['status' => 'sent', 'sent_at' => now()])) {
                    return;
                }
                $f->staff?->notify(new FittingReminderDue($f, false));
                $c = $f->customer;
                if ($c?->email && $c->hasConsent('email', 'transactional')) {
                    Notification::route('mail', $c->email)->notify(new FittingReminderDue($f, true));
                }
                \App\Services\Messaging\CustomerUpdates::safely(fn ($u) => $u->fittingReminder($f, $r->id));
                $sent++;
            });

        return $sent;
    }
}
