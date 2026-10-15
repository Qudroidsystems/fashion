<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\MaterialIssue;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\PurchasingService;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\StockLedger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SRS §8.2 Inventory & procurement:
 *   GET/POST /inventory-items · GET /inventory-items/{id} · GET /stock-balances · GET /stock-movements
 *   POST /stock-transfers · POST /stock-counts · POST /material-issues
 *   GET /suppliers · GET/POST /purchase-orders · GET /purchase-orders/{id}
 *   POST /purchase-orders/{id}/submit · POST /purchase-orders/{id}/approve · POST /goods-receipts
 *
 * Items in request lines may be given as `item` = public id, code or barcode
 * (so a scanner can send what it read); locations as `branch_id`.
 * Costs are returned only with "View costing".
 */
class InventoryController extends Controller
{
    public function __construct(
        protected StockLedger $ledger,
        protected ItemService $items,
        protected PurchasingService $purchasing,
        protected StockDocumentService $docs,
    ) {
        $this->middleware('permission:View inventory', ['only' => ['items', 'item', 'balances', 'movements', 'suppliers', 'purchaseOrders', 'purchaseOrder']]);
        $this->middleware('permission:Manage inventory', ['only' => ['storeItem', 'transfer', 'receive']]);
        $this->middleware('permission:Adjust stock', ['only' => ['count']]);
        $this->middleware('permission:Issue material', ['only' => ['issue']]);
        $this->middleware('permission:Manage purchase order', ['only' => ['storePurchaseOrder', 'submitPurchaseOrder']]);
        $this->middleware('permission:Approve purchase order', ['only' => ['approvePurchaseOrder']]);
    }

    /* -------------------------------- items -------------------------------- */

    public function items(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:100', 'type' => 'nullable|string', 'per_page' => 'nullable|integer|min:1|max:100']);
        $page = InventoryItem::active()->search($request->q)->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->orderBy('name')->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($i) => $this->itemData($i, $request)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function item(Request $request, string $item): JsonResponse
    {
        $i = $this->findItem($item);
        $branches = StockBalance::where('item_id', $i->id)->with('lot:id,lot_no,kind', 'branch:id,name')->get()
            ->map(fn ($b) => ['branch_id' => $b->branch_id, 'branch' => $b->branch?->name, 'lot' => $b->lot?->lot_no, 'lot_kind' => $b->lot?->kind,
                'on_hand' => $b->on_hand, 'reserved' => $b->reserved])->values();

        return response()->json(['success' => true, 'data' => $this->itemData($i, $request) + [
            'availability' => $this->ledger->availability($i),
            'balances'     => $branches,
        ]]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'code'          => ['nullable', 'string', 'max:60', Rule::unique('inventory_items', 'code')->where('tenant_id', $tenant)],
            'type'          => ['required', Rule::in(array_diff(array_keys(InventoryItem::TYPES), ['product']))],
            'category'      => 'nullable|string|max:80',
            'unit'          => ['required', Rule::in(array_keys(InventoryItem::UNITS))],
            'decimals'      => 'nullable|integer|min:0|max:3',
            'barcode'       => ['nullable', 'string', 'max:40', Rule::unique('inventory_items', 'barcode')->where('tenant_id', $tenant)],
            'colour'        => 'nullable|string|max:60',
            'width'         => 'nullable|string|max:30',
            'reorder_level' => 'nullable|numeric|min:0',
            'track_lots'    => 'nullable|boolean',
            'customer_id'   => ['nullable', 'required_if:type,customer_material', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
        ]);
        $item = $this->items->create($data);

        return response()->json(['success' => true, 'data' => $this->itemData($item, $request)], 201);
    }

    public function balances(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => 'nullable|integer', 'item' => 'nullable|string']);
        $rows = StockBalance::query()
            ->when($request->branch_id, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->item, fn ($q, $i) => $q->where('item_id', $this->findItem($i)->id))
            ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0))
            ->with('item:id,public_id,code,name,unit,decimals', 'lot:id,lot_no,kind', 'branch:id,name')
            ->orderBy('item_id')->limit(1000)->get();

        return response()->json(['success' => true, 'data' => $rows->map(fn ($b) => [
            'item_id' => $b->item->public_id, 'code' => $b->item->code, 'name' => $b->item->name, 'unit' => $b->item->unit,
            'branch_id' => $b->branch_id, 'branch' => $b->branch?->name, 'lot' => $b->lot?->lot_no, 'lot_kind' => $b->lot?->kind,
            'on_hand' => $b->on_hand, 'reserved' => $b->reserved, 'available' => round($b->on_hand - $b->reserved, 3),
        ])]);
    }

    public function movements(Request $request): JsonResponse
    {
        $request->validate(['item' => 'nullable|string', 'branch_id' => 'nullable|integer', 'per_page' => 'nullable|integer|min:1|max:200']);
        $cost = $request->user()->can('View costing');
        $page = StockMovement::with('item:id,public_id,code,name,unit', 'lot:id,lot_no', 'user:id,name')
            ->when($request->item, fn ($q, $i) => $q->where('item_id', $this->findItem($i)->id))
            ->when($request->branch_id, fn ($q, $b) => $q->where('branch_id', $b))
            ->latest('occurred_at')->latest('id')->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($m) => [
                'id' => $m->id, 'occurred_at' => $m->occurred_at->toIso8601String(), 'type' => $m->type,
                'item_id' => $m->item->public_id, 'item' => $m->item->name, 'branch_id' => $m->branch_id, 'lot' => $m->lot?->lot_no,
                'quantity' => $m->quantity, 'balance_after' => $m->balance_after, 'unit' => $m->item->unit,
                'reference' => $m->reference, 'reason' => $m->reason, 'by' => $m->user?->name,
            ] + ($cost ? ['unit_cost' => $m->unit_cost] : [])),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /* ---------------------------- stock documents -------------------------- */

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_branch_id' => ['required', $this->branchRule()],
            'to_branch_id'   => ['required', 'different:from_branch_id', $this->branchRule()],
            'notes'          => 'nullable|string|max:2000',
            'lines'          => 'required|array|min:1',
            'lines.*.item'   => 'required|string',
            'lines.*.lot'    => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0.001',
        ]);
        $t = $this->docs->transfer((int) $data['from_branch_id'], (int) $data['to_branch_id'], $this->mapLines($data['lines']), $data['notes'] ?? null);

        return response()->json(['success' => true, 'data' => ['id' => $t->public_id, 'number' => $t->number]], 201);
    }

    /** A stock count; posts immediately when the counter may approve and it is within the limit. */
    public function count(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', $this->branchRule()],
            'reason'    => 'nullable|string|max:255',
            'lines'     => 'required|array|min:1',
            'lines.*.item' => 'required|string',
            'lines.*.lot'  => 'nullable|string',
            'lines.*.counted_quantity' => 'required|numeric|min:0',
        ]);
        $lines = collect($data['lines'])->map(fn ($l) => $this->mapLine($l) + ['counted_quantity' => $l['counted_quantity']])->all();
        $adj = $this->docs->saveAdjustment(null, ['kind' => 'count', 'branch_id' => $data['branch_id'], 'reason' => $data['reason'] ?? 'Count from app'], $lines);
        $adj = $this->docs->submitAdjustment($adj, $request->user());

        return response()->json(['success' => true, 'data' => [
            'id' => $adj->public_id, 'number' => $adj->number, 'status' => $adj->status,
            'differences' => $adj->lines()->with('item:id,public_id,name')->get()->map(fn ($l) => [
                'item_id' => $l->item->public_id, 'expected' => $l->expected_quantity, 'counted' => $l->counted_quantity, 'change' => $l->quantity_change,
            ]),
        ]], 201);
    }

    public function issue(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'branch_id'     => ['required', $this->branchRule()],
            'customer_id'   => ['nullable', Rule::exists('customers', 'public_id')->where('tenant_id', $tenant)],
            'job_reference' => 'required|string|max:120',
            'notes'         => 'nullable|string|max:2000',
            'allow_reserved'=> 'nullable|boolean',
            'lines'         => 'required|array|min:1',
            'lines.*.item'  => 'required|string',
            'lines.*.lot'   => 'nullable|string',
            'lines.*.quantity' => 'required|numeric|min:0.001',
        ]);
        $data['customer_id'] = ! empty($data['customer_id']) ? \App\Models\Customer::where('public_id', $data['customer_id'])->value('id') : null;
        $issue = $this->docs->issue($data, $this->mapLines($data['lines']));

        return response()->json(['success' => true, 'data' => [
            'id' => $issue->public_id, 'number' => $issue->number,
        ] + ($request->user()->can('View costing') ? ['total_cost' => $issue->total_cost] : [])], 201);
    }

    /* ------------------------------ purchasing ----------------------------- */

    public function suppliers(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Supplier::active()->search($request->q)->orderBy('name')->limit(200)->get()
            ->map(fn ($s) => ['id' => $s->public_id, 'code' => $s->code, 'name' => $s->name, 'phone' => $s->phone, 'contact_person' => $s->contact_person])]);
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        $page = PurchaseOrder::with('supplier:id,name', 'branch:id,name')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('order_date')->latest('id')->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($po) => $this->poData($po, $request, false)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function purchaseOrder(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->poData($purchaseOrder->load('lines.item', 'supplier', 'branch'), $request, true)]);
    }

    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'supplier_id'   => ['required', Rule::exists('suppliers', 'public_id')->where('tenant_id', $tenant)],
            'branch_id'     => ['required', $this->branchRule()],
            'order_date'    => 'nullable|date',
            'expected_date' => 'nullable|date',
            'notes'         => 'nullable|string|max:2000',
            'submit'        => 'nullable|boolean',
            'lines'         => 'required|array|min:1',
            'lines.*.item'  => 'required|string',
            'lines.*.quantity'  => 'required|numeric|min:0.001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);
        $data['supplier_id'] = Supplier::where('public_id', $data['supplier_id'])->value('id');
        $lines = collect($data['lines'])->map(fn ($l) => ['item_id' => $this->findItem($l['item'])->id, 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost']])->all();
        $po = $this->purchasing->savePurchaseOrder(null, $data, $lines);
        if ($request->boolean('submit')) {
            $po = $this->purchasing->submit($po, $request->user());
        }

        return response()->json(['success' => true, 'data' => $this->poData($po->fresh(['lines.item', 'supplier', 'branch']), $request, true)], 201);
    }

    public function submitPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->purchasing->submit($purchaseOrder, $request->user());

        return response()->json(['success' => true, 'data' => $this->poData($po, $request, false)]);
    }

    public function approvePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->purchasing->approve($purchaseOrder, $request->user());

        return response()->json(['success' => true, 'data' => $this->poData($po, $request, false)]);
    }

    /** Receive against a PO (lines by purchase_order_line_id) or directly (lines by item). */
    public function receive(Request $request): JsonResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'purchase_order_id'  => ['nullable', Rule::exists('purchase_orders', 'public_id')->where('tenant_id', $tenant)],
            'branch_id'          => ['required_without:purchase_order_id', 'nullable', $this->branchRule()],
            'supplier_id'        => ['nullable', Rule::exists('suppliers', 'public_id')->where('tenant_id', $tenant)],
            'supplier_reference' => 'nullable|string|max:60',
            'notes'              => 'nullable|string|max:2000',
            'lines'              => 'required|array|min:1',
            'lines.*.purchase_order_line_id' => 'nullable|integer',
            'lines.*.item'       => 'nullable|string',
            'lines.*.quantity'   => 'required|numeric|min:0',
            'lines.*.unit_cost'  => 'nullable|numeric|min:0',
            'lines.*.lot_no'     => 'nullable|string|max:60',
        ]);
        $po = ! empty($data['purchase_order_id']) ? PurchaseOrder::where('public_id', $data['purchase_order_id'])->first() : null;
        $data['supplier_id'] = ! empty($data['supplier_id']) ? Supplier::where('public_id', $data['supplier_id'])->value('id') : null;
        $canCost = $request->user()->can('View costing');
        $lines = collect($data['lines'])->map(function ($l) use ($po, $canCost) {
            if (! $po) {
                if (empty($l['item'])) {
                    throw ValidationException::withMessages(['lines' => 'Each line needs an item.']);
                }
                $l['item_id'] = $this->findItem($l['item'])->id;
            }
            if (! $canCost) {
                unset($l['unit_cost']);   // cost then comes from the PO line or the item's average
            }

            return $l;
        })->all();
        $grn = $this->purchasing->receive($data, $lines, $po);

        return response()->json(['success' => true, 'data' => ['id' => $grn->public_id, 'number' => $grn->number, 'purchase_order_status' => $po?->fresh()->status]], 201);
    }

    /* ------------------------------ helpers -------------------------------- */

    protected function findItem(string $ref): InventoryItem
    {
        $item = InventoryItem::where('public_id', $ref)->orWhere('code', $ref)->orWhere('barcode', $ref)->first();
        if (! $item) {
            throw ValidationException::withMessages(['item' => "No stock item matches “{$ref}”."]);
        }

        return $item;
    }

    protected function mapLine(array $l): array
    {
        $item = $this->findItem($l['item']);
        $lotId = ! empty($l['lot']) ? StockLot::where('item_id', $item->id)->where('lot_no', $l['lot'])->value('id') : null;
        if (! empty($l['lot']) && ! $lotId) {
            throw ValidationException::withMessages(['lines' => "No roll / lot “{$l['lot']}” for {$item->name}."]);
        }

        return ['item_id' => $item->id, 'lot_id' => $lotId];
    }

    protected function mapLines(array $lines): array
    {
        return collect($lines)->map(fn ($l) => $this->mapLine($l) + ['quantity' => $l['quantity']])->all();
    }

    protected function branchRule()
    {
        return Rule::exists('branches', 'id')->where('tenant_id', CurrentTenant::id())->whereNull('deleted_at');
    }

    protected function itemData(InventoryItem $i, Request $request): array
    {
        $a = $this->ledger->availability($i);

        return [
            'id' => $i->public_id, 'code' => $i->code, 'name' => $i->name, 'type' => $i->type, 'category' => $i->category,
            'unit' => $i->unit, 'decimals' => $i->decimals, 'barcode' => $i->barcode, 'colour' => $i->colour, 'width' => $i->width,
            'track_lots' => $i->track_lots, 'reorder_level' => $i->reorder_level,
            'on_hand' => $a['on_hand'], 'reserved' => $a['reserved'], 'available' => $a['available'], 'incoming' => $a['incoming'],
        ] + ($request->user()->can('View costing') && ! $i->isCustomerOwned() ? ['average_cost' => $i->average_cost] : []);
    }

    protected function poData(PurchaseOrder $po, Request $request, bool $lines): array
    {
        $cost = $request->user()->can('View costing');
        $out = [
            'id' => $po->public_id, 'number' => $po->number, 'status' => $po->status,
            'supplier' => $po->supplier?->name, 'branch_id' => $po->branch_id, 'branch' => $po->branch?->name,
            'order_date' => $po->order_date?->toDateString(), 'expected_date' => $po->expected_date?->toDateString(),
        ] + ($cost ? ['total' => $po->total] : []);
        if ($lines) {
            $out['lines'] = $po->lines->map(fn ($l) => [
                'id' => $l->id, 'item_id' => $l->item->public_id, 'item' => $l->item->name, 'unit' => $l->item->unit,
                'quantity' => $l->quantity, 'received_quantity' => $l->received_quantity, 'outstanding' => $l->outstanding,
            ] + ($cost ? ['unit_cost' => $l->unit_cost] : []));
        }

        return $out;
    }
}
