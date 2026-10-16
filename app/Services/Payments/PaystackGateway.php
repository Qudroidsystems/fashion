<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Paystack (https://paystack.com/docs/api). Each fashion house uses its own
 * Paystack account; the secret key is stored encrypted in the tenant settings
 * and never sent back to the browser (SRS FR-ADM-003).
 *
 * PaystackGateway::platform() uses the platform's own account (config
 * billing.paystack) for house subscriptions.
 */
class PaystackGateway
{
    public const BASE = 'https://api.paystack.co';

    public function __construct(protected ?Tenant $tenant, protected ?string $platformSecret = null)
    {
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** The platform's own Paystack account (subscriptions). */
    public static function platform(): self
    {
        return new self(null, config('billing.paystack.secret_key') ?: null);
    }

    public function enabled(): bool
    {
        if (! $this->tenant) {
            return $this->platformSecret !== null;
        }

        return (bool) $this->tenant->setting('paystack.enabled') && $this->secret() !== null;
    }

    public function secret(): ?string
    {
        if (! $this->tenant) {
            return $this->platformSecret;
        }
        $enc = $this->tenant->setting('paystack.secret_key');
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Start a hosted checkout. Amount in naira (sent to Paystack in kobo). */
    public function initialize(string $reference, float $amount, string $currency, string $email, string $callbackUrl, array $metadata = []): array
    {
        $res = Http::withToken($this->secret())->acceptJson()->timeout(20)->post(self::BASE . '/transaction/initialize', [
            'reference'    => $reference,
            'amount'       => (int) round($amount * 100),
            'currency'     => $currency,
            'email'        => $email,
            'callback_url' => $callbackUrl,
            'metadata'     => $metadata,
        ]);
        if (! $res->successful() || ! $res->json('status')) {
            throw ValidationException::withMessages(['paystack' => 'Paystack did not accept the payment request: ' . ($res->json('message') ?? 'HTTP ' . $res->status())]);
        }

        return $res->json('data');   // authorization_url, access_code, reference
    }

    /** Ask Paystack what really happened (never trust the redirect or the webhook alone). */
    public function verify(string $reference): array
    {
        $res = Http::withToken($this->secret())->acceptJson()->timeout(20)->get(self::BASE . '/transaction/verify/' . rawurlencode($reference));
        if (! $res->successful() || ! $res->json('status')) {
            return ['status' => 'unknown', 'message' => $res->json('message') ?? 'HTTP ' . $res->status()];
        }

        return $res->json('data');   // status: success|failed|abandoned|ongoing…, amount (kobo), currency, channel, paid_at, id
    }

    /**
     * Charge a saved card again (subscription renewals). Returns Paystack's
     * transaction data; status "success" means the money is taken.
     */
    public function chargeAuthorization(string $reference, float $amount, string $currency, string $email, string $authorizationCode, array $metadata = []): array
    {
        try {
            $res = Http::withToken($this->secret())->acceptJson()->timeout(30)->post(self::BASE . '/transaction/charge_authorization', [
                'reference'          => $reference,
                'amount'             => (int) round($amount * 100),
                'currency'           => $currency,
                'email'              => $email,
                'authorization_code' => $authorizationCode,
                'metadata'           => $metadata,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return ['status' => 'failed', 'gateway_response' => 'Could not reach Paystack; will try again'];
        }
        if (! $res->successful() || ! $res->json('status')) {
            return ['status' => 'failed', 'gateway_response' => $res->json('message') ?? 'HTTP ' . $res->status()];
        }

        return $res->json('data');
    }

    /** x-paystack-signature = HMAC-SHA512 of the raw body with the secret key. */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->secret();

        return $secret && $signature && hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }
}
