<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MaterialIssue;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\StockDocumentService;
use App\Support\Inventory\ItemPicker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Material handed to production and returned from it (SRS FR-INV-006, scenario 38). */
class MaterialIssueController extends Controller
{
    public function __construct(protected StockDocumentService $docs)
    {
        $this->middleware('permission:View inventory', ['only' => ['index', 'show']]);
        $this->middleware('permission:Issue material', ['only' => ['create', 'store', 'returnStock']]);
    }

    public function index(Request $request): View
    {
        return view('inventory.issues.index', [
            'pagetitle' => 'Material issues',
            'issues'    => MaterialIssue::with('branch:id,name', 'customer:id,public_id,first_name,last_name', 'issuedTo:id,name')->withCount('lines')
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                    ->orWhere('job_reference', 'like', "%{$request->q}%")
                    ->orWhereHas('customer', fn ($c) => $c->search($request->q))))
                ->latest('issued_at')->latest('id')->paginate(30)->withQueryString(),
            'tz'        => CurrentTenant::get()->timezone,
            'canCost'   => $request->user()->can('View costing'),
        ]);
    }

    public function create(Request $request): View
    {
        $order = $request->filled('order') ? \App\Models\Order::where('public_id', $request->order)->with('customer')->first() : null;
        $customer = $order?->customer ?? ($request->filled('customer') ? Customer::where('public_id', $request->customer)->first() : null);
        $branchId = CurrentTenant::membership()?->branch_id ?? Branch::where('is_main', true)->value('id');
        // Pre-fill with what is reserved: for the order if given, else for the customer.
        $reserved = StockReservation::where('status', 'active')
            ->when($order, fn ($q) => $q->where('order_id', $order->id), fn ($q) => $q->where('customer_id', $customer?->id ?? 0))->get();
        if ($reserved->isNotEmpty()) {
            $branchId = $reserved->first()->branch_id;
        }
        $rows = $reserved->where('branch_id', $branchId)->map(fn ($r) => ['item_id' => $r->item_id, 'quantity' => $r->open_quantity])->values()->all();

        return view('inventory.issues.form', [
            'pagetitle'   => 'Issue material',
            'customer'    => $customer,
            'order'       => $order,
            'rows'        => $rows,
            'branchId'    => $branchId,
            'branches'    => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'customers'   => Customer::active()->orderBy('first_name')->limit(500)->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number']),
            'staff'       => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'pickerItems' => ItemPicker::data(onlyCustomerId: $customer?->id ?? -1),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'branch_id'        => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'customer_id'      => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'order_id'         => ['nullable', Rule::exists('orders', 'id')->where('tenant_id', $tenant)],
            'job_reference'    => 'required|string|max:120',
            'issued_to'        => ['nullable', 'integer'],
            'notes'            => 'nullable|string|max:2000',
            'allow_reserved'   => 'nullable|boolean',
            'lines'            => 'required|array',
            'lines.*.item_id'  => 'nullable|integer',
            'lines.*.lot_id'   => 'nullable|integer',
            'lines.*.quantity' => 'nullable|numeric|min:0',
        ]);
        if (! empty($data['issued_to']) && ! User::inCurrentTenant()->whereKey($data['issued_to'])->exists()) {
            $data['issued_to'] = null;
        }
        if (! empty($data['order_id'])) {
            $data['customer_id'] = \App\Models\Order::whereKey($data['order_id'])->value('customer_id');
        }
        $issue = $this->docs->issue($data, $data['lines']);

        return redirect()->route('material-issues.show', $issue)->with('success', 'Material issued to production.');
    }

    public function show(MaterialIssue $materialIssue): View
    {
        $materialIssue->load('branch', 'customer', 'lines.item', 'lines.lot', 'issuedTo', 'issuer');

        return view('inventory.issues.show', [
            'pagetitle' => $materialIssue->number,
            'issue'     => $materialIssue,
            'tz'        => CurrentTenant::get()->timezone,
            'canCost'   => request()->user()->can('View costing'),
        ]);
    }

    public function returnStock(Request $request, MaterialIssue $materialIssue): RedirectResponse
    {
        $data = $request->validate([
            'returns'    => 'required|array',
            'returns.*'  => 'nullable|numeric|min:0',
            'as_remnant' => 'nullable|boolean',
            'note'       => 'nullable|string|max:255',
        ]);
        $this->docs->returnFromProduction($materialIssue, $data['returns'], $request->boolean('as_remnant'), $data['note'] ?? null);

        return back()->with('success', $request->boolean('as_remnant') ? 'Remnants added back to stock.' : 'Material returned to stock.');
    }
}
