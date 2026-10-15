<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Inventory\PurchasingService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(protected PurchasingService $purchasing)
    {
        $this->middleware('permission:View inventory', ['only' => ['index']]);
        $this->middleware('permission:Manage supplier', ['only' => ['create', 'store', 'edit', 'update']]);
    }

    public function index(Request $request): View
    {
        $suppliers = Supplier::search($request->q)
            ->where('is_active', $request->input('status', 'active') === 'active')
            ->withCount(['purchaseOrders as open_orders' => fn ($q) => $q->whereIn('status', ['pending_approval', 'approved', 'partially_received'])])
            ->withSum(['purchaseOrders as ordered_total' => fn ($q) => $q->whereNotIn('status', ['draft', 'cancelled'])], 'total')
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('inventory.suppliers.index', ['pagetitle' => 'Suppliers', 'suppliers' => $suppliers]);
    }

    public function create(): View
    {
        return view('inventory.suppliers.form', ['pagetitle' => 'New supplier', 'supplier' => new Supplier(['is_active' => true]), 'orders' => collect(), 'receipts' => collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = $this->purchasing->saveSupplier(null, $this->validated($request));

        return redirect()->route('suppliers.edit', $supplier)->with('success', 'Supplier added.');
    }

    public function edit(Supplier $supplier): View
    {
        return view('inventory.suppliers.form', [
            'pagetitle' => $supplier->name,
            'supplier'  => $supplier,
            'orders'    => PurchaseOrder::where('supplier_id', $supplier->id)->latest('order_date')->latest('id')->limit(15)->get(),
            'receipts'  => GoodsReceipt::where('supplier_id', $supplier->id)->latest('received_at')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->purchasing->saveSupplier($supplier, $this->validated($request, $supplier));

        return back()->with('success', 'Supplier saved.');
    }

    protected function validated(Request $request, ?Supplier $supplier = null): array
    {
        $data = $request->validate([
            'name'               => 'required|string|max:150',
            'code'               => ['nullable', 'string', 'max:30', Rule::unique('suppliers', 'code')->where('tenant_id', CurrentTenant::id())->ignore($supplier?->id)],
            'contact_person'     => 'nullable|string|max:120',
            'phone'              => 'nullable|string|max:30',
            'email'              => 'nullable|email|max:150',
            'address'            => 'nullable|string|max:255',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'bank_details'       => 'nullable|string|max:255',
            'notes'              => 'nullable|string|max:2000',
            'is_active'          => 'nullable|boolean',
        ]);
        $data['is_active'] = $supplier ? $request->boolean('is_active') : true;

        return $data;
    }
}
