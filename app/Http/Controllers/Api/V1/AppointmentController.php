<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Appointments\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Appointments for the staff app.
 *   GET  /appointments?from=&days=&staff=   — the diary (appointments and fittings) by day
 *   GET  /appointments/meta                 — types
 *   POST /appointments                       {customer, type, starts_at, duration_minutes?, staff_id?, order?, notes?, allow_clash?}
 *   GET  /appointments/{id}
 *   POST /appointments/{id}/reschedule       {starts_at, duration_minutes?, allow_clash?}
 *   POST /appointments/{id}/status           {status: done|no_show|cancelled, note?}
 */
class AppointmentController extends Controller
{
    public function __construct(protected AppointmentService $appointments)
    {
        $this->middleware('permission:Manage appointment');
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => 'nullable|date', 'days' => 'nullable|integer|min:1|max:31', 'staff' => 'nullable|integer']);
        $tz = $this->appointments->tz();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'), $tz) : CarbonImmutable::now($tz);
        $days = (int) $request->query('days', 7);
        $diary = $this->appointments->diary($from, $from->addDays($days - 1), $request->integer('staff') ?: null);

        return response()->json(['success' => true, 'data' => $diary->map(fn ($entries, $day) => [
            'date' => $day,
            'entries' => $entries->map(fn ($e) => $this->entry($e['kind'], $e['model']))->values(),
        ])->values()]);
    }

    public function meta(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'types' => collect(Appointment::TYPES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $t = \App\Support\Tenancy\CurrentTenant::id();
        $data = $request->validate([
            'customer' => 'required|string', 'type' => ['required', Rule::in(array_keys(Appointment::TYPES))],
            'starts_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5|max:480',
            'staff_id' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', $t)],
            'order' => 'nullable|string', 'notes' => 'nullable|string|max:1000', 'allow_clash' => 'nullable|boolean',
        ]);
        $customer = Customer::where('public_id', $data['customer'])->firstOrFail();
        $a = $this->appointments->book(['customer_id' => $customer->id, 'order_id' => $data['order'] ?? null] + $data, $request->user());

        return response()->json(['success' => true, 'data' => $this->entry('appointment', $a->fresh(['customer', 'staff', 'branch', 'order']))], 201);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $appointment->load('customer', 'staff', 'branch', 'order', 'creator');
        $tz = $this->appointments->tz();

        return response()->json(['success' => true, 'data' => $this->entry('appointment', $appointment) + [
            'notes' => $appointment->notes, 'outcome' => $appointment->outcome, 'cancel_reason' => $appointment->cancel_reason,
            'booked_by' => $appointment->creator?->name,
            'messages' => \App\Models\OutgoingMessage::where('related_type', $appointment->getMorphClass())->where('related_id', $appointment->id)->latest('id')->get()
                ->map(fn ($m) => ['label' => \App\Services\Messaging\CustomerUpdates::EVENTS[$m->purpose][0] ?? 'Message', 'status' => $m->status, 'result' => $m->result,
                    'at' => ($m->status === 'queued' && $m->send_after ? $m->send_after : $m->created_at)->copy()->timezone($tz)->toIso8601String()])->values(),
        ]]);
    }

    public function reschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['starts_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5|max:480', 'allow_clash' => 'nullable|boolean']);
        $a = $this->appointments->reschedule($appointment, $data['starts_at'], $data['duration_minutes'] ?? null, $request->user(), (bool) ($data['allow_clash'] ?? false));

        return response()->json(['success' => true, 'data' => $this->entry('appointment', $a->fresh(['customer', 'staff', 'branch', 'order']))]);
    }

    public function status(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:done,no_show,cancelled', 'note' => 'nullable|string|max:1000']);
        $a = $this->appointments->setStatus($appointment, $data['status'], $data['note'] ?? null, $request->user());

        return response()->json(['success' => true, 'data' => $this->entry('appointment', $a->fresh(['customer', 'staff', 'branch', 'order']))]);
    }

    protected function entry(string $kind, $m): array
    {
        $tz = $this->appointments->tz();
        $at = $kind === 'fitting' ? $m->scheduled_at : $m->starts_at;

        return [
            'kind' => $kind, 'id' => $m->public_id, 'number' => $m->number,
            'type' => $kind === 'fitting' ? 'fitting' : $m->type, 'type_label' => $kind === 'fitting' ? 'Fitting' : $m->type_label,
            'title' => $kind === 'fitting' ? ($m->line?->description ?? 'Fitting') : $m->type_label,
            'starts_at' => $at->copy()->timezone($tz)->toIso8601String(), 'duration_minutes' => $m->duration_minutes,
            'status' => $m->status, 'status_label' => $m->status_label,
            'needs_outcome' => $at->isPast() && in_array($m->status, ['booked', 'scheduled'], true),
            'customer' => $m->customer ? ['id' => $m->customer->public_id, 'name' => $m->customer->name, 'phone' => $m->customer->phone] : null,
            'staff' => $m->staff?->name, 'staff_id' => $m->staff_id, 'branch' => $m->branch?->name,
            'order' => $m->order ? ['id' => $m->order->public_id, 'number' => $m->order->number] : null,
        ];
    }
}
