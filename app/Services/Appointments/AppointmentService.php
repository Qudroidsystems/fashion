<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Fitting;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Services\Audit\AuditLogger;
use App\Services\Messaging\CustomerUpdates;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appointments: book, move, record what happened, cancel. The customer gets a confirmation and a
 * reminder the day before (Customer messages settings); the staff member gets an in-app note.
 * A staff member can't be double-booked against another appointment or a fitting.
 */
class AppointmentService
{
    public function __construct(protected CustomerUpdates $updates)
    {
    }

    /** $data: customer_id, type, starts_at (house time), duration_minutes?, staff_id?, branch_id?, order_id?, notes?, allow_clash? */
    public function book(array $data, User $by): Appointment
    {
        $at = $this->when($data['starts_at']);
        $customer = Customer::findOrFail($data['customer_id']);
        $order = $this->orderFor($customer, $data['order_id'] ?? null);
        $minutes = (int) ($data['duration_minutes'] ?? 30);
        $this->checkClash($data['staff_id'] ?? null, $at, $minutes, null, ! empty($data['allow_clash']));

        $a = DB::transaction(function () use ($data, $by, $at, $customer, $order, $minutes) {
            $a = Appointment::create([
                'number' => DocumentNumber::next('appointment'), 'customer_id' => $customer->id, 'order_id' => $order?->id,
                'branch_id' => $data['branch_id'] ?? CurrentTenant::membership()?->branch_id ?? $order?->branch_id,
                'staff_id' => $data['staff_id'] ?? null, 'type' => $data['type'], 'starts_at' => $at->utc(),
                'duration_minutes' => $minutes, 'status' => 'booked', 'notes' => $data['notes'] ?? null, 'created_by' => $by->id,
            ]);
            AuditLogger::log('appointment.booked', $a, after: ['number' => $a->number, 'customer' => $customer->name, 'at' => $at->toIso8601String(), 'type' => $a->type]);

            return $a;
        });
        $this->afterBooking($a, $by);

        return $a;
    }

    public function reschedule(Appointment $a, string $when, ?int $minutes, User $by, bool $allowClash = false): Appointment
    {
        $this->assertOpen($a);
        $at = $this->when($when);
        $minutes ??= $a->duration_minutes;
        $this->checkClash($a->staff_id, $at, $minutes, $a->id, $allowClash);
        $before = $a->starts_at->toIso8601String();
        $a->update(['starts_at' => $at->utc(), 'duration_minutes' => $minutes]);
        AuditLogger::log('appointment.moved', $a, ['at' => $before], ['at' => $a->starts_at->toIso8601String()]);
        $this->afterBooking($a, $by);

        return $a;
    }

    /** $status: done, no_show, cancelled */
    public function setStatus(Appointment $a, string $status, ?string $note, User $by): Appointment
    {
        $this->assertOpen($a);
        if ($status === 'cancelled' && ! trim((string) $note)) {
            throw ValidationException::withMessages(['note' => 'Say why it was cancelled.']);
        }
        $a->update([
            'status' => $status,
            'outcome' => $status !== 'cancelled' ? ($note ?: null) : $a->outcome,
            'cancel_reason' => $status === 'cancelled' ? $note : null,
        ]);
        $this->updates->cancelFor($a);   // no reminder for something that already happened or was cancelled
        AuditLogger::log('appointment.' . $status, $a, after: ['note' => $note]);

        return $a;
    }

    /** Everything in the diary between two house dates: appointments and fittings, by day. */
    public function diary(CarbonImmutable $from, CarbonImmutable $to, ?int $staffId = null, ?int $branchId = null, bool $includeClosed = true): Collection
    {
        $tz = $this->tz();
        $range = [$from->timezone($tz)->startOfDay()->utc(), $to->timezone($tz)->endOfDay()->utc()];
        $apps = Appointment::with('customer:id,public_id,first_name,last_name,phone', 'staff:id,name', 'branch:id,name', 'order:id,public_id,number')
            ->whereBetween('starts_at', $range)
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $includeClosed, fn ($q) => $q->where('status', 'booked'))
            ->get()->map(fn ($a) => ['kind' => 'appointment', 'at' => $a->starts_at, 'model' => $a]);
        $fits = Fitting::with('customer:id,public_id,first_name,last_name,phone', 'staff:id,name', 'branch:id,name', 'order:id,public_id,number', 'line:id,description')
            ->whereBetween('scheduled_at', $range)->where('status', '!=', 'rescheduled')
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $includeClosed, fn ($q) => $q->where('status', 'scheduled'))
            ->get()->map(fn ($f) => ['kind' => 'fitting', 'at' => $f->scheduled_at, 'model' => $f]);

        return $apps->concat($fits)->sortBy(fn ($e) => $e['at']->timestamp)->values()
            ->groupBy(fn ($e) => $e['at']->copy()->timezone($tz)->toDateString());
    }

    /* ------------------------------------------------------------------ */

    protected function afterBooking(Appointment $a, User $by): void
    {
        CustomerUpdates::safely(fn ($u) => $u->appointmentBooked($a->fresh()));
        if ($a->staff_id && $a->staff_id !== $by->id) {
            DB::afterCommit(fn () => $a->staff?->notify(new AppointmentBooked($a)));
        }
    }

    protected function checkClash(?int $staffId, CarbonImmutable $at, int $minutes, ?int $ignore, bool $allow): void
    {
        if (! $staffId || $allow) {
            return;
        }
        $end = $at->addMinutes($minutes);
        $clash = Appointment::where('staff_id', $staffId)->where('status', 'booked')->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->where('starts_at', '<', $end->utc())->get()->first(fn ($x) => $x->ends_at->gt($at));
        $fit = $clash ? null : Fitting::where('staff_id', $staffId)->where('status', 'scheduled')
            ->where('scheduled_at', '<', $end->utc())->get()->first(fn ($f) => $f->scheduled_at->copy()->addMinutes($f->duration_minutes)->gt($at));
        if ($clash || $fit) {
            $what = $clash ? ($clash->type_label . ' with ' . $clash->customer?->name) : ('a fitting with ' . $fit->customer?->name);
            $time = ($clash?->starts_at ?? $fit->scheduled_at)->copy()->timezone($this->tz())->format('g:i A');
            throw ValidationException::withMessages(['starts_at' => "They already have {$what} at {$time}. Choose another time or someone else, or tick “book anyway”."]);
        }
    }

    protected function when(string $value): CarbonImmutable
    {
        $at = CarbonImmutable::parse($value, $this->tz());
        if ($at->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a time in the future.']);
        }

        return $at;
    }

    protected function assertOpen(Appointment $a): void
    {
        if ($a->status !== 'booked') {
            throw ValidationException::withMessages(['status' => 'This appointment is already ' . mb_strtolower($a->status_label) . '.']);
        }
    }

    protected function orderFor(Customer $c, mixed $ref): ?Order
    {
        if (! $ref) {
            return null;
        }
        $o = Order::where(fn ($q) => $q->where('id', is_numeric($ref) ? (int) $ref : 0)->orWhere('public_id', $ref))->first();
        if (! $o || $o->customer_id !== $c->id) {
            throw ValidationException::withMessages(['order_id' => 'That order is not this customer’s.']);
        }

        return $o;
    }

    public function tz(): string
    {
        return CurrentTenant::get()?->timezone ?: config('app.timezone');
    }
}
