<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Orders\BillingService;
use App\Support\Money;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Online payments (SRS FR-ORD-007/008, FR-ADM-004, scenario 36).
 *
 *  - A payment link is a PaymentIntent with a unique reference.
 *  - Webhooks must carry a valid signature; each one is stored once
 *    (webhook_events unique key), so a replay is ignored.
 *  - Whatever arrives (webhook or the customer coming back), the payment is
 *    confirmed only after asking Paystack to verify it, and only once.
 */
class OnlinePaymentService
{
    public function __construct(protected BillingService $billing)
    {
    }

    public function createLink(?Order $order, ?Invoice $invoice, ?float $amount = null): PaymentIntent
    {
        $tenant = CurrentTenant::require();
        $gateway = PaystackGateway::for($tenant);
        if (! $gateway->enabled()) {
            throw ValidationException::withMessages(['paystack' => 'Online payments are not set up. Add your Paystack keys in House settings.']);
        }
        $customer = $invoice?->customer ?? $order?->customer;
        $due = $invoice ? $invoice->balance() : ($order ? max(0, $order->balance()) : 0);
        $amount = round($amount ?? $due, 2);
        if ($amount <= 0 || $amount > $due + 0.005) {
            throw ValidationException::withMessages(['amount' => 'The link amount must be between ' . Money::format(0.01) . ' and ' . Money::format($due) . '.']);
        }

        $intent = PaymentIntent::create([
            'provider' => 'paystack', 'reference' => 'FH-' . Str::upper(Str::random(6)) . '-' . now()->format('ymdHis'),
            'customer_id' => $customer->id, 'order_id' => $order?->id ?? $invoice?->order_id, 'invoice_id' => $invoice?->id,
            'amount' => $amount, 'currency' => $tenant->currency_code ?: 'NGN', 'status' => 'pending', 'created_by' => auth()->id(),
        ]);
        $email = $customer->email ?: 'customer-' . Str::lower($customer->public_id) . '@noemail.invalid';
        $data = $gateway->initialize($intent->reference, $amount, $intent->currency, $email, route('pay.return'), [
            'tenant' => $tenant->public_id, 'intent' => $intent->public_id,
            'custom_fields' => [['display_name' => 'For', 'variable_name' => 'for', 'value' => $invoice?->number ?? $order?->number]],
        ]);
        $intent->update(['authorization_url' => $data['authorization_url'] ?? null]);
        AuditLogger::log('payment.link_created', $order ?? $invoice, after: ['reference' => $intent->reference, 'amount' => $amount]);

        return $intent;
    }

    /**
     * Verify with Paystack and, if paid, record the payment exactly once.
     * Safe to call from the webhook, the return page and a manual "check" button.
     */
    public function confirm(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->status === 'succeeded') {
            return $intent;
        }
        $gateway = PaystackGateway::for(CurrentTenant::require());
        $data = $gateway->verify($intent->reference);
        $status = $data['status'] ?? 'unknown';

        return DB::transaction(function () use ($intent, $data, $status) {
            $intent = PaymentIntent::lockForUpdate()->findOrFail($intent->id);
            if ($intent->status === 'succeeded') {
                return $intent;   // another delivery got here first
            }
            $intent->forceFill(['provider_status' => $status, 'provider_payload' => $data, 'verified_at' => now()]);

            if ($status === 'success') {
                $paidKobo = (int) ($data['amount'] ?? 0);
                if ($paidKobo !== (int) round($intent->amount * 100) || strtoupper($data['currency'] ?? '') !== strtoupper($intent->currency)) {
                    $intent->save();
                    Log::warning('Paystack amount/currency mismatch', ['reference' => $intent->reference, 'paid' => $paidKobo, 'currency' => $data['currency'] ?? null]);
                    throw ValidationException::withMessages(['paystack' => 'Paystack reports a different amount for this payment. Check it in your Paystack dashboard.']);
                }
                $channel = $data['channel'] ?? null;
                $payment = $this->billing->recordPayment([
                    'customer_id' => $intent->customer_id, 'amount' => $intent->amount,
                    'method' => in_array($channel, ['bank', 'bank_transfer', 'ussd', 'dedicated_nuban'], true) ? 'bank_transfer' : 'card',
                    'reference' => $intent->reference, 'channel' => 'online', 'confirm_duplicate' => true,
                    'invoice_id' => $intent->invoice_id, 'order_id' => $intent->invoice_id ? null : $intent->order_id,
                    'received_at' => ! empty($data['paid_at']) ? \Illuminate\Support\Carbon::parse($data['paid_at']) : now(),
                    'notes' => 'Paid online with Paystack' . ($channel ? ' (' . $channel . ')' : ''),
                ]);
                $intent->forceFill(['status' => 'succeeded', 'payment_id' => $payment->id, 'paid_at' => $payment->received_at, 'provider_channel' => $channel])->save();
                AuditLogger::log('payment.online_confirmed', $payment, after: ['reference' => $intent->reference, 'amount' => $intent->amount]);
            } elseif (in_array($status, ['failed', 'abandoned', 'reversed'], true)) {
                $intent->forceFill(['status' => $status === 'abandoned' ? 'abandoned' : 'failed'])->save();
            } else {
                $intent->save();   // still pending / unknown: try again later
            }

            return $intent;
        });
    }

    /**
     * Webhook from Paystack for one fashion house (URL carries the tenant).
     * Returns [httpStatus, message]. Invalid signatures are rejected; replays are ignored.
     */
    public function handleWebhook(Tenant $tenant, string $rawBody, ?string $signature): array
    {
        $gateway = PaystackGateway::for($tenant);
        $payload = json_decode($rawBody, true) ?: [];
        $event = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];
        $key = $event . ':' . ($data['id'] ?? hash('sha256', $rawBody));

        if (! $gateway->validSignature($rawBody, $signature)) {
            Log::warning('Rejected Paystack webhook with a bad signature', ['tenant' => $tenant->public_id]);

            return [401, 'Invalid signature'];
        }

        try {
            $record = WebhookEvent::create([
                'tenant_id' => $tenant->id, 'provider' => 'paystack', 'event_key' => $key, 'event_type' => $event,
                'signature_valid' => true, 'status' => 'received', 'payload' => $payload,
            ]);
        } catch (QueryException) {
            return [200, 'Already processed'];   // replay or duplicate delivery (scenario 36)
        }

        return CurrentTenant::run($tenant, function () use ($record, $event, $data) {
            if ($event !== 'charge.success') {
                $record->update(['status' => 'ignored', 'message' => 'Event not used']);

                return [200, 'Ignored'];
            }
            $intent = PaymentIntent::where('reference', $data['reference'] ?? '')->first();
            if (! $intent) {
                $record->update(['status' => 'ignored', 'message' => 'Unknown reference']);

                return [200, 'Unknown reference'];
            }
            try {
                $intent = $this->confirm($intent);
                if ($intent->status === 'pending') {
                    // Paystack could not be reached to verify: forget this delivery so its retry is processed.
                    $record->delete();

                    return [503, 'Could not verify yet'];
                }
                $record->update(['status' => 'processed', 'message' => 'Intent ' . $intent->status]);

                return [200, 'OK'];
            } catch (\Throwable $e) {
                $record->update(['status' => 'failed', 'message' => Str::limit($e->getMessage(), 250)]);

                return [200, 'Recorded for review'];   // Paystack must not keep retrying a mismatch forever
            }
        });
    }
}
