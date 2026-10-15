<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\MaterialIssue;
use App\Models\MeasurementSession;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderMaterialRequirement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Support\Orders\Catalog;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Orders (SRS FR-ORD-001…003, FR-ORD-011, §6.2). */
class OrderController extends Controller
{
    public function __construct(protected OrderService $orders, protected BillingService $billing)
    {
        $this->middleware('permission:View order', ['only' => ['index', 'show']]);
        $this->middleware('permission:Create order', ['only' => ['create', 'store']]);
        $this->middleware('permission:Update order', ['only' => ['edit', 'update', 'transition', 'updateLine', 'addMaterial', 'removeMaterial']]);
        $this->middleware('permission:Cancel order', ['only' => ['cancel', 'reopen']]);
    }

    public function index(Request $request): View
    {
        $view = $request->input('view', 'open');
        $orders = Order::with('customer:id,public_id,first_name,last_name,customer_number', 'owner:id,name')->withCount('lines')
            ->when($view === 'open', fn ($q) => $q->whereIn('status', ['draft', ...OrderStatus::OPEN]))
            ->when($view === 'late', fn ($q) => $q->whereIn('status', OrderStatus::OPEN)->whereDate('promised_date', '<', now()->toDateString()))
            ->when($view === 'due', fn ($q) => $q->whereIn('status', OrderStatus::OPEN)->whereBetween('promised_date', [now()->toDateString(), now()->addDays(7)->toDateString()]))
            ->when(array_key_exists($view, OrderStatus::STATUSES), fn ($q) => $q->where('status', $view))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                ->orWhere('title', 'like', "%{$request->q}%")->orWhereHas('customer', fn ($c) => $c->search($request->q))))
            ->when($request->boolean('mine'), fn ($q) => $q->where('owner_id', $request->user()->id))
            ->orderByRaw('case when promised_date is null then 1 else 0 end')->orderBy('promised_date')->latest('id')
            ->paginate(30)->withQueryString();

        $open = Order::whereIn('status', OrderStatus::OPEN);

        return view('orders.index', [
            'pagetitle' => 'Orders',
            'orders'    => $orders,
            'view'      => $view,
            'counts'    => Order::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'stats'     => [
                'open' => (clone $open)->count(),
                'late' => (clone $open)->whereDate('promised_date', '<', now()->toDateString())->count(),
                'due'  => (clone $open)->whereBetween('promised_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
                'value'=> (clone $open)->sum('total'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $customer = $request->filled('customer') ? Customer::where('public_id', $request->customer)->first() : null;
        $order = new Order(['customer_id' => $customer?->id, 'priority' => 'normal', 'owner_id' => $request->user()->id]);
        $rows = [];
        if ($request->filled('style')) {
            $style = \App\Models\FashionStyle::where('public_id', $request->style)->first();
            $rows = $style ? [['kind' => 'bespoke', 'style_id' => $style->id]] : [];
        }

        return view('orders.form', $this->formData($order, $rows));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $order = $this->orders->saveOrder(null, $data, $data['lines']);
        if ($request->input('action') === 'confirm') {
            $this->orders->transition($order, 'confirmed');
        }

        return redirect()->route('orders.show', $order)->with('success', $request->input('action') === 'confirm' ? 'Order confirmed.' : 'Draft order saved.');
    }

    public function show(Order $order): View
    {
        $order->load('customer', 'branch', 'owner', 'quotation', 'lines.styleVersion.style', 'lines.measurementSession.templateVersion.template',
            'lines.assignee', 'materials.item', 'materials.reservation', 'materials.line', 'events.user', 'revisions.user', 'creator');

        $tenantTz = CurrentTenant::get()->timezone;

        return view('orders.show', [
            'pagetitle' => $order->number,
            'order'     => $order,
            'paid'      => $order->paid(),
            'balance'   => $order->balance(),
            'unbilled'  => $this->billing->unbilled($order),
            'invoices'  => Invoice::where('order_id', $order->id)->latest('id')->get(),
            'payments'  => PaymentAllocation::where(fn ($q) => $q->where('order_id', $order->id)->orWhereIn('invoice_id', Invoice::where('order_id', $order->id)->select('id')))
                ->with('payment', 'invoice:id,public_id,number')->latest('id')->get(),
            'credit'    => Payment::where('customer_id', $order->customer_id)->where('status', 'confirmed')->get()->filter(fn ($p) => $p->unallocated() > 0),
            'issues'    => MaterialIssue::where('order_id', $order->id)->with('lines.item')->latest('issued_at')->get(),
            'sessions'  => MeasurementSession::where('customer_id', $order->customer_id)->where('status', 'approved')->with('templateVersion.template')->latest('verified_at')->get(),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'items'     => InventoryItem::active()->where(fn ($q) => $q->where('type', '!=', 'customer_material')->orWhere('customer_id', $order->customer_id))->orderBy('name')->get(['id', 'name', 'code', 'unit', 'colour']),
            'branches'  => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'next'      => OrderStatus::TRANSITIONS[$order->status] ?? [],
            'tz'        => $tenantTz,
        ]);
    }

    public function edit(Order $order): View
    {
        abort_unless($order->isEditable(), 403, 'This order can no longer be changed.');
        $order->load('lines');

        return view('orders.form', $this->formData($order, $order->lines->where('status', '!=', 'cancelled')->map(fn ($l) => $l->copyable() + ['id' => $l->id])->values()->all()));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $this->validated($request, $order);
        $order = $this->orders->saveOrder($order, $data, $data['lines'], $data['revision_reason'] ?? null);
        if ($request->input('action') === 'confirm' && $order->status === 'draft') {
            $this->orders->transition($order, 'confirmed');
        }

        return redirect()->route('orders.show', $order)->with('success', 'Order saved.');
    }

    public function transition(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(array_keys(OrderStatus::STATUSES))],
            'note' => 'nullable|string|max:500',
            'customer_visible' => 'nullable|boolean',
        ]);
        $this->orders->transition($order, $data['to'], $data['note'] ?? null, $request->has('customer_visible') ? $request->boolean('customer_visible') : null);

        return back()->with('success', 'Order is now ' . strtolower(OrderStatus::label($data['to'])) . '.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->orders->cancel($order, $data['reason']);
        $paid = $order->paid();

        return back()->with('success', 'Order cancelled. Reserved materials were released.' . ($paid > 0 ? ' ' . \App\Support\Money::format($paid) . ' paid is now the customer’s credit — refund it or use it on another order.' : ''));
    }

    public function reopen(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->orders->reopen($order, $data['reason']);

        return back()->with('success', 'Order reopened.');
    }

    public function updateLine(Request $request, OrderLine $line): RedirectResponse
    {
        $data = $request->validate([
            'measurement_session_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'wearer' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(['pending', 'in_production', 'ready'])],
        ]);
        if (! empty($data['assigned_to']) && ! User::inCurrentTenant()->whereKey($data['assigned_to'])->exists()) {
            $data['assigned_to'] = null;
        }
        $data = array_filter($data, fn ($v, $k) => $request->has($k), ARRAY_FILTER_USE_BOTH);
        $this->orders->updateLine($line, $data);

        return back()->with('success', 'Garment updated.');
    }

    public function addMaterial(Request $request, Order $order): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'item_id'       => ['required', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenant)],
            'quantity'      => 'required|numeric|min:0.001',
            'branch_id'     => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'order_line_id' => ['nullable', Rule::exists('order_lines', 'id')->where('order_id', $order->id)],
            'note'          => 'nullable|string|max:255',
            'allow_short'   => 'nullable|boolean',
        ]);
        abort_unless($request->user()->can('Manage inventory') || $request->user()->can('Issue material') || $request->user()->can('Update order'), 403);
        $item = InventoryItem::findOrFail($data['item_id']);
        $this->orders->addMaterial($order, $item, (float) $data['quantity'], (int) $data['branch_id'], $data['order_line_id'] ?? null, $data['note'] ?? null, $request->boolean('allow_short'));

        return back()->with('success', 'Reserved ' . $item->qty($data['quantity']) . ' of ' . $item->name . '.');
    }

    public function removeMaterial(OrderMaterialRequirement $requirement): RedirectResponse
    {
        $this->orders->removeMaterial($requirement);

        return back()->with('success', 'Material removed and its reservation released.');
    }

    /* ------------------------------------------------------------------ */

    protected function formData(Order $order, array $rows): array
    {
        return [
            'pagetitle' => $order->exists ? 'Edit ' . $order->number : 'New order',
            'order'     => $order,
            'rows'      => $rows,
            'catalog'   => Catalog::data(),
            'customers' => Customer::active()->orderBy('first_name')->limit(1000)->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number', 'phone']),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'branches'  => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'depositPercent' => (float) CurrentTenant::get()->setting('deposit_percent', 50),
        ];
    }

    protected function validated(Request $request, ?Order $order = null): array
    {
        $tenant = CurrentTenant::id();

        return $request->validate([
            'customer_id'     => [$order ? 'nullable' : 'required', Rule::exists('customers', 'id')->where('tenant_id', $tenant)->where('status', 'active')],
            'branch_id'       => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'owner_id'        => ['nullable', 'integer'],
            'title'           => 'nullable|string|max:150',
            'event_date'      => 'nullable|date',
            'promised_date'   => 'nullable|date',
            'priority'        => 'nullable|in:normal,rush',
            'order_discount'  => 'nullable|numeric|min:0',
            'deposit_required'=> 'nullable|numeric|min:0',
            'customer_notes'  => 'nullable|string|max:2000',
            'internal_notes'  => 'nullable|string|max:2000',
            'revision_reason' => 'nullable|string|max:255',
            'lines'           => 'required|array',
            'lines.*.id'      => 'nullable|integer',
            'lines.*.kind'    => 'required|in:bespoke,ready_made,service,charge',
            'lines.*.style_id'=> 'nullable|integer',
            'lines.*.product_variant_id' => 'nullable|integer',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.instructions' => 'nullable|string|max:2000',
            'lines.*.options' => 'nullable|array',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
            'action'          => 'nullable|in:save,confirm',
        ]);
    }
}
