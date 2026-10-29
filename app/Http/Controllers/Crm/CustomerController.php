<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\FollowUpTask;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Crm\CustomerService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Customer CRM screens (SRS §5.3). */
class CustomerController extends Controller
{
    public function __construct(protected CustomerService $service)
    {
        $this->middleware('permission:View customer', ['only' => ['index', 'show']]);
        $this->middleware('permission:Create customer', ['only' => ['create', 'store']]);
        $this->middleware('permission:Update customer', ['only' => ['edit', 'update', 'archive']]);
        $this->middleware('permission:Delete customer', ['only' => ['destroy']]);
        $this->middleware('permission:Merge customer', ['only' => ['mergeForm', 'merge']]);
        $this->middleware('permission:Export customer', ['only' => ['export']]);
    }

    /** List + segmentation (FR-CRM-005). */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q'         => 'nullable|string|max:100',
            'tag'       => 'nullable|integer',
            'vip'       => 'nullable|boolean',
            'branch'    => 'nullable|integer',
            'status'    => 'nullable|in:active,archived,merged',
            'follow_up' => 'nullable|in:open,overdue,none',
            'mine'      => 'nullable|boolean',
            'birthday'  => 'nullable|in:month',
            'sort'      => 'nullable|in:recent,name,number,activity',
        ]);

        $customers = $this->filtered($filters)
            ->with(['tags:id,name,color', 'branch:id,name', 'assignee:id,name'])
            ->withCount(['followUps as open_follow_ups' => fn ($q) => $q->where('status', 'open')])
            ->paginate(25)->withQueryString();

        $tz = CurrentTenant::get()->timezone;

        return view('customers.index', [
            'pagetitle' => 'Customers',
            'customers' => $customers,
            'filters'   => $filters,
            'tags'      => CustomerTag::orderBy('name')->get(),
            'branches'  => Branch::active()->orderBy('name')->get(['id', 'name']),
            'stats'     => [
                'total'     => Customer::active()->count(),
                'vip'       => Customer::active()->where('is_vip', true)->count(),
                'new'       => Customer::active()->where('created_at', '>=', now($tz)->startOfMonth()->utc())->count(),
                'followups' => FollowUpTask::open()->count(),
                'overdue'   => FollowUpTask::overdue()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('customers.form', $this->formData(new Customer(['is_vip' => false])));
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $data = $request->customerData();

        if (! $request->boolean('confirm_duplicate')) {
            $dupes = $this->service->findDuplicates($data);
            if ($dupes->isNotEmpty()) {
                return back()->withInput()->with('duplicates', $dupes->map(fn ($c) => [
                    'url' => route('customers.show', $c), 'name' => $c->name, 'number' => $c->customer_number,
                    'phone' => $c->phone, 'email' => $c->email, 'reasons' => $c->match_reasons,
                ])->all());
            }
        }

        $customer = DB::transaction(function () use ($request, $data) {
            $customer = Customer::create($data);
            $this->service->syncTags($customer, $request->tagNames());
            $this->service->syncConsents($customer, (array) $request->input('consents', []), 'staff');

            return $customer;
        });

        AuditLogger::log('customer.created', $customer, after: $customer->only(['customer_number', 'first_name', 'last_name', 'phone', 'email']));

        return redirect()->route('customers.show', $customer)->with('success', "{$customer->name} added as {$customer->customer_number}.");
    }

    public function show(Request $request, Customer $customer): View
    {
        $customer->load(['tags', 'branch', 'assignee', 'creator', 'consents', 'mergedInto']);
        $user = $request->user();

        return view('customers.show', [
            'pagetitle' => $customer->name,
            'customer'  => $customer,
            'timeline'  => $this->service->timeline($customer, $user),
            'notes'     => CustomerNote::where('customer_id', $customer->id)->visibleTo($user)->with('author:id,name')
                ->orderByDesc('is_pinned')->latest()->get(),
            'hiddenNotes' => $user->can('View customer sensitive notes') ? 0
                : CustomerNote::where('customer_id', $customer->id)->where('is_sensitive', true)->count(),
            'followUps' => FollowUpTask::where('customer_id', $customer->id)->with('assignee:id,name')
                ->orderByRaw("case when status = 'open' then 0 else 1 end")->orderBy('due_at')->get(),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'channels'  => CustomerConsent::CHANNELS,
            'purposes'  => CustomerConsent::PURPOSES,
            'tz'        => CurrentTenant::get()->timezone,
            'cMaterials' => $user->can('View customer materials')
                ? \App\Models\CustomerMaterial::where('customer_id', $customer->id)->with('order:id,public_id,number')->latest('received_at')->limit(50)->get() : collect(),
            'cMaterialChannels' => $user->can('Manage customer materials') ? app(\App\Services\Messaging\Messenger::class)->available() : [],
        ] + $this->measurementData($customer, $user) + $this->orderData($customer, $user));
    }

    /** Orders tab: orders, open quotations and money summary (SRS §5.8). */
    protected function orderData(Customer $customer, $user): array
    {
        if (! $user->can('View order')) {
            return ['oOrders' => collect(), 'oQuotes' => collect(), 'oMoney' => null];
        }

        return [
            'oOrders' => \App\Models\Order::where('customer_id', $customer->id)->withCount('lines')->latest('id')->limit(30)->get(),
            'oQuotes' => $user->can('Manage quotation') ? \App\Models\Quotation::where('customer_id', $customer->id)->whereIn('status', ['draft', 'sent'])->latest('id')->get() : collect(),
            'oMoney'  => $user->can('View payment') ? app(\App\Services\Orders\BillingService::class)->customerSummary($customer) : null,
        ];
    }

    /** Measurements tab: current approved set per template, history, open links (SRS §5.4/§5.5). */
    protected function measurementData(Customer $customer, $user): array
    {
        if (! $user->can('View measurement')) {
            return ['mSessions' => collect(), 'mCurrent' => collect(), 'mRequests' => collect(), 'mTemplates' => collect()];
        }

        $sessions = \App\Models\MeasurementSession::where('customer_id', $customer->id)
            ->with(['templateVersion.template', 'measurer:id,name', 'values.templateParameter'])
            ->latest('measured_at')->get();

        return [
            'mSessions'  => $sessions,
            'mCurrent'   => $sessions->where('status', 'approved')
                ->groupBy(fn ($s) => $s->templateVersion->template_id)
                ->map(fn ($g) => $g->sortByDesc('verified_at')->first())->values(),
            'mRequests'  => $user->can('Send measurement request')
                ? \App\Models\MeasurementRequest::where('customer_id', $customer->id)->with('templateVersion.template', 'creator:id,name', 'session')->latest()->limit(10)->get()
                : collect(),
            'mTemplates' => \App\Models\MeasurementTemplate::usable()->with('currentVersion')->orderBy('name')->get(),
        ];
    }

    public function edit(Customer $customer): View
    {
        abort_if($customer->status === 'merged', 403, 'This record was merged into another customer.');

        return view('customers.form', $this->formData($customer->load(['tags', 'consents'])));
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        abort_if($customer->status === 'merged', 403, 'This record was merged into another customer.');

        $data = $request->customerData();
        $before = $customer->only(array_keys($data));

        DB::transaction(function () use ($request, $customer, $data) {
            $customer->update($data);
            $this->service->syncTags($customer, $request->tagNames());
            $this->service->syncConsents($customer, (array) $request->input('consents', []), 'staff');
        });

        [$b, $a] = AuditLogger::diff(
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $before),
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $customer->only(array_keys($data)))
        );
        if ($a) {
            AuditLogger::log('customer.updated', $customer, $b, $a);
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    /** Archive / restore (keeps all history). */
    public function archive(Customer $customer): RedirectResponse
    {
        abort_if($customer->status === 'merged', 403);

        $customer->update(['status' => $customer->status === 'archived' ? 'active' : 'archived']);
        AuditLogger::log('customer.' . ($customer->status === 'archived' ? 'archived' : 'restored'), $customer);

        return back()->with('success', $customer->name . ($customer->status === 'archived' ? ' archived.' : ' restored.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        AuditLogger::log('customer.deleted', $customer, before: $customer->only(['customer_number', 'first_name', 'last_name']));
        $customer->delete();   // soft delete

        return redirect()->route('customers.index')->with('success', 'Customer deleted.');
    }

    public function mergeForm(Customer $customer, Request $request): View
    {
        $candidates = $request->filled('q')
            ? Customer::active()->where('id', '!=', $customer->id)->search($request->q)->limit(10)->get()
            : $this->service->findDuplicates($customer->toArray(), $customer->id);

        return view('customers.merge', [
            'pagetitle'  => 'Merge customer',
            'customer'   => $customer,
            'candidates' => $candidates,
        ]);
    }

    /** Merge the duplicate {customer} into the chosen record (FR-CRM-002). */
    public function merge(Request $request, Customer $customer): RedirectResponse
    {
        $request->validate(['target' => 'required|string']);
        abort_if($customer->status === 'merged', 422, 'Already merged.');

        $target = Customer::active()->where('public_id', $request->target)->firstOrFail();
        $merged = $this->service->merge($customer, $target);

        return redirect()->route('customers.show', $merged)->with('success', "Merged {$customer->customer_number} into {$merged->customer_number}.");
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['q', 'tag', 'vip', 'branch', 'status', 'follow_up', 'mine', 'birthday']);
        $query = $this->filtered($filters)->with('tags:id,name', 'branch:id,name');
        $canSeeContacts = true; // contact details are part of "Export customer"

        AuditLogger::log('customer.exported', null, description: 'Exported customer list');

        return response()->streamDownload(function () use ($query, $canSeeContacts) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer no.', 'Name', 'Phone', 'WhatsApp', 'E-mail', 'Gender', 'Birthday', 'City', 'Branch', 'VIP', 'Tags', 'Source', 'Status', 'Added']);
            $query->chunk(500, function ($rows) use ($out, $canSeeContacts) {
                foreach ($rows as $c) {
                    fputcsv($out, [
                        $c->customer_number, $c->full_name,
                        $canSeeContacts ? $c->phone : '', $canSeeContacts ? $c->whatsapp : '', $canSeeContacts ? $c->email : '',
                        $c->gender, $c->birthday?->format('d M'), $c->city, $c->branch?->name, $c->is_vip ? 'Yes' : 'No',
                        $c->tags->pluck('name')->implode(', '), Customer::SOURCES[$c->source] ?? $c->source,
                        $c->status, $c->created_at->format('Y-m-d'),
                    ]);
                }
            });
            fclose($out);
        }, 'customers-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------------ */

    protected function filtered(array $f)
    {
        $tz = CurrentTenant::get()->timezone;

        return Customer::query()
            ->where('status', $f['status'] ?? 'active')
            ->search($f['q'] ?? null)
            ->when($f['tag'] ?? null, fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('customer_tags.id', $v)))
            ->when(isset($f['vip']) && $f['vip'] !== '' && $f['vip'] !== null, fn ($q) => $q->where('is_vip', (bool) $f['vip']))
            ->when($f['branch'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when(! empty($f['mine']), fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when(($f['follow_up'] ?? null) === 'open', fn ($q) => $q->whereHas('followUps', fn ($t) => $t->open()))
            ->when(($f['follow_up'] ?? null) === 'overdue', fn ($q) => $q->whereHas('followUps', fn ($t) => $t->overdue()))
            ->when(($f['follow_up'] ?? null) === 'none', fn ($q) => $q->whereDoesntHave('followUps', fn ($t) => $t->open()))
            ->when(($f['birthday'] ?? null) === 'month', fn ($q) => $q->whereNotNull('birthday')
                ->whereMonth('birthday', now($tz)->month))
            ->when(true, function ($q) use ($f) {
                match ($f['sort'] ?? 'recent') {
                    'name'     => $q->orderBy('first_name')->orderBy('last_name'),
                    'number'   => $q->orderBy('customer_number'),
                    'activity' => $q->orderByDesc('last_activity_at')->orderByDesc('id'),
                    default    => $q->latest('id'),
                };
            });
    }

    protected function formData(Customer $customer): array
    {
        return [
            'pagetitle' => $customer->exists ? 'Edit ' . $customer->name : 'New customer',
            'customer'  => $customer,
            'branches'  => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'allTags'   => CustomerTag::orderBy('name')->pluck('name'),
            'channels'  => CustomerConsent::CHANNELS,
            'purposes'  => CustomerConsent::PURPOSES,
        ];
    }
}
