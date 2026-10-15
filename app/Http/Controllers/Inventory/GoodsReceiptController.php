<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Services\Inventory\PurchasingService;
use App\Support\Inventory\ItemPicker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Goods-received notes; direct receipts are for buys without a purchase order (market purchases). */
class GoodsReceiptController extends Controller
{
    public function __construct(protected PurchasingService $purchasing)
    {
        $this->middleware('permission:View inventory', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage inventory', ['only' => ['create', 'store']]);
    }

    public function index(Request $request): View
    {
        return view('inventory.receipts.index', [
            'pagetitle' => 'Goods received',
            'receipts'  => GoodsReceipt::with('supplier:id,name', 'branch:id,name', 'purchaseOrder:id,public_id,number', 'receiver:id,name')
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                    ->orWhere('supplier_reference', 'like', "%{$request->q}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$request->q}%"))))
                ->latest('received_at')->latest('id')->paginate(30)->withQueryString(),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    public function create(): View
    {
        return view('inventory.receipts.form', [
            'pagetitle'   => 'Receive stock',
            'suppliers'   => Supplier::active()->orderBy('name')->get(['id', 'name']),
            'branches'    => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
            'branchId'    => CurrentTenant::membership()?->branch_id ?? Branch::where('is_main', true)->value('id'),
            'pickerItems' => ItemPicker::data(customerMaterial: false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'supplier_id'        => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)],
            'branch_id'          => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'supplier_reference' => 'nullable|string|max:60',
            'notes'              => 'nullable|string|max:2000',
            'lines'              => 'required|array',
            'lines.*.item_id'    => 'nullable|integer',
            'lines.*.quantity'   => 'nullable|numeric|min:0',
            'lines.*.unit_cost'  => 'nullable|numeric|min:0',
            'lines.*.lot_no'     => 'nullable|string|max:60',
        ]);
        $grn = $this->purchasing->receive($data, $data['lines']);

        return redirect()->route('goods-receipts.show', $grn)->with('success', 'Stock received.');
    }

    public function show(GoodsReceipt $goodsReceipt): View
    {
        $goodsReceipt->load('supplier', 'branch', 'purchaseOrder', 'lines.item', 'lines.lot', 'receiver');

        return view('inventory.receipts.show', ['pagetitle' => $goodsReceipt->number, 'grn' => $goodsReceipt, 'tz' => CurrentTenant::get()->timezone]);
    }
}
