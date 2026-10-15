<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FollowUpTask;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Customer follow-up queue (FR-CRM-001 "follow-ups", FR-REP-001 "follow-up queues"). */
class FollowUpController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View customer', ['only' => ['index']]);
        $this->middleware('permission:Update customer', ['except' => ['index']]);
    }

    public function index(Request $request): View
    {
        $filter = $request->input('show', 'mine');
        $tz = CurrentTenant::get()->timezone;

        $tasks = FollowUpTask::with(['customer:id,public_id,first_name,last_name,customer_number,phone', 'assignee:id,name'])
            ->whereHas('customer')
            ->when($filter === 'mine', fn ($q) => $q->open()->where('assigned_to', auth()->id()))
            ->when($filter === 'open', fn ($q) => $q->open())
            ->when($filter === 'overdue', fn ($q) => $q->overdue())
            ->when($filter === 'today', fn ($q) => $q->open()->whereBetween('due_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()]))
            ->when($filter === 'done', fn ($q) => $q->where('status', 'done')->latest('completed_at'))
            ->orderByRaw('due_at is null')->orderBy('due_at')
            ->paginate(30)->withQueryString();

        return view('customers.follow-ups', [
            'pagetitle' => 'Follow-ups',
            'tasks'     => $tasks,
            'filter'    => $filter,
            'tz'        => $tz,
            'counts'    => [
                'mine'    => FollowUpTask::open()->where('assigned_to', auth()->id())->count(),
                'open'    => FollowUpTask::open()->count(),
                'overdue' => FollowUpTask::overdue()->count(),
            ],
        ]);
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);

        FollowUpTask::create($data + [
            'customer_id' => $customer->id,
            'status'      => 'open',
            'created_by'  => $request->user()->id,
        ]);
        $customer->touchActivity();

        return redirect()->to(route('customers.show', $customer) . '#follow-ups')->with('success', 'Follow-up added.');
    }

    public function update(Request $request, FollowUpTask $followUp): RedirectResponse
    {
        $request->validate(['status' => ['required', Rule::in(['open', 'done', 'cancelled'])]]);

        $followUp->update([
            'status'       => $request->status,
            'completed_at' => $request->status === 'done' ? now() : null,
            'completed_by' => $request->status === 'done' ? $request->user()->id : null,
        ]);
        $followUp->customer?->touchActivity();

        return back()->with('success', 'Follow-up ' . ($request->status === 'open' ? 'reopened' : $request->status) . '.');
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title'       => 'required|string|max:160',
            'details'     => 'nullable|string|max:2000',
            'due_at'      => 'nullable|date',
            'assigned_to' => ['nullable', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', CurrentTenant::id())],
        ]);

        // Dates are typed in the house's timezone; store UTC.
        if (! empty($data['due_at'])) {
            $data['due_at'] = Carbon::parse($data['due_at'], CurrentTenant::get()->timezone)->utc();
        }
        $data['assigned_to'] ??= $request->user()->id;

        return $data;
    }
}
