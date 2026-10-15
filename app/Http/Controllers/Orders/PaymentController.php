<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Orders\BillingService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Payments, allocations, refunds and reversals (SRS FR-ORD-006…010). */
class PaymentController extends Controller
{
    public function __construct(protected BillingService $billing)
    {
        $this->middleware('permission:View payment', ['only' => ['index', 'show']]);
        $this->middleware('permission:Record payment', ['only' => ['create', 'store', 'allocate', 'requestRefund']]);
        $this->middleware('permission:Approve refund', ['only' => ['reverse', 'approveRefund', 'rejectRefund']]);
    }

    public function index(Request $request): View
    {
        $tz = CurrentTenant::get()->timezone;
        $today = now($tz)->startOfDay()->utc();

        return view('orders.payments.index', [
            'pagetitle' => 'Payments',
            'payments'  => Payment::with('customer:id,public_id,first_name,last_name', 'receiver:id,name')
                ->when($request->filled('method'), fn ($q) => $q->where('method', $request->input('method')))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                    ->orWhere('reference', 'like', "%{$request->q}%")->orWhereHas('customer', fn ($c) => $c->search($request->q))))
                ->latest('received_at')->latest('id')->paginate(40)->withQueryString(),
            'pending'   => Refund::where('status', 'pending_approval')->with('payment.customer', 'requester:id,name')->latest()->get(),
            'today'     => Payment::where('status', 'confirmed')->where('received_at', '>=', $today)->selectRaw('method, sum(amount) total, count(*) n')->groupBy('method')->get(),
            'tz'        => $tz,
        ]);
    }

    public function create(Request $request): View
    {
        $order = $request->filled('order') ? Order::where('public_id', $request->order)->with('customer')->first() : null;
        $invoice = $request->filled('invoice') ? Invoice::where('public_id', $request->invoice)->with('customer')->first() : null;
        $customer = $order?->customer ?? $invoice?->customer
            ?? ($request->filled('customer') ? Customer::where('public_id', $request->customer)->first() : null);

        $openOrders = $customer ? Order::where('customer_id', $customer->id)->whereNotIn('status', ['draft', 'cancelled'])->get()->filter(fn ($o) => $o->balance() > 0)->values() : collect();
        $openInvoices = $customer ? Invoice::where('customer_id', $customer->id)->where('status', 'issued')->get()->filter(fn ($i) => $i->balance() > 0)->values() : collect();

        return view('orders.payments.form', [
            'pagetitle'    => 'Record payment',
            'customer'     => $customer,
            'order'        => $order,
            'invoice'      => $invoice,
            'openOrders'   => $openOrders,
            'openInvoices' => $openInvoices,
            'customers'    => $customer ? collect() : Customer::active()->orderBy('first_name')->limit(1000)->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number']),
            'suggested'    => $this->suggested($order, $invoice),
        ]);
    }

    /** Invoice balance; for an order the rest of the deposit if not reached, else its balance. */
    protected function suggested(?Order $order, ?Invoice $invoice): ?float
    {
        if ($invoice) {
            return $invoice->balance();
        }
        if (! $order) {
            return null;
        }
        $depositLeft = $order->deposit_required - $order->paid();

        return max(0, round($depositLeft > 0 ? min($depositLeft, $order->balance()) : $order->balance(), 2));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'amount'      => 'required|numeric|min:0.01|max:999999999',
            'method'      => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference'   => 'nullable|string|max:120',
            'received_at' => 'nullable|date|before_or_equal:now',
            'notes'       => 'nullable|string|max:2000',
            'apply_to'    => 'nullable|string',      // order:ID | invoice:ID | auto | none
            'confirm_duplicate' => 'nullable|boolean',
        ]);
        [$kind, $id] = array_pad(explode(':', (string) ($data['apply_to'] ?? 'auto'), 2), 2, null);
        $data['order_id'] = $kind === 'order' ? (int) $id : null;
        $data['invoice_id'] = $kind === 'invoice' ? (int) $id : null;
        $data['auto_apply'] = $kind === 'auto';
        if (! empty($data['received_at'])) {
            $data['received_at'] = \Illuminate\Support\Carbon::parse($data['received_at'], CurrentTenant::get()->timezone)->utc();
        }
        $payment = $this->billing->recordPayment($data);

        return redirect()->route('payments.show', $payment)->with('success', 'Payment recorded — receipt ' . $payment->number . '.');
    }

    public function show(Payment $payment): View
    {
        $payment->load('customer', 'allocations.order', 'allocations.invoice', 'refunds.requester', 'refunds.approver', 'receiver', 'branch');
        $openOrders = Order::where('customer_id', $payment->customer_id)->whereNotIn('status', ['draft', 'cancelled'])->get()->filter(fn ($o) => $o->balance() > 0);
        $paidOrders = $payment->allocations->whereNotNull('order_id')->pluck('order')->merge(
            $payment->allocations->whereNotNull('invoice_id')->pluck('invoice.order')->filter())->unique('id');

        return view('orders.payments.show', [
            'pagetitle'  => $payment->number,
            'payment'    => $payment,
            'house'      => CurrentTenant::get(),
            'openOrders' => $openOrders,
            'paidOrders' => $paidOrders,
            'refundableCredit' => $this->billing->refundable($payment),
            'tz'         => CurrentTenant::get()->timezone,
        ]);
    }

    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'order_id'   => ['nullable', Rule::exists('orders', 'id')->where('tenant_id', $tenant)],
            'invoice_id' => ['nullable', Rule::exists('invoices', 'id')->where('tenant_id', $tenant)],
            'amount'     => 'nullable|numeric|min:0.01',
        ]);
        $this->billing->allocate($payment, ! empty($data['order_id']) ? Order::find($data['order_id']) : null,
            ! empty($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null, isset($data['amount']) ? (float) $data['amount'] : null);

        return back()->with('success', 'Credit applied.');
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->billing->reverse($payment, $data['reason'], $request->user());

        return back()->with('success', 'Payment reversed. The orders it paid are owed again.');
    }

    public function requestRefund(Request $request, Payment $payment): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'method'    => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reason'    => 'required|string|max:255',
            'reference' => 'nullable|string|max:120',
            'order_id'  => ['nullable', Rule::exists('orders', 'id')->where('tenant_id', $tenant)],
        ]);
        $this->billing->requestRefund($payment, $data);

        return back()->with('success', 'Refund requested. It is paid once approved.');
    }

    public function approveRefund(Request $request, Refund $refund): RedirectResponse
    {
        $this->billing->approveRefund($refund, $request->user());

        return back()->with('success', 'Refund approved — give the customer their money and keep the reference.');
    }

    public function rejectRefund(Request $request, Refund $refund): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->billing->rejectRefund($refund, $request->user(), $data['reason']);

        return back()->with('success', 'Refund rejected.');
    }
}
