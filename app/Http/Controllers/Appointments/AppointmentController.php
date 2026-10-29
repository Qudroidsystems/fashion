<?php

namespace App\Http\Controllers\Appointments;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Appointments\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The diary: appointments and fittings by day; booking, moving and recording appointments. */
class AppointmentController extends Controller
{
    public function __construct(protected AppointmentService $appointments)
    {
        $this->middleware('permission:Manage appointment');
    }

    public function index(Request $request): View
    {
        $request->validate(['from' => 'nullable|date', 'staff' => 'nullable|integer', 'days' => 'nullable|integer|in:1,7,14,30']);
        $tz = $this->appointments->tz();
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from'), $tz) : CarbonImmutable::now($tz);
        $days = (int) $request->query('days', 7);
        $to = $from->addDays($days - 1);

        return view('appointments.index', [
            'diary' => $this->appointments->diary($from, $to, $request->integer('staff') ?: null),
            'from' => $from, 'to' => $to, 'days' => $days, 'tz' => $tz,
            'staff' => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'today' => CarbonImmutable::now($tz),
        ]);
    }

    public function create(Request $request): View
    {
        $customer = $request->filled('customer') ? Customer::where('public_id', $request->query('customer'))->first() : null;
        $order = $request->filled('order') ? Order::where('public_id', $request->query('order'))->first() : null;
        $customer ??= $order?->customer;

        return view('appointments.form', [
            'a' => new Appointment(['type' => $request->query('type', 'consultation'), 'duration_minutes' => 30, 'customer_id' => $customer?->id, 'order_id' => $order?->id,
                'staff_id' => $request->user()->id]),
            'customer' => $customer,
            'customers' => $customer ? collect() : Customer::active()->latest('id')->limit(500)->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number', 'phone']),
            'orders' => $customer ? Order::where('customer_id', $customer->id)->whereNotIn('status', ['cancelled', 'closed'])->latest('id')->get(['id', 'number', 'title']) : collect(),
            'staff' => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'branches' => Branch::active()->orderBy('name')->get(['id', 'name']),
            'tz' => $this->appointments->tz(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules() + ['customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', \App\Support\Tenancy\CurrentTenant::id())]]);
        $a = $this->appointments->book($data, $request->user());

        return redirect()->route('appointments.show', $a)->with('success', "Booked. {$a->customer->first_name} will get a confirmation and a reminder the day before.");
    }

    public function show(Appointment $appointment): View
    {
        $appointment->load('customer', 'order', 'branch', 'staff', 'creator');

        return view('appointments.show', [
            'a' => $appointment, 'tz' => $this->appointments->tz(),
            'messages' => \App\Models\OutgoingMessage::where('related_type', $appointment->getMorphClass())->where('related_id', $appointment->id)->latest('id')->get(),
            'others' => Appointment::where('customer_id', $appointment->customer_id)->whereKeyNot($appointment->id)->latest('starts_at')->limit(5)->get(),
        ]);
    }

    public function reschedule(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate(['starts_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5|max:480', 'allow_clash' => 'nullable|boolean']);
        $this->appointments->reschedule($appointment, $data['starts_at'], $data['duration_minutes'] ?? null, $request->user(), (bool) ($data['allow_clash'] ?? false));

        return back()->with('success', 'Moved. The customer gets the new time.');
    }

    public function status(Request $request, Appointment $appointment): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:done,no_show,cancelled', 'note' => 'nullable|string|max:1000']);
        $this->appointments->setStatus($appointment, $data['status'], $data['note'] ?? null, $request->user());

        return back()->with('success', 'Saved.');
    }

    protected function rules(): array
    {
        $t = \App\Support\Tenancy\CurrentTenant::id();

        return [
            'type' => ['required', Rule::in(array_keys(Appointment::TYPES))],
            'starts_at' => 'required|date',
            'duration_minutes' => 'required|integer|min:5|max:480',
            'staff_id' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', $t)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $t)],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('tenant_id', $t)],
            'notes' => 'nullable|string|max:1000',
            'allow_clash' => 'nullable|boolean',
        ];
    }
}
