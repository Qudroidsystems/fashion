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
 */
class PaystackGateway
{
    public const BASE = 'https://api.paystack.co';

    public function __construct(protected Tenant $tenant)
    {
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function enabled(): bool
    {
        return (bool) $this->tenant->setting('paystack.enabled') && $this->secret() !== null;
    }

    public function secret(): ?string
    {
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

    /** x-paystack-signature = HMAC-SHA512 of the raw body with the secret key. */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->secret();

        return $secret && $signature && hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }
}
