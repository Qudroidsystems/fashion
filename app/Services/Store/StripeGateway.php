<?php

namespace App\Services\Store;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Stripe Checkout (https://docs.stripe.com/api/checkout/sessions) with the house's own
 * Stripe account. Keys are stored encrypted in the store settings. Like Paystack, a
 * payment only counts after we ask Stripe for the session's real state.
 */
class StripeGateway
{
    public const BASE = 'https://api.stripe.com/v1';

    public function __construct(protected Tenant $tenant)
    {
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function enabled(): bool
    {
        return $this->secret() !== null;
    }

    public function secret(): ?string
    {
        return StoreSettings::secret($this->tenant, 'stripe.secret_key');
    }

    public function webhookSecret(): ?string
    {
        return StoreSettings::secret($this->tenant, 'stripe.webhook_secret');
    }

    /** Amount in the currency's smallest unit. */
    public static function minor(float $amount, string $currency): int
    {
        return in_array(strtoupper($currency), config('store.zero_decimal_currencies', []), true)
            ? (int) round($amount) : (int) round($amount * 100);
    }

    /** Start a hosted checkout. Returns Stripe's session (id, url, …). */
    public function createSession(string $reference, float $amount, string $currency, ?string $email, string $description, string $successUrl, string $cancelUrl, array $metadata = []): array
    {
        $payload = [
            'mode'                => 'payment',
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'client_reference_id' => $reference,
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => ['currency' => strtolower($currency), 'unit_amount' => self::minor($amount, $currency), 'product_data' => ['name' => $description]],
            ]],
            'metadata'            => ['reference' => $reference] + $metadata,
            'payment_intent_data' => ['metadata' => ['reference' => $reference] + $metadata],
        ];
        if ($email) {
            $payload['customer_email'] = $email;
        }
        $res = Http::withToken($this->secret())->asForm()->acceptJson()->timeout(20)->post(self::BASE . '/checkout/sessions', $payload);
        if (! $res->successful() || ! $res->json('id')) {
            throw ValidationException::withMessages(['stripe' => 'Stripe did not accept the payment request: ' . ($res->json('error.message') ?? 'HTTP ' . $res->status())]);
        }

        return $res->json();
    }

    /** What Stripe says about a checkout session (payment_status: paid|unpaid|no_payment_required, amount_total, currency). */
    public function retrieveSession(string $sessionId): array
    {
        try {
            $res = Http::withToken($this->secret())->acceptJson()->timeout(20)->get(self::BASE . '/checkout/sessions/' . rawurlencode($sessionId));
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return ['payment_status' => 'unknown'];
        }
        if (! $res->successful()) {
            return ['payment_status' => 'unknown', 'message' => $res->json('error.message') ?? 'HTTP ' . $res->status()];
        }

        return $res->json();
    }

    /** Stripe-Signature: t=timestamp,v1=HMAC-SHA256("t.payload") with the endpoint's signing secret. */
    public function validSignature(string $payload, ?string $header, int $tolerance = 300): bool
    {
        $secret = $this->webhookSecret();
        if (! $secret || ! $header) {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = (string) $v;
            }
        }
        if (! $t || ! $sigs || abs(time() - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }

        return false;
    }
}
