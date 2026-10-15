<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Orders\BillingService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(protected BillingService $billing)
    {
        $this->middleware('permission:View payment', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage invoice', ['only' => ['fromOrder', 'create', 'store', 'issue', 'void']]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'unpaid');
        $invoices = Invoice::with('customer:id,public_id,first_name,last_name', 'order:id,public_id,number')
            ->when($status === 'unpaid', fn ($q) => $q->where('status', 'issued'))
            ->when(in_array($status, ['draft', 'void'], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                ->orWhereHas('customer', fn ($c) => $c->search($request->q))))
            ->latest('id')->paginate(40)->withQueryString();
        if ($status === 'unpaid') {
            $invoices->setCollection($invoices->getCollection()->filter(fn ($i) => $i->balance() > 0)->values());
        }

        return view('orders.invoices.index', ['pagetitle' => 'Invoices', 'invoices' => $invoices, 'status' => $status]);
    }

    public function fromOrder(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|in:deposit,balance,full', 'amount' => 'nullable|numeric|min:0.01', 'due_date' => 'nullable|date']);
        $invoice = $this->billing->invoiceOrder($order, $data['kind'], isset($data['amount']) ? (float) $data['amount'] : null, $data);

        return redirect()->route('invoices.show', $invoice)->with('success', 'Draft invoice created. Check it, then issue it.');
    }

    public function create(Request $request): View
    {
        return view('orders.invoices.form', [
            'pagetitle' => 'New invoice',
            'customer'  => $request->filled('customer') ? Customer::where('public_id', $request->customer)->first() : null,
            'customers' => Customer::active()->orderBy('first_name')->limit(1000)->get(['id', 'first_name', 'last_name', 'customer_number']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', CurrentTenant::id())],
            'due_date'    => 'nullable|date',
            'notes'       => 'nullable|string|max:2000',
            'lines'       => 'required|array',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity'    => 'nullable|numeric|min:0',
            'lines.*.unit_price'  => 'nullable|numeric|min:0',
        ]);
        $invoice = $this->billing->invoiceCustomer(Customer::findOrFail($data['customer_id']), $data['lines'], $data);

        return redirect()->route('invoices.show', $invoice)->with('success', 'Draft invoice created.');
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load('customer', 'order', 'lines', 'allocations.payment');

        return view('orders.invoices.show', ['pagetitle' => $invoice->number ?? 'Draft invoice', 'invoice' => $invoice, 'house' => CurrentTenant::get(), 'tz' => CurrentTenant::get()->timezone]);
    }

    public function issue(Invoice $invoice): RedirectResponse
    {
        $this->billing->issue($invoice);

        return back()->with('success', 'Invoice ' . $invoice->number . ' issued.');
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->billing->void($invoice, $data['reason']);

        return back()->with('success', 'Invoice voided. Any payment on it moved to the order.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->status === 'draft' && request()->user()->can('Manage invoice'), 403);
        $order = $invoice->order;
        $invoice->lines()->delete();
        $invoice->delete();

        return $order ? redirect()->route('orders.show', $order)->with('success', 'Draft deleted.') : redirect()->route('invoices.index')->with('success', 'Draft deleted.');
    }
}
