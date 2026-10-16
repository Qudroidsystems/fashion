<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Fitting;
use App\Models\Order;
use App\Models\ProductionTask;
use App\Models\User;
use App\Services\Production\FittingService;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Fitting appointments (SRS FR-PRD-005). */
class FittingController extends Controller
{
    public function __construct(protected FittingService $fittings)
    {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()->can('View production') || $request->user()->can('Manage fitting'), 403);

            return $next($request);
        })->only(['index', 'show']);
        $this->middleware('permission:Manage fitting', ['only' => ['create', 'store', 'reschedule', 'cancel', 'record']]);
    }

    public function index(Request $request): View
    {
        $tz = CurrentTenant::get()->timezone;
        $when = $request->input('when', 'upcoming');
        $q = Fitting::with('customer:id,public_id,first_name,last_name,phone', 'order:id,public_id,number,title', 'line:id,description', 'staff:id,name', 'branch:id,name')
            ->when($request->filled('staff'), fn ($w) => $w->where('staff_id', $request->staff));
        if ($when === 'past') {
            $q->where(fn ($w) => $w->where('scheduled_at', '<', now())->orWhereNotIn('status', ['scheduled']))->whereNotIn('status', ['rescheduled'])->latest('scheduled_at');
        } else {
            $q->where('status', 'scheduled')->where('scheduled_at', '>=', now($tz)->startOfDay()->utc())->orderBy('scheduled_at');
        }
        $list = $q->paginate(50)->withQueryString();

        return view('production.fittings.index', [
            'pagetitle' => 'Fittings',
            'fittings'  => $list,
            'days'      => $list->getCollection()->groupBy(fn ($f) => $f->scheduled_at->timezone($tz)->toDateString()),
            'overdue'   => Fitting::where('status', 'scheduled')->where('scheduled_at', '<', now()->subHour())->count(),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'when'      => $when,
            'tz'        => $tz,
        ]);
    }

    public function create(Request $request): View
    {
        $order = $request->filled('order') ? Order::where('public_id', $request->order)->with('customer', 'lines')->first() : null;
        $task = $request->filled('task') ? ProductionTask::where('public_id', $request->task)->first() : null;
        $order ??= $task?->order?->load('customer', 'lines');

        return view('production.fittings.form', [
            'pagetitle' => 'Book a fitting',
            'order'     => $order,
            'task'      => $task,
            'customer'  => $order?->customer ?? ($request->filled('customer') ? Customer::where('public_id', $request->customer)->first() : null),
            'customers' => $order ? collect() : Customer::active()->orderBy('first_name')->limit(500)->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number']),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'branches'  => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $fitting = $this->fittings->schedule($data, $request->user());

        return redirect()->route('fittings.show', $fitting)->with('success', 'Fitting booked. Reminders are set.');
    }

    public function show(Fitting $fitting): View
    {
        $fitting->load('customer', 'order', 'line', 'task', 'staff', 'branch', 'reminders', 'recorder', 'rescheduledTo');

        return view('production.fittings.show', [
            'pagetitle' => $fitting->number,
            'fitting'   => $fitting,
            'previous'  => Fitting::where('rescheduled_to_id', $fitting->id)->first(),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function reschedule(Request $request, Fitting $fitting): RedirectResponse
    {
        $data = $request->validate(['scheduled_at' => 'required|date', 'reason' => 'nullable|string|max:255']);
        $new = $this->fittings->reschedule($fitting, $data['scheduled_at'], $data['reason'] ?? null, $request->user());

        return redirect()->route('fittings.show', $new)->with('success', 'Fitting moved. Old reminders were cancelled and new ones set.');
    }

    public function cancel(Request $request, Fitting $fitting): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->fittings->cancel($fitting, $data['reason']);

        return back()->with('success', 'Fitting cancelled.');
    }

    public function record(Request $request, Fitting $fitting): RedirectResponse
    {
        $data = $request->validate([
            'status'  => 'required|in:attended,no_show',
            'outcome' => 'nullable|required_if:status,attended|in:approved,alterations',
            'notes'   => 'nullable|string|max:2000',
            'alterations' => 'nullable|array',
            'alterations.*.name' => 'nullable|string|max:120',
            'alterations.*.responsible_party' => ['nullable', Rule::in(array_keys(ProductionTask::RESPONSIBLE))],
            'alterations.*.cost_treatment' => ['nullable', Rule::in(array_keys(ProductionTask::COST_TREATMENT))],
            'alterations.*.cost' => 'nullable|numeric|min:0',
            'alterations.*.assigned_to' => 'nullable|integer',
        ]);
        $staffIds = User::inCurrentTenant()->pluck('users.id')->all();
        $alts = collect($data['alterations'] ?? [])->map(function ($a) use ($staffIds) {
            $a['assigned_to'] = in_array((int) ($a['assigned_to'] ?? 0), $staffIds, true) ? (int) $a['assigned_to'] : null;

            return $a;
        })->all();
        $this->fittings->record($fitting, $data['status'], $data['outcome'] ?? null, $data['notes'] ?? null, $alts, $request->user());

        return back()->with('success', match (true) {
            $data['status'] === 'no_show' => 'Recorded as a no-show. Book another time when the customer is ready.',
            ($data['outcome'] ?? null) === 'alterations' => 'Recorded. The alterations are on the production board.',
            default => 'Recorded — the garment moves on.',
        });
    }

    protected function validated(Request $request): array
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'order_id'         => ['nullable', Rule::exists('orders', 'id')->where('tenant_id', $tenant)],
            'customer_id'      => ['nullable', 'required_without:order_id', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'order_line_id'    => 'nullable|integer',
            'task_id'          => 'nullable|integer',
            'staff_id'         => 'nullable|integer',
            'branch_id'        => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'date'             => 'required|date',
            'time'             => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|min:10|max:480',
            'notes'            => 'nullable|string|max:1000',
        ]);
        if (! empty($data['staff_id']) && ! User::inCurrentTenant()->whereKey($data['staff_id'])->exists()) {
            $data['staff_id'] = null;
        }
        $data['scheduled_at'] = Carbon::parse($data['date'] . ' ' . $data['time'])->toDateTimeString();

        return $data;
    }
}
