<?php

namespace App\Http\Controllers\Sites;

use App\Models\PaymentIntent;
use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Services\Store\ShopOrderService;
use App\Services\Store\StorePaymentService;
use App\Services\Store\StoreSettings;
use App\Services\Store\WhatsApp;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Checkout, the private order page (pay, send a transfer receipt, WhatsApp) and card-payment returns. */
class CheckoutController extends StorefrontController
{
    public function show(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $account = $this->auth->account($t);
            if (! $account && ! StoreSettings::get($t, 'guest_checkout')) {
                return redirect()->to($this->base($request, $t) . '/account/login?next=checkout')->with('toast', 'Sign in or create an account to check out.');
            }
            $cart = $this->carts->cart($request, $t, $account);
            $address = $account?->addresses->first();
            $summary = $this->carts->summary($t, $cart, ['state' => old('state', $address?->state)]);
            if (! $summary['lines']) {
                return redirect()->to($this->base($request, $t) . '/cart');
            }

            return $this->page($request, $t, 'sites.checkout', [
                'summary'  => $summary,
                'methods'  => $this->store->methods($t),
                'pickups'  => $this->store->pickupBranches($t),
                'delivers' => (bool) StoreSettings::get($t, 'delivery.enabled'),
                'states'   => ($t->currency_code ?: 'NGN') === 'NGN' ? collect(json_decode((string) @file_get_contents(public_path('states_lgas.json')), true) ?: [])->pluck('state')->all() : [],
                'address'  => $address,
            ]);
        }, true);
    }

    /** Totals for the chosen delivery/pickup and state (checkout updates as the shopper types). */
    public function quote(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $cart = $this->carts->cart($request, $t, $this->auth->account($t));
            $s = $this->carts->summary($t, $cart, ['fulfilment' => $request->input('fulfilment'), 'state' => $request->input('state'),
                'email' => $request->input('email'), 'phone' => $request->input('phone')]);
            $cur = $s['currency'];

            return response()->json([
                'subtotal' => Money::format($s['subtotal'], $cur), 'discount' => $s['discount'] > 0 ? '-' . Money::format($s['discount'], $cur) : null,
                'shipping' => $s['fulfilment'] === 'pickup' ? 'Free (pickup)' : ($s['delivery'] ? ($s['shipping'] > 0 ? Money::format($s['shipping'], $cur)
                    : (! empty($s['delivery']['to_agree']) ? 'Confirmed after ordering' : 'Free')) : 'Not available to this state'),
                'shipping_label' => $s['delivery']['label'] ?? null, 'eta' => $s['delivery']['eta'] ?? null,
                'deliverable' => $s['fulfilment'] === 'pickup' || (bool) $s['delivery'],
                'total' => Money::format($s['total'], $cur), 'coupon_error' => $s['coupon_error'],
            ]);
        }, true);
    }

    public function place(Request $request, string $site, ShopOrderService $orders, StorePaymentService $payments): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $orders, $payments) {
            $account = $this->auth->account($t);
            if (! $account && ! StoreSettings::get($t, 'guest_checkout')) {
                return redirect()->to($this->base($request, $t) . '/account/login?next=checkout');
            }
            $data = $request->validate([
                'first_name'       => 'required|string|max:80',
                'last_name'        => 'nullable|string|max:80',
                'email'            => [($request->input('payment_method') === 'whatsapp' ? 'nullable' : 'required'), 'email', 'max:160'],
                'phone'            => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
                'fulfilment'       => 'required|in:delivery,pickup',
                'address_line1'    => 'required_if:fulfilment,delivery|nullable|string|max:190',
                'address_line2'    => 'nullable|string|max:190',
                'city'             => 'required_if:fulfilment,delivery|nullable|string|max:80',
                'state'            => 'nullable|string|max:80',
                'country'          => 'nullable|string|max:80',
                'postal_code'      => 'nullable|string|max:20',
                'pickup_branch_id' => 'required_if:fulfilment,pickup|nullable|integer',
                'payment_method'   => 'required|string|in:' . implode(',', array_keys(ShopOrder::PAYMENT_METHODS)),
                'customer_note'    => 'nullable|string|max:1000',
                'save_address'     => 'nullable|boolean',
                'agree'            => 'accepted',
            ], [
                'phone.regex' => 'Enter a valid phone number.', 'agree.accepted' => 'Please accept the terms to place your order.',
                'address_line1.required_if' => 'Enter the delivery address.', 'city.required_if' => 'Enter the town or city.',
                'pickup_branch_id.required_if' => 'Choose where to collect your order.',
            ]);
            $cart = $this->carts->cart($request, $t, $account);
            if (! $cart) {
                return redirect()->to($this->base($request, $t) . '/cart');
            }
            $order = $orders->checkout($t, $cart, $data, $account, $request);

            if ($account && $request->boolean('save_address') && $data['fulfilment'] === 'delivery' && ! $account->addresses()->where('line1', $data['address_line1'])->exists()) {
                $account->addresses()->create(['name' => trim($data['first_name'] . ' ' . ($data['last_name'] ?? '')), 'phone' => $data['phone'],
                    'line1' => $data['address_line1'], 'line2' => $data['address_line2'] ?? null, 'city' => $data['city'], 'state' => $data['state'] ?? null,
                    'country' => $data['country'] ?? null, 'postal_code' => $data['postal_code'] ?? null, 'is_default' => ! $account->addresses()->exists()]);
            }
            $page = $this->base($request, $t) . '/order/' . $order->token;

            if (in_array($order->payment_method, ['paystack', 'stripe'], true)) {
                try {
                    return redirect()->away($this->startCard($request, $t, $order, $order->payment_method, $payments));
                } catch (ValidationException $e) {
                    return redirect()->to($page)->with('toast_error', collect($e->errors())->flatten()->first() . ' Your order is saved — you can try again or pay another way.');
                }
            }

            return redirect()->to($page)->with('placed', true)->with('open_whatsapp', $order->payment_method === 'whatsapp');
        }, true);
    }

    /** The shopper's private order page (link in e-mails and WhatsApp). */
    public function order(Request $request, string $site, string $token): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $token) {
            $order = ShopOrder::with(['items', 'pickupBranch', 'events' => fn ($q) => $q->where('customer_visible', true)])->where('token', $token)->first();
            abort_unless($order, 404);
            $url = $this->abs($request, $t, 'order/' . $order->token);
            $methods = collect($this->store->methods($t))->only(['paystack', 'stripe'])->all();

            return $this->page($request, $t, 'sites.order', [
                'order'   => $order,
                'bank'    => StoreSettings::get($t, 'bank'),
                'cardMethods' => $methods,
                'waOrder' => WhatsApp::link(WhatsApp::houseNumber($t), WhatsApp::orderMessage($order, $t, $url)),
                'orderUrl'=> $url,
            ]);
        });
    }

    /** Pay (or try again) by card for what is still owed. */
    public function pay(Request $request, string $site, string $token, StorePaymentService $payments): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $token, $payments) {
            $order = ShopOrder::where('token', $token)->firstOrFail();
            $method = (string) $request->input('method');
            $page = $this->base($request, $t) . '/order/' . $order->token;
            if (! isset($this->store->methods($t)[$method]) || ! in_array($method, ['paystack', 'stripe'], true)) {
                return redirect()->to($page)->with('toast_error', 'Choose a card payment option.');
            }
            try {
                return redirect()->away($this->startCard($request, $t, $order, $method, $payments));
            } catch (ValidationException $e) {
                return redirect()->to($page)->with('toast_error', collect($e->errors())->flatten()->first());
            }
        }, true);
    }

    protected function startCard(Request $request, Tenant $t, ShopOrder $order, string $method, StorePaymentService $payments): string
    {
        if ($method === 'stripe') {
            return $payments->startStripe($t, $order,
                $this->abs($request, $t, 'checkout/stripe?order=' . $order->token . '&session_id={CHECKOUT_SESSION_ID}'),
                $this->abs($request, $t, 'order/' . $order->token . '?cancelled=1'));
        }

        return $payments->startPaystack($t, $order, $this->abs($request, $t, 'checkout/paystack'));
    }

    /** Paystack sends the shopper here; the payment is verified with Paystack before showing "paid". */
    public function paystackReturn(Request $request, string $site, StorePaymentService $payments): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $payments) {
            $ref = (string) $request->query('reference', $request->query('trxref', ''));
            $intent = $ref !== '' ? PaymentIntent::where('provider', 'paystack')->where('reference', $ref)->whereNotNull('shop_order_id')->first() : null;
            abort_unless($intent, 404);

            return $this->afterCard($request, $t, $intent, $payments);
        });
    }

    public function stripeReturn(Request $request, string $site, StorePaymentService $payments): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $payments) {
            $order = ShopOrder::where('token', (string) $request->query('order'))->firstOrFail();
            $intent = PaymentIntent::where('provider', 'stripe')->where('shop_order_id', $order->id)
                ->where('provider_reference', (string) $request->query('session_id'))->first();
            abort_unless($intent, 404);

            return $this->afterCard($request, $t, $intent, $payments);
        });
    }

    protected function afterCard(Request $request, Tenant $t, PaymentIntent $intent, StorePaymentService $payments): Response
    {
        try {
            $intent = $payments->confirm($intent);
        } catch (\Throwable) {
            $intent = $intent->fresh();
        }
        $order = ShopOrder::findOrFail($intent->shop_order_id);
        $page = $this->base($request, $t) . '/order/' . $order->token;

        return match ($intent->status) {
            'succeeded' => redirect()->to($page)->with('placed', true)->with('toast', 'Payment received — thank you!'),
            'failed'    => redirect()->to($page)->with('toast_error', 'The payment did not go through. You can try again or choose another way to pay.'),
            default     => redirect()->to($page)->with('toast', 'We are waiting for the payment provider to confirm your payment. This page will update.'),
        };
    }

    /** Bank transfer receipt from the shopper. */
    public function proof(Request $request, string $site, string $token, ShopOrderService $orders): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $token, $orders) {
            $order = ShopOrder::where('token', $token)->firstOrFail();
            $data = $request->validate([
                'proof' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
                'reference' => 'nullable|string|max:120',
            ]);
            $orders->uploadProof($order, $request->file('proof'), $data['reference'] ?? null);

            return redirect()->to($this->base($request, $t) . '/order/' . $order->token)->with('toast', 'Thank you — we will confirm your payment shortly.');
        });
    }

    public function cancel(Request $request, string $site, string $token, ShopOrderService $orders): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $token, $orders) {
            $order = ShopOrder::where('token', $token)->firstOrFail();
            try {
                $orders->cancel($order, 'Cancelled by the customer' . ($request->filled('reason') ? ': ' . mb_substr((string) $request->input('reason'), 0, 200) : ''), null, true);
            } catch (ValidationException $e) {
                return back()->with('toast_error', collect($e->errors())->flatten()->first());
            }

            return redirect()->to($this->base($request, $t) . '/order/' . $order->token)->with('toast', 'Your order was cancelled.');
        });
    }
}
