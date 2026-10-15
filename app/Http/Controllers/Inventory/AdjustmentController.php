<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\StockAdjustment;
use App\Services\Inventory\StockDocumentService;
use App\Support\Inventory\ItemPicker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Adjustments, wastage, stock counts, opening balances and customer material (SRS FR-INV-006/010, scenario 41). */
class AdjustmentController extends Controller
{
    public function __construct(protected StockDocumentService $docs)
    {
        $this->middleware('permission:View inventory', ['only' => ['index', 'show']]);
        $this->middleware('permission:Adjust stock', ['only' => ['create', 'store', 'edit', 'update', 'submit', 'destroy']]);
        $this->middleware('permission:Approve stock adjustment', ['only' => ['approve', 'reject']]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'open');

        return view('inventory.adjustments.index', [
            'pagetitle'   => 'Adjustments & counts',
            'adjustments' => StockAdjustment::with('branch:id,name', 'creator:id,name', 'customer:id,first_name,last_name')->withCount('lines')
                ->when($status === 'open', fn ($q) => $q->whereIn('status', ['draft', 'pending_approval']))
                ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
                ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->kind))
                ->latest('id')->paginate(30)->withQueryString(),
            'status'      => $status,
            'counts'      => StockAdjustment::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'limit'       => (float) CurrentTenant::get()->setting('stock_approval_threshold', 20000),
            'tz'          => CurrentTenant::get()->timezone,
        ]);
    }

    public function create(Request $request): View
    {
        $kind = $request->input('kind', 'adjustment');
        abort_unless(array_key_exists($kind, StockAdjustment::KINDS), 404);
        $branchId = $request->integer('branch_id') ?: (CurrentTenant::membership()?->branch_id ?? Branch::where('is_main', true)->value('id'));
        $adj = new StockAdjustment(['kind' => $kind, 'branch_id' => $branchId, 'customer_id' => $request->integer('customer') ?: null]);

        $rows = [];
        if ($kind === 'count' && $request->boolean('sheet')) {
            $rows = $this->docs->countSheet($branchId, $request->input('type') ?: null, $request->input('category') ?: null)
                ->map(fn ($r) => ['item_id' => $r['item']->id, 'lot_id' => $r['lot']?->id, 'expected' => $r['on_hand'], 'counted_quantity' => ''])->all();
        } elseif ($request->filled('item')) {
            $item = InventoryItem::where('public_id', $request->item)->first();
            $rows = $item ? [['item_id' => $item->id]] : [];
        }

        return view('inventory.adjustments.form', $this->formData($adj, $rows));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $adj = $this->docs->saveAdjustment(null, $data, $data['lines'] ?? []);

        return $this->afterSave($request, $adj);
    }

    public function show(StockAdjustment $stockAdjustment): View
    {
        $stockAdjustment->load('branch', 'lines.item', 'lines.lot', 'creator', 'submitter', 'approver', 'customer');

        return view('inventory.adjustments.show', [
            'pagetitle' => $stockAdjustment->number,
            'adj'       => $stockAdjustment,
            'limit'     => (float) CurrentTenant::get()->setting('stock_approval_threshold', 20000),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function edit(StockAdjustment $stockAdjustment): View
    {
        abort_unless($stockAdjustment->isEditable(), 403, 'Only drafts can be changed.');
        $stockAdjustment->load('lines');

        return view('inventory.adjustments.form', $this->formData($stockAdjustment, $stockAdjustment->lines->map(fn ($l) => [
            'item_id' => $l->item_id, 'lot_id' => $l->lot_id, 'note' => $l->note, 'unit_cost' => $l->unit_cost,
            'expected' => $l->expected_quantity, 'counted_quantity' => $l->counted_quantity,
            'quantity_change' => $l->quantity_change, 'quantity' => abs($l->quantity_change),
        ])->all()));
    }

    public function update(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $data = $this->validated($request, false);
        $adj = $this->docs->saveAdjustment($stockAdjustment, $data, $data['lines'] ?? []);

        return $this->afterSave($request, $adj);
    }

    public function submit(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $adj = $this->docs->submitAdjustment($stockAdjustment, $request->user());

        return redirect()->route('stock-adjustments.show', $adj)
            ->with('success', $adj->status === 'approved' ? 'Posted to the stock ledger.' : 'Sent for approval.');
    }

    public function approve(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $this->docs->approveAdjustment($stockAdjustment, $request->user());

        return back()->with('success', 'Approved and posted to the stock ledger.');
    }

    public function reject(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->docs->rejectAdjustment($stockAdjustment, $request->user(), $data['reason']);

        return back()->with('success', 'Rejected — nothing was posted.');
    }

    public function destroy(StockAdjustment $stockAdjustment): RedirectResponse
    {
        abort_unless($stockAdjustment->status === 'draft', 403, 'Only drafts can be deleted.');
        $stockAdjustment->lines()->delete();
        $stockAdjustment->delete();

        return redirect()->route('stock-adjustments.index')->with('success', 'Draft deleted.');
    }

    /* ------------------------------------------------------------------ */

    protected function afterSave(Request $request, StockAdjustment $adj): RedirectResponse
    {
        if ($request->input('action') === 'submit') {
            return $this->submit($request, $adj);
        }

        return redirect()->route('stock-adjustments.show', $adj)->with('success', 'Draft saved.');
    }

    protected function formData(StockAdjustment $adj, array $rows): array
    {
        $kind = $adj->kind;

        return [
            'pagetitle'   => ($adj->exists ? $adj->number . ' · ' : 'New ') . strtolower(StockAdjustment::KINDS[$kind]),
            'adj'         => $adj,
            'rows'        => $rows,
            'branches'    => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'customers'   => $kind === 'customer_material' ? Customer::active()->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'customer_number']) : collect(),
            'pickerItems' => $kind === 'customer_material'
                ? ItemPicker::data(['customer_material'])
                : ItemPicker::data(customerMaterial: $kind !== 'opening'),
            'types'       => InventoryItem::TYPES,
            'categories'  => InventoryItem::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'canCost'     => request()->user()->can('View costing'),
        ];
    }

    protected function validated(Request $request, bool $creating): array
    {
        $tenant = CurrentTenant::id();

        return $request->validate([
            'kind'        => [$creating ? 'required' : 'nullable', Rule::in(array_keys(StockAdjustment::KINDS))],
            'branch_id'   => [$creating ? 'required' : 'nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'reason'      => 'nullable|string|max:255',
            'lines'       => 'required|array',
            'lines.*.item_id'          => 'nullable|integer',
            'lines.*.lot_id'           => 'nullable|integer',
            'lines.*.lot_no'           => 'nullable|string|max:60',
            'lines.*.quantity'         => 'nullable|numeric|min:0',
            'lines.*.quantity_change'  => 'nullable|numeric',
            'lines.*.counted_quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_cost'        => 'nullable|numeric|min:0',
            'lines.*.note'             => 'nullable|string|max:255',
            'action'      => 'nullable|in:save,submit',
        ]);
    }
}
