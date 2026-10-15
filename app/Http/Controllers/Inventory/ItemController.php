<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\StockLedger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Stock overview, items, the ledger and reservations (SRS §5.6). */
class ItemController extends Controller
{
    public function __construct(protected ItemService $items, protected StockLedger $ledger)
    {
        $this->middleware('permission:View inventory', ['only' => ['index', 'show', 'movements']]);
        $this->middleware('permission:Manage inventory', ['only' => ['create', 'store', 'edit', 'update', 'reserve', 'release']]);
    }

    public function index(Request $request): View
    {
        $branchId = $request->integer('branch') ?: null;
        $show = $request->input('show', 'all');   // all | low | out | in_stock
        $type = $request->input('type');

        $sumSql = fn (string $col) => '(select coalesce(sum(' . $col . '),0) from stock_balances sb where sb.item_id = inventory_items.id'
            . ($branchId ? ' and sb.branch_id = ' . (int) $branchId : '') . ')';

        $query = InventoryItem::query()
            ->select('inventory_items.*')
            ->selectRaw($sumSql('on_hand') . ' as on_hand_sum')
            ->selectRaw($sumSql('reserved') . ' as reserved_sum')
            ->where('is_active', $request->input('status', 'active') === 'active')
            ->search($request->q)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($show === 'low', fn ($q) => $q->whereNotNull('reorder_level')->whereRaw($sumSql('on_hand') . ' - ' . $sumSql('reserved') . ' < reorder_level'))
            ->when($show === 'out', fn ($q) => $q->whereRaw($sumSql('on_hand') . ' <= 0'))
            ->when($show === 'in_stock', fn ($q) => $q->whereRaw($sumSql('on_hand') . ' > 0'))
            ->with('customer:id,first_name,last_name')
            ->orderBy('name');

        $lowCount = InventoryItem::active()->whereNotNull('reorder_level')
            ->whereRaw('(select coalesce(sum(on_hand - reserved),0) from stock_balances sb where sb.item_id = inventory_items.id) < reorder_level')->count();

        return view('inventory.index', [
            'pagetitle'  => 'Stock',
            'items'      => $query->paginate(40)->withQueryString(),
            'branches'   => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'categories' => InventoryItem::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'branchId'   => $branchId,
            'show'       => $show,
            'stats'      => [
                'items'     => InventoryItem::active()->count(),
                'low'       => $lowCount,
                'value'     => $request->user()->can('View costing') ? $this->ledger->valuation($branchId) : null,
                'approvals' => PurchaseOrder::where('status', 'pending_approval')->count() + StockAdjustment::where('status', 'pending_approval')->count(),
                'incoming'  => PurchaseOrder::whereIn('status', ['approved', 'partially_received'])->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $item = new InventoryItem([
            'type' => $request->input('type', 'fabric'), 'unit' => $request->input('type') === 'accessory' ? 'pcs' : 'yd',
            'is_active' => true, 'customer_id' => $request->integer('customer') ?: null,
        ]);

        return view('inventory.items.form', $this->formData($item));
    }

    public function store(Request $request): RedirectResponse
    {
        $item = $this->items->create($this->validated($request));

        return redirect()->route('inventory.items.show', $item)->with('success', 'Item added. Record opening stock or receive it from a supplier.');
    }

    public function show(Request $request, InventoryItem $item): View
    {
        $item->load('preferredSupplier', 'customer', 'productVariant.product');
        $branches = Branch::withTrashed()->get()->keyBy('id');
        $balances = StockBalance::where('item_id', $item->id)->with('lot')->get();

        $byBranch = $balances->groupBy('branch_id')->map(fn ($rows, $bid) => [
            'branch'   => $branches[$bid] ?? null,
            'on_hand'  => round($rows->sum('on_hand'), 3),
            'reserved' => round($rows->sum('reserved'), 3),
            'lots'     => $rows->where('lot_key', '>', 0)->where('on_hand', '!=', 0)->values(),
        ])->filter(fn ($r) => $r['on_hand'] != 0 || $r['reserved'] != 0);

        $incoming = PurchaseOrderLine::where('item_id', $item->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['approved', 'partially_received']))
            ->with('order.supplier', 'order.branch')->get()->filter(fn ($l) => $l->outstanding > 0);

        return view('inventory.items.show', [
            'pagetitle'    => $item->name,
            'item'         => $item,
            'availability' => $this->ledger->availability($item),
            'byBranch'     => $byBranch,
            'incoming'     => $incoming,
            'reservations' => StockReservation::where('item_id', $item->id)->where('status', 'active')->with('branch', 'customer', 'creator')->latest()->get(),
            'movements'    => StockMovement::where('item_id', $item->id)->with('branch', 'lot', 'user:id,name', 'customer:id,first_name,last_name')
                ->latest('occurred_at')->latest('id')->limit(30)->get(),
            'branches'     => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'customers'    => Customer::active()->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'customer_number']),
            'tz'           => CurrentTenant::get()->timezone,
        ]);
    }

    public function edit(InventoryItem $item): View
    {
        return view('inventory.items.form', $this->formData($item));
    }

    public function update(Request $request, InventoryItem $item): RedirectResponse
    {
        abort_if($item->type === 'product' && $item->product_variant_id, 403, 'Edit ready-made products from the product page.');
        $this->items->update($item, $this->validated($request, $item));

        return redirect()->route('inventory.items.show', $item)->with('success', 'Item updated.');
    }

    /** The full ledger, filterable (FR-INV-004). */
    public function movements(Request $request): View
    {
        $movements = StockMovement::with('item:id,public_id,name,code,unit,decimals', 'branch:id,name', 'lot', 'user:id,name', 'customer:id,public_id,first_name,last_name')
            ->when($request->filled('item'), fn ($q) => $q->whereHas('item', fn ($i) => $i->where('public_id', $request->item)))
            ->when($request->integer('branch'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->date('to')->endOfDay()))
            ->latest('occurred_at')->latest('id')->paginate(50)->withQueryString();

        return view('inventory.movements', [
            'pagetitle' => 'Stock ledger',
            'movements' => $movements,
            'branches'  => Branch::withTrashed()->orderBy('name')->get(),
            'filterItem'=> $request->filled('item') ? InventoryItem::where('public_id', $request->item)->first() : null,
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function reserve(Request $request, InventoryItem $item): RedirectResponse
    {
        $data = $request->validate([
            'branch_id'   => ['required', Rule::exists('branches', 'id')->where('tenant_id', CurrentTenant::id())],
            'quantity'    => 'required|numeric|min:0.001',
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', CurrentTenant::id())],
            'reference'   => 'required|string|max:120',
            'needed_by'   => 'nullable|date',
        ]);
        $this->ledger->reserve($item, (int) $data['branch_id'], (float) $data['quantity'], $data);
        \App\Services\Audit\AuditLogger::log('stock.reserved', $item, after: $data);

        return back()->with('success', 'Reserved ' . $item->qty($data['quantity']) . ' for ' . $data['reference'] . '.');
    }

    public function release(StockReservation $reservation): RedirectResponse
    {
        abort_unless(request()->user()->can('Manage inventory') || request()->user()->can('Issue material'), 403);
        $this->ledger->release($reservation);
        \App\Services\Audit\AuditLogger::log('stock.reservation_released', $reservation->item, description: $reservation->reference);

        return back()->with('success', 'Reservation released.');
    }

    /* ------------------------------------------------------------------ */

    protected function formData(InventoryItem $item): array
    {
        return [
            'pagetitle' => $item->exists ? 'Edit ' . $item->name : 'New stock item',
            'item'      => $item,
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::active()->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'customer_number']),
            'categories'=> InventoryItem::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'hasStock'  => $item->exists && StockMovement::where('item_id', $item->id)->exists(),
        ];
    }

    protected function validated(Request $request, ?InventoryItem $item = null): array
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'name'                  => 'required|string|max:150',
            'code'                  => ['nullable', 'string', 'max:60', Rule::unique('inventory_items', 'code')->where('tenant_id', $tenant)->ignore($item?->id)],
            'type'                  => ['required', Rule::in(array_keys(InventoryItem::TYPES))],
            'category'              => 'nullable|string|max:80',
            'unit'                  => ['required', Rule::in(array_keys(InventoryItem::UNITS))],
            'decimals'              => 'nullable|integer|min:0|max:3',
            'barcode'               => ['nullable', 'string', 'max:40', Rule::unique('inventory_items', 'barcode')->where('tenant_id', $tenant)->ignore($item?->id)],
            'colour'                => 'nullable|string|max:60',
            'width'                 => 'nullable|string|max:30',
            'description'           => 'nullable|string|max:2000',
            'opening_cost'          => 'nullable|numeric|min:0',
            'sale_price'            => 'nullable|numeric|min:0',
            'reorder_level'         => 'nullable|numeric|min:0',
            'reorder_quantity'      => 'nullable|numeric|min:0',
            'preferred_supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'customer_id'           => ['nullable', 'required_if:type,customer_material', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'track_lots'            => 'nullable|boolean',
            'is_active'             => 'nullable|boolean',
        ], ['customer_id.required_if' => 'Choose which customer owns this material.']);

        $data['track_lots'] = $request->boolean('track_lots');
        $data['is_active'] = $item ? $request->boolean('is_active') : true;
        $data['decimals'] = $data['decimals'] ?? InventoryItem::UNITS[$data['unit']] ?? 0;
        if ($data['type'] !== 'customer_material') {
            $data['customer_id'] = null;
        }
        if (! $request->user()->can('View costing')) {
            unset($data['opening_cost']);
        }

        return $data;
    }
}
