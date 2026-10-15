<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff side of online payment links (SRS FR-ORD-007). */
class PaymentLinkController extends Controller
{
    public function __construct(protected OnlinePaymentService $online)
    {
        $this->middleware('permission:Record payment');
    }

    public function forOrder(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['amount' => 'nullable|numeric|min:1']);

        return $this->flash($this->online->createLink($order, null, isset($data['amount']) ? (float) $data['amount'] : null));
    }

    public function forInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['amount' => 'nullable|numeric|min:1']);

        return $this->flash($this->online->createLink(null, $invoice, isset($data['amount']) ? (float) $data['amount'] : null));
    }

    public function check(PaymentIntent $intent): RedirectResponse
    {
        $intent = $this->online->confirm($intent);

        return back()->with('success', match ($intent->status) {
            'succeeded' => 'Paid — receipt ' . $intent->payment?->number . ' recorded.',
            'failed', 'abandoned' => 'Paystack says this payment did not go through.',
            default => 'Not paid yet.',
        });
    }

    protected function flash(PaymentIntent $intent): RedirectResponse
    {
        $intent->load('customer');

        return back()->with('payment_link', [
            'url' => $intent->authorization_url, 'amount' => $intent->amount, 'reference' => $intent->reference,
            'customer' => $intent->customer->first_name, 'phone' => $intent->customer->whatsapp ?: $intent->customer->phone,
        ]);
    }
}
