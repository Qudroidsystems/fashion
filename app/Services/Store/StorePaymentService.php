<?php

namespace App\Services\Store;

use App\Models\PaymentIntent;
use App\Models\ShopOrder;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaystackGateway;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Card payments for online orders through the house's own Paystack or Stripe account.
 * The browser coming back proves nothing: every payment is verified with the provider,
 * checked for amount and currency, and recorded once (the intent row is locked).
 * Paystack webhooks arrive through the existing per-house Paystack webhook; Stripe has its own.
 */
class StorePaymentService
{
    public function __construct(protected ShopOrderService $orders, protected StoreSettings $settings)
    {
    }

    /** Start a Paystack checkout for what is still owed; returns the URL to send the shopper to. */
    public function startPaystack(Tenant $tenant, ShopOrder $shop, string $callbackUrl): string
    {
        $gateway = PaystackGateway::for($tenant);
        if (! $gateway->enabled()) {
            throw ValidationException::withMessages(['payment_method' => 'Card payments are not available right now. Please choose another way to pay.']);
        }
        $intent = $this->intent($shop, 'paystack');
        $email = $shop->email ?: 'shopper-' . Str::lower($shop->public_id) . '@noemail.invalid';
        $data = $gateway->initialize($intent->reference, $intent->amount, $intent->currency, $email, $callbackUrl, [
            'tenant' => $tenant->public_id, 'shop_order' => $shop->public_id,
            'custom_fields' => [['display_name' => 'Order', 'variable_name' => 'order', 'value' => $shop->number]],
        ]);
        $intent->update(['authorization_url' => $data['authorization_url'] ?? null]);

        return (string) $intent->authorization_url;
    }

    /** Start a Stripe Checkout session; returns its URL. {CHECKOUT_SESSION_ID} in $successUrl is filled by Stripe. */
    public function startStripe(Tenant $tenant, ShopOrder $shop, string $successUrl, string $cancelUrl): string
    {
        $gateway = StripeGateway::for($tenant);
        if (! $gateway->enabled()) {
            throw ValidationException::withMessages(['payment_method' => 'Card payments are not available right now. Please choose another way to pay.']);
        }
        $intent = $this->intent($shop, 'stripe');
        $session = $gateway->createSession($intent->reference, $intent->amount, $intent->currency, $shop->email,
            $tenant->name . ' — order ' . $shop->number, $successUrl, $cancelUrl, ['tenant' => $tenant->public_id, 'shop_order' => $shop->public_id]);
        $intent->update(['provider_reference' => $session['id'], 'authorization_url' => $session['url'] ?? null]);

        return (string) $intent->authorization_url;
    }

    protected function intent(ShopOrder $shop, string $provider): PaymentIntent
    {
        if (! $shop->canPayOnline()) {
            throw ValidationException::withMessages(['payment_method' => 'This order has nothing left to pay.']);
        }

        return PaymentIntent::create([
            'provider' => $provider, 'reference' => 'WEB-' . Str::upper(Str::random(8)) . '-' . now()->format('ymdHis'),
            'customer_id' => $shop->customer_id, 'order_id' => $shop->order_id, 'shop_order_id' => $shop->id,
            'amount' => $shop->balance(), 'currency' => $shop->currency, 'status' => 'pending', 'created_by' => auth()->id(),
        ]);
    }

    /**
     * Ask the provider what happened and, if paid, record the payment exactly once.
     * Safe from the webhook, the return page and the expiry job.
     */
    public function confirm(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status === 'succeeded') {
            return $intent;
        }
        $tenant = CurrentTenant::require();
        [$state, $paidMinor, $currency, $channel, $paidAt, $payload] = $this->verify($tenant, $intent);

        return DB::transaction(function () use ($intent, $state, $paidMinor, $currency, $channel, $paidAt, $payload) {
            $intent = PaymentIntent::lockForUpdate()->findOrFail($intent->id);
            if ($intent->status === 'succeeded') {
                return $intent;
            }
            $intent->forceFill(['provider_status' => $state, 'provider_payload' => $payload, 'verified_at' => now()]);
            if ($state === 'paid') {
                $expected = $intent->provider === 'stripe' ? StripeGateway::minor($intent->amount, $intent->currency) : (int) round($intent->amount * 100);
                if ($paidMinor !== $expected || strtoupper((string) $currency) !== strtoupper($intent->currency)) {
                    $intent->save();
                    Log::warning('Online shop payment amount/currency mismatch', ['reference' => $intent->reference, 'paid' => $paidMinor, 'currency' => $currency]);
                    throw ValidationException::withMessages(['payment' => 'The provider reports a different amount for this payment. Check it in your dashboard.']);
                }
                $shop = ShopOrder::findOrFail($intent->shop_order_id);
                $label = $intent->provider === 'stripe' ? 'Stripe' : 'Paystack';
                $payment = $this->orders->recordPayment($shop, $intent->amount,
                    in_array($channel, ['bank', 'bank_transfer', 'ussd', 'dedicated_nuban'], true) ? 'bank_transfer' : 'card',
                    $intent->reference, null, 'Paid online with ' . $label . ($channel ? ' (' . $channel . ')' : ''), 'online', $paidAt ?? now());
                $intent->forceFill(['status' => 'succeeded', 'payment_id' => $payment->id, 'paid_at' => $payment->received_at, 'provider_channel' => $channel,
                    'customer_id' => $payment->customer_id, 'order_id' => $shop->fresh()->order_id])->save();
                AuditLogger::log('payment.online_confirmed', $payment, after: ['reference' => $intent->reference, 'amount' => $intent->amount, 'shop_order' => $shop->number]);
                DB::afterCommit(fn () => app(StoreNotifier::class)->staff(CurrentTenant::require(), $shop->fresh('items'), 'paid'));
            } elseif ($state === 'failed') {
                $intent->forceFill(['status' => 'failed'])->save();
            } else {
                $intent->save();   // still pending / unknown: check again later
            }

            return $intent;
        });
    }

    /** @return array{0:string,1:int,2:?string,3:?string,4:?Carbon,5:array} state paid|failed|pending, amount in minor units, currency, channel, paid at, payload */
    protected function verify(Tenant $tenant, PaymentIntent $intent): array
    {
        if ($intent->provider === 'stripe') {
            if (! $intent->provider_reference) {
                return ['pending', 0, null, null, null, []];
            }
            $s = StripeGateway::for($tenant)->retrieveSession($intent->provider_reference);
            $state = ($s['payment_status'] ?? null) === 'paid' ? 'paid' : (($s['status'] ?? null) === 'expired' ? 'failed' : 'pending');

            return [$state, (int) ($s['amount_total'] ?? 0), $s['currency'] ?? null, 'card', null,
                collect($s)->only(['id', 'status', 'payment_status', 'amount_total', 'currency', 'payment_intent'])->all()];
        }
        $d = PaystackGateway::for($tenant)->verify($intent->reference);
        $status = $d['status'] ?? 'unknown';
        $state = $status === 'success' ? 'paid' : (in_array($status, ['failed', 'abandoned', 'reversed'], true) ? 'failed' : 'pending');

        return [$state, (int) ($d['amount'] ?? 0), $d['currency'] ?? null, $d['channel'] ?? null,
            ! empty($d['paid_at']) ? Carbon::parse($d['paid_at']) : null, $d];
    }

    /** Stripe webhook for one house. Returns [httpStatus, message]. */
    public function handleStripeWebhook(Tenant $tenant, string $rawBody, ?string $signature): array
    {
        $gateway = StripeGateway::for($tenant);
        if (! $gateway->validSignature($rawBody, $signature)) {
            Log::warning('Rejected Stripe webhook with a bad signature', ['tenant' => $tenant->public_id]);

            return [400, 'Invalid signature'];
        }
        $payload = json_decode($rawBody, true) ?: [];
        $event = $payload['type'] ?? 'unknown';
        try {
            $record = WebhookEvent::create([
                'tenant_id' => $tenant->id, 'provider' => 'stripe', 'event_key' => $payload['id'] ?? hash('sha256', $rawBody), 'event_type' => $event,
                'signature_valid' => true, 'status' => 'received', 'payload' => $payload,
            ]);
        } catch (QueryException) {
            return [200, 'Already processed'];
        }

        return CurrentTenant::run($tenant, function () use ($record, $event, $payload) {
            if (! in_array($event, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                $record->update(['status' => 'ignored', 'message' => 'Event not used']);

                return [200, 'Ignored'];
            }
            $session = $payload['data']['object'] ?? [];
            $intent = PaymentIntent::where('provider', 'stripe')->where(fn ($q) => $q->where('provider_reference', $session['id'] ?? '-')
                ->orWhere('reference', $session['client_reference_id'] ?? '-'))->first();
            if (! $intent) {
                $record->update(['status' => 'ignored', 'message' => 'Unknown session']);

                return [200, 'Unknown session'];
            }
            try {
                $intent = $this->confirm($intent);
                $record->update(['status' => 'processed', 'message' => 'Intent ' . $intent->status]);

                return [200, 'OK'];
            } catch (\Throwable $e) {
                $record->update(['status' => 'failed', 'message' => Str::limit($e->getMessage(), 250)]);

                return [200, 'Recorded for review'];
            }
        });
    }
}
