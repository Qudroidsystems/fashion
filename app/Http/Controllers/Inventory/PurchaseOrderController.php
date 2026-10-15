<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Inventory\PurchasingService;
use App\Support\Inventory\ItemPicker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Purchase orders and receiving against them (SRS FR-INV-005). */
class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchasingService $purchasing)
    {
        $this->middleware('permission:View inventory', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage purchase order', ['only' => ['create', 'store', 'edit', 'update', 'submit', 'reopen', 'cancel']]);
        $this->middleware('permission:Approve purchase order', ['only' => ['approve']]);
        $this->middleware('permission:Manage inventory', ['only' => ['receiveForm', 'receive']]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'open');
        $orders = PurchaseOrder::with('supplier:id,name', 'branch:id,name', 'creator:id,name')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_received']))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$request->q}%"))))
            ->latest('order_date')->latest('id')->paginate(30)->withQueryString();

        return view('inventory.purchase-orders.index', [
            'pagetitle' => 'Purchase orders',
            'orders'    => $orders,
            'status'    => $status,
            'counts'    => PurchaseOrder::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function create(Request $request): View
    {
        $po = new PurchaseOrder([
            'supplier_id' => Supplier::where('public_id', $request->supplier)->value('id'),
            'branch_id'   => CurrentTenant::membership()?->branch_id ?? Branch::where('is_main', true)->value('id'),
            'order_date'  => now(CurrentTenant::get()->timezone),
        ]);
        $rows = [];
        if ($request->filled('item')) {
            $item = \App\Models\InventoryItem::where('public_id', $request->item)->first();
            if ($item) {
                $rows[] = ['item_id' => $item->id, 'quantity' => $item->reorder_quantity ?: '', 'unit_cost' => $item->last_cost ?: ''];
                $po->supplier_id ??= $item->preferred_supplier_id;
            }
        }

        return view('inventory.purchase-orders.form', $this->formData($po, $rows));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $po = $this->purchasing->savePurchaseOrder(null, $data, $data['lines'] ?? []);

        return $this->afterSave($request, $po);
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load('supplier', 'branch', 'lines.item', 'creator', 'approver', 'receipts.receiver');

        return view('inventory.purchase-orders.show', [
            'pagetitle' => $purchaseOrder->number,
            'po'        => $purchaseOrder,
            'limit'     => (float) CurrentTenant::get()->setting('po_approval_threshold', 100000),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): View
    {
        abort_unless($purchaseOrder->isEditable(), 403, 'Only draft purchase orders can be changed.');
        $purchaseOrder->load('lines');

        return view('inventory.purchase-orders.form', $this->formData($purchaseOrder, $purchaseOrder->lines->map(fn ($l) => [
            'item_id' => $l->item_id, 'quantity' => $l->quantity, 'unit_cost' => $l->unit_cost,
        ])->all()));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $this->validated($request);
        $po = $this->purchasing->savePurchaseOrder($purchaseOrder, $data, $data['lines'] ?? []);

        return $this->afterSave($request, $po);
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $po = $this->purchasing->submit($purchaseOrder, $request->user());

        return redirect()->route('purchase-orders.show', $po)->with('success', $po->status === 'approved' ? 'Purchase order approved — you can send it to the supplier.' : 'Sent for approval.');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->purchasing->approve($purchaseOrder, $request->user());

        return back()->with('success', 'Purchase order approved.');
    }

    public function reopen(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->purchasing->reopen($purchaseOrder);

        return redirect()->route('purchase-orders.edit', $purchaseOrder)->with('success', 'Back to draft — make your changes and submit again.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $po = $this->purchasing->cancel($purchaseOrder, $data['reason']);

        return back()->with('success', $po->status === 'closed' ? 'Purchase order closed; what was received stays in stock.' : 'Purchase order cancelled.');
    }

    public function receiveForm(PurchaseOrder $purchaseOrder): View
    {
        abort_unless($purchaseOrder->isReceivable(), 403, 'Only approved purchase orders can be received.');
        $purchaseOrder->load('supplier', 'branch', 'lines.item');

        return view('inventory.purchase-orders.receive', ['pagetitle' => 'Receive ' . $purchaseOrder->number, 'po' => $purchaseOrder]);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'supplier_reference' => 'nullable|string|max:60',
            'notes'              => 'nullable|string|max:2000',
            'lines'              => 'required|array',
            'lines.*.purchase_order_line_id' => 'required|integer',
            'lines.*.quantity'   => 'nullable|numeric|min:0',
            'lines.*.unit_cost'  => 'nullable|numeric|min:0',
            'lines.*.lot_no'     => 'nullable|string|max:60',
            'lines.*.colour'     => 'nullable|string|max:60',
            'lines.*.width'      => 'nullable|string|max:30',
        ]);
        if (! $request->user()->can('View costing')) {
            foreach ($data['lines'] as &$l) {
                unset($l['unit_cost']);
            }
        }
        $grn = $this->purchasing->receive($data, $data['lines'], $purchaseOrder);

        return redirect()->route('goods-receipts.show', $grn)->with('success', 'Stock received and added to ' . $purchaseOrder->branch->name . '.');
    }

    /* ------------------------------------------------------------------ */

    protected function afterSave(Request $request, PurchaseOrder $po): RedirectResponse
    {
        if ($request->input('action') === 'submit') {
            return $this->submit($request, $po);
        }

        return redirect()->route('purchase-orders.show', $po)->with('success', 'Draft saved.');
    }

    protected function formData(PurchaseOrder $po, array $rows): array
    {
        return [
            'pagetitle'   => $po->exists ? 'Edit ' . $po->number : 'New purchase order',
            'po'          => $po,
            'rows'        => $rows,
            'suppliers'   => Supplier::active()->orderBy('name')->get(['id', 'name', 'code']),
            'branches'    => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'pickerItems' => ItemPicker::data(customerMaterial: false),
        ];
    }

    protected function validated(Request $request): array
    {
        $tenant = CurrentTenant::id();

        return $request->validate([
            'supplier_id'       => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'branch_id'         => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'order_date'        => 'required|date',
            'expected_date'     => 'nullable|date|after_or_equal:order_date',
            'notes'             => 'nullable|string|max:2000',
            'lines'             => 'required|array',
            'lines.*.item_id'   => 'nullable|integer',
            'lines.*.quantity'  => 'nullable|numeric|min:0',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'action'            => 'nullable|in:save,submit',
        ]);
    }
}
