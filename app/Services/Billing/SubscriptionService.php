<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\BillingNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaystackGateway;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * House subscriptions, paid to the platform's own Paystack account.
 *
 *  - New houses get a trial of the trial plan; when it ends without payment they move to the free plan.
 *  - Choosing a dearer plan (or paying from free/trial) charges now and starts a new period today.
 *  - Choosing a cheaper plan takes effect at the end of the paid period.
 *  - Renewals charge the saved card. If that fails the house keeps its plan for the grace
 *    period, is retried daily, and then moves to the free plan. Nothing is ever deleted.
 *  - The platform super admin can set any plan by hand (e.g. a bank transfer paid offline).
 */
class SubscriptionService
{
    public function __construct(protected Entitlements $entitlements)
    {
    }

    /* ------------------------------- start ------------------------------- */

    public function startTrial(Tenant $tenant): Subscription
    {
        $plan = Plan::where('code', config('billing.trial_plan'))->where('is_active', true)->first();
        if (! $plan) {
            return $this->startFree($tenant);
        }
        $ends = now()->addDays((int) config('tenancy.trial_days', 14));
        $sub = Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $plan->id, 'interval' => 'monthly', 'status' => 'trialing', 'trial_ends_at' => $ends,
            'current_period_start' => now(), 'current_period_end' => null, 'billing_email' => $tenant->email,
        ]);
        $this->syncTenant($sub);

        return $sub;
    }

    public function startFree(Tenant $tenant): Subscription
    {
        $free = Plan::free() ?? throw new \RuntimeException('No free plan is set up.');
        $sub = Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $free->id, 'interval' => 'monthly', 'status' => 'active', 'trial_ends_at' => null,
            'current_period_start' => now(), 'current_period_end' => null, 'billing_email' => $tenant->email,
        ]);
        $this->syncTenant($sub);

        return $sub;
    }

    public function ensure(Tenant $tenant): Subscription
    {
        return Subscription::where('tenant_id', $tenant->id)->first() ?? $this->startFree($tenant);
    }

    /* ------------------------------ changing ------------------------------ */

    /**
     * The owner picks a plan. Returns ['redirect' => Paystack URL] when a
     * payment is needed, or ['message' => …] when the change is done or scheduled.
     */
    public function choose(Tenant $tenant, Plan $plan, string $interval, User $by): array
    {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan' => 'That plan is no longer offered.']);
        }
        $sub = $this->ensure($tenant)->load('plan');
        $current = $sub->plan;
        $paidUp = $sub->status === 'active' && $current->isPaid() && $sub->current_period_end?->isFuture();

        // Same plan: undo a scheduled cancellation or downgrade.
        if ($current->id === $plan->id && $sub->interval === $interval && $sub->status !== 'past_due' && ($sub->status !== 'trialing' || ! $plan->isPaid())) {
            if ($sub->cancel_at_period_end || $sub->pending_plan_id) {
                $sub->update(['cancel_at_period_end' => false, 'pending_plan_id' => null, 'pending_interval' => null]);

                return ['message' => "You stay on {$plan->name}."];
            }

            return ['message' => "You are already on {$plan->name}."];
        }

        // Cheaper (or free) while paid up: switch when the paid time runs out.
        if (! $plan->isPaid() || ($paidUp && $plan->sort_order < $current->sort_order)) {
            if ($paidUp) {
                $sub->update(['pending_plan_id' => $plan->id, 'pending_interval' => $interval, 'cancel_at_period_end' => false]);
                AuditLogger::log('subscription.downgrade_scheduled', $tenant, after: ['plan' => $plan->code, 'from' => $sub->current_period_end->toDateString()], tenantId: $tenant->id);

                return ['message' => "You move to {$plan->name} on " . $sub->current_period_end->format('j M Y') . '. Until then you keep ' . $current->name . '.'];
            }
            $this->moveTo($sub, $plan, $interval, 'Changed by ' . $by->name);

            return ['message' => "You are now on {$plan->name}."];
        }

        // Dearer, or paying for the first time: charge now, new period from today.
        $invoice = $this->invoice($tenant, $plan, $interval, $sub->status === 'past_due' && $current->id === $plan->id ? 'renewal' : ($current->isPaid() ? 'upgrade' : 'new'), $by);
        $gateway = PaystackGateway::platform();
        if (! $gateway->enabled()) {
            throw ValidationException::withMessages(['plan' => 'Online payment for plans is not set up yet. Please contact us to upgrade.']);
        }
        $res = $gateway->initialize($invoice->reference, $invoice->amount, $invoice->currency, $sub->billing_email ?: $by->email,
            route('billing.return'), ['billing_invoice' => $invoice->number, 'tenant' => $tenant->public_id, 'plan' => $plan->code, 'interval' => $interval]);

        return ['redirect' => $res['authorization_url']];
    }

    public function cancel(Tenant $tenant): Subscription
    {
        $sub = $this->ensure($tenant);
        if ($sub->plan->isPaid() && $sub->status === 'active' && $sub->current_period_end) {
            $sub->update(['cancel_at_period_end' => true, 'pending_plan_id' => null, 'pending_interval' => null]);
        }
        AuditLogger::log('subscription.cancel_scheduled', $tenant, tenantId: $tenant->id);

        return $sub;
    }

    /** Platform super admin: set a plan by hand, optionally recording an offline payment. */
    public function adminSet(Tenant $tenant, Plan $plan, string $interval, ?Carbon $until, string $status, float $paid, User $by, ?string $note = null): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $interval, $until, $status, $paid, $by, $note) {
            $sub = $this->ensure($tenant);
            $before = ['plan' => $sub->plan->code, 'status' => $sub->status, 'until' => $sub->endsAt()?->toDateString()];
            $sub->fill([
                'plan_id' => $plan->id, 'interval' => $interval, 'status' => $status, 'cancel_at_period_end' => false,
                'pending_plan_id' => null, 'pending_interval' => null, 'grace_ends_at' => null, 'failure_reason' => null,
                'trial_ends_at' => $status === 'trialing' ? $until : null,
                'current_period_start' => now(), 'current_period_end' => $status === 'trialing' ? null : $until,
            ])->save();
            if ($paid > 0) {
                SubscriptionInvoice::create([
                    'tenant_id' => $tenant->id, 'number' => $this->number(), 'plan_id' => $plan->id, 'interval' => $interval, 'reason' => 'manual',
                    'amount' => $paid, 'currency' => config('billing.paystack.currency', 'NGN'), 'status' => 'paid', 'reference' => 'MAN-' . Str::ulid(),
                    'gateway' => 'manual', 'channel' => $note ? Str::limit($note, 30, '') : 'offline', 'period_start' => now(), 'period_end' => $until,
                    'paid_at' => now(), 'created_by' => $by->id,
                ]);
            }
            $this->syncTenant($sub);
            $this->entitlements->forget();
            AuditLogger::log('subscription.set_by_platform', $tenant, $before, ['plan' => $plan->code, 'status' => $status, 'until' => $until?->toDateString(), 'paid' => $paid], $note, tenantId: $tenant->id);

            return $sub->fresh('plan');
        });
    }

    /* ------------------------------ payments ------------------------------ */

    protected function invoice(Tenant $tenant, Plan $plan, string $interval, string $reason, ?User $by = null): SubscriptionInvoice
    {
        return SubscriptionInvoice::create([
            'tenant_id' => $tenant->id, 'number' => $this->number(), 'plan_id' => $plan->id, 'interval' => $interval, 'reason' => $reason,
            'amount' => $plan->price($interval), 'currency' => config('billing.paystack.currency', 'NGN'), 'status' => 'pending',
            'reference' => 'SUB-' . Str::ulid(), 'gateway' => 'paystack', 'created_by' => $by?->id,
        ]);
    }

    protected function number(): string
    {
        $year = now()->format('Y');
        $n = SubscriptionInvoice::where('number', 'like', "SINV-{$year}-%")->count() + 1;

        return sprintf('SINV-%s-%05d', $year, $n);
    }

    /**
     * Check a payment with Paystack and apply it. Safe to call any number of
     * times (return page, webhook, renewal): an invoice is applied once.
     */
    public function confirm(string $reference, ?array $verified = null): ?SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::where('reference', $reference)->first();
        if (! $invoice || $invoice->status === 'paid') {
            return $invoice;
        }
        $data = $verified ?? PaystackGateway::platform()->verify($reference);
        $ok = ($data['status'] ?? null) === 'success'
            && (int) ($data['amount'] ?? -1) === (int) round($invoice->amount * 100)
            && strtoupper($data['currency'] ?? '') === strtoupper($invoice->currency);
        if (! $ok) {
            if (in_array($data['status'] ?? null, ['failed', 'abandoned', 'reversed'], true) || (($data['status'] ?? null) === 'success')) {
                $invoice->update(['status' => 'failed', 'failure_reason' => Str::limit($data['gateway_response'] ?? 'Amount or currency did not match', 250)]);
            }

            return $invoice->fresh();
        }

        return DB::transaction(function () use ($invoice, $data) {
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->status === 'paid') {
                return $invoice;
            }
            $sub = Subscription::where('tenant_id', $invoice->tenant_id)->lockForUpdate()->first();
            $start = $invoice->reason === 'renewal' && $sub->current_period_end?->isFuture() ? $sub->current_period_end->copy() : now();
            $end = $invoice->interval === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'channel' => $data['channel'] ?? null, 'period_start' => $start, 'period_end' => $end]);

            $fill = [
                'plan_id' => $invoice->plan_id, 'interval' => $invoice->interval, 'status' => 'active', 'trial_ends_at' => null,
                'current_period_start' => $start, 'current_period_end' => $end, 'cancel_at_period_end' => false,
                'pending_plan_id' => null, 'pending_interval' => null, 'grace_ends_at' => null, 'failure_reason' => null,
            ];
            $auth = $data['authorization'] ?? null;
            if ($auth && ($auth['reusable'] ?? false) && ! empty($auth['authorization_code'])) {
                $fill['authorization'] = Crypt::encryptString(json_encode([
                    'authorization_code' => $auth['authorization_code'], 'email' => $data['customer']['email'] ?? $sub->billing_email,
                ]));
                $fill['card_label'] = trim(ucfirst($auth['brand'] ?? 'Card') . ' •••• ' . ($auth['last4'] ?? '') . (isset($auth['exp_month']) ? ', exp ' . $auth['exp_month'] . '/' . substr((string) ($auth['exp_year'] ?? ''), -2) : ''));
                $fill['billing_email'] = $data['customer']['email'] ?? $sub->billing_email;
            }
            $sub->fill($fill)->save();
            $this->syncTenant($sub);
            $this->entitlements->forget();
            $plan = Plan::find($invoice->plan_id);
            AuditLogger::log('subscription.paid', $sub->tenant, after: ['invoice' => $invoice->number, 'plan' => $plan->code, 'amount' => $invoice->amount, 'until' => $end->toDateString()], tenantId: $sub->tenant_id);
            $this->tellOwners($sub->tenant, "Payment received: {$plan->name} plan",
                'Thank you. We received ' . Money::format($invoice->amount) . " for the {$plan->name} plan. It runs until " . $end->format('j F Y') . '.');

            return $invoice;
        });
    }

    /** Paystack callback to the platform (charge.success). Signature-checked and replay-safe. */
    public function handleWebhook(string $rawBody, ?string $signature): array
    {
        $gateway = PaystackGateway::platform();
        if (! $gateway->validSignature($rawBody, $signature)) {
            Log::warning('Rejected platform Paystack webhook with a bad signature');

            return [401, 'Invalid signature'];
        }
        $payload = json_decode($rawBody, true) ?: [];
        $event = $payload['event'] ?? 'unknown';
        $data = $payload['data'] ?? [];
        try {
            $record = WebhookEvent::create([
                'provider' => 'paystack-billing', 'event_key' => $event . ':' . ($data['id'] ?? hash('sha256', $rawBody)), 'event_type' => $event,
                'signature_valid' => true, 'status' => 'received', 'payload' => $payload,
            ]);
        } catch (QueryException) {
            return [200, 'Already processed'];
        }
        if ($event !== 'charge.success' || ! SubscriptionInvoice::where('reference', $data['reference'] ?? '')->exists()) {
            $record->update(['status' => 'ignored']);

            return [200, 'Ignored'];
        }
        $invoice = $this->confirm($data['reference']);
        if ($invoice->status === 'pending') {
            $record->delete();   // could not verify yet; let Paystack retry

            return [503, 'Could not verify yet'];
        }
        $record->update(['status' => 'processed', 'message' => 'Invoice ' . $invoice->status]);

        return [200, 'OK'];
    }

    /* ------------------------------ renewals ------------------------------ */

    /** Runs hourly: trials ending, renewals due, failed payments retried, grace periods ending, notices. */
    public function run(): array
    {
        $done = ['renewed' => 0, 'failed' => 0, 'to_free' => 0, 'notices' => 0];
        $now = now();
        Subscription::with('plan', 'tenant')->get()->each(function (Subscription $sub) use (&$done, $now) {
            if (! $sub->tenant || in_array($sub->tenant->status, ['suspended', 'cancelled'], true)) {
                return;
            }
            try {
                if ($sub->status === 'trialing' && $sub->trial_ends_at && $sub->trial_ends_at->lte($now)) {
                    $this->moveTo($sub, Plan::free(), 'monthly', 'Trial ended');
                    $this->tellOwners($sub->tenant, 'Your free trial has ended', 'Your house is now on the Free plan. Everything you added is still there. Choose a plan to switch the other features back on.');
                    $done['to_free']++;
                } elseif ($sub->status === 'active' && $sub->current_period_end && $sub->current_period_end->lte($now)) {
                    $done[$this->renew($sub)]++;
                } elseif ($sub->status === 'past_due' && $sub->grace_ends_at && $sub->grace_ends_at->lte($now)) {
                    $this->moveTo($sub, Plan::free(), 'monthly', 'Renewal not paid');
                    $this->tellOwners($sub->tenant, 'Your plan has ended', 'We could not take the renewal payment, so your house is now on the Free plan. Nothing was deleted. Choose a plan to switch the other features back on.');
                    $done['to_free']++;
                } elseif ($sub->status === 'past_due' && $sub->cardAuthorization() && (! $sub->last_notice_at || $sub->last_notice_at->lte($now->copy()->subDay()))) {
                    $done[$this->charge($sub, $sub->plan, $sub->interval) ? 'renewed' : 'failed']++;
                } else {
                    $done['notices'] += $this->notice($sub) ? 1 : 0;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $done;
    }

    protected function renew(Subscription $sub): string
    {
        if ($sub->cancel_at_period_end) {
            $this->moveTo($sub, Plan::free(), 'monthly', 'Cancelled at the end of the period');

            return 'to_free';
        }
        $plan = $sub->pendingPlan ?? $sub->plan;
        $interval = $sub->pending_interval ?? $sub->interval;
        if (! $plan->isPaid()) {
            $this->moveTo($sub, $plan, $interval, 'Scheduled change');

            return 'to_free';
        }

        return $this->charge($sub, $plan, $interval) ? 'renewed' : 'failed';
    }

    /** Charge the saved card for the next period. */
    protected function charge(Subscription $sub, Plan $plan, string $interval): bool
    {
        $auth = $sub->cardAuthorization();
        $gateway = PaystackGateway::platform();
        $invoice = $this->invoice($sub->tenant, $plan, $interval, 'renewal');
        $data = $auth && $gateway->enabled()
            ? $gateway->chargeAuthorization($invoice->reference, $invoice->amount, $invoice->currency, $auth['email'] ?? $sub->billing_email, $auth['authorization_code'],
                ['billing_invoice' => $invoice->number, 'tenant' => $sub->tenant->public_id, 'renewal' => true])
            : ['status' => 'failed', 'gateway_response' => 'No saved card'];
        $invoice->increment('attempts');
        if (($data['status'] ?? null) === 'success') {
            // Renewal starts when the old period ends.
            $sub->update(['plan_id' => $plan->id, 'interval' => $interval]);
            $this->confirm($invoice->reference, $data);

            return true;
        }
        $invoice->update(['status' => 'failed', 'failure_reason' => Str::limit($data['gateway_response'] ?? 'Declined', 250)]);
        $first = $sub->status !== 'past_due';
        $sub->update([
            'status' => 'past_due', 'failure_reason' => $invoice->failure_reason, 'last_notice_at' => now(),
            'grace_ends_at' => $sub->grace_ends_at ?? now()->addDays((int) config('billing.grace_days', 7)),
        ]);
        $this->syncTenant($sub);
        if ($first) {
            $this->tellOwners($sub->tenant, 'We could not renew your plan',
                ($auth ? 'Your card was declined (' . $invoice->failure_reason . ').' : 'There is no saved card to charge.')
                . " You keep the {$plan->name} plan until " . $sub->grace_ends_at->format('j F') . '. Pay from your plan page to keep it.', 'Pay now');
        }

        return false;
    }

    /** Warn the owner a few days before a trial ends, or before a renewal with no saved card. */
    protected function notice(Subscription $sub): bool
    {
        $soon = now()->addDays((int) config('billing.notice_days', 3));
        $end = $sub->endsAt();
        if (! $end || $end->gt($soon) || ($sub->last_notice_at && $sub->last_notice_at->gte(now()->subDays((int) config('billing.notice_days', 3) + 1)))) {
            return false;
        }
        if ($sub->status === 'trialing') {
            $this->tellOwners($sub->tenant, 'Your free trial ends ' . $end->format('j F'), 'Choose a plan before then to keep everything switched on. Otherwise your house moves to the Free plan; nothing is deleted.', 'Choose a plan');
        } elseif ($sub->status === 'active' && $sub->plan->isPaid() && ! $sub->cancel_at_period_end && ! $sub->cardAuthorization()) {
            $this->tellOwners($sub->tenant, "Your {$sub->plan->name} plan renews " . $end->format('j F'), 'There is no saved card, so please pay from your plan page to keep it.', 'Pay now');
        } else {
            return false;
        }
        $sub->update(['last_notice_at' => now()]);

        return true;
    }

    public function moveTo(Subscription $sub, ?Plan $plan, string $interval, string $why): Subscription
    {
        $plan ??= Plan::free();
        $paid = $plan->isPaid();
        $sub->fill([
            'plan_id' => $plan->id, 'interval' => $interval, 'status' => 'active', 'trial_ends_at' => null,
            'current_period_start' => now(), 'current_period_end' => $paid ? $sub->current_period_end : null,
            'cancel_at_period_end' => false, 'pending_plan_id' => null, 'pending_interval' => null, 'grace_ends_at' => null, 'failure_reason' => null,
        ])->save();
        $this->syncTenant($sub);
        $this->entitlements->forget();
        AuditLogger::log('subscription.plan_changed', $sub->tenant, after: ['plan' => $plan->code], description: $why, tenantId: $sub->tenant_id);

        return $sub;
    }

    /** Keep the house's own status in step (the platform's suspended/cancelled always win). */
    public function syncTenant(Subscription $sub): void
    {
        $tenant = $sub->tenant()->first();
        if (! $tenant || in_array($tenant->status, ['suspended', 'cancelled'], true)) {
            return;
        }
        $tenant->forceFill(match ($sub->status) {
            'trialing' => ['status' => 'trial', 'trial_ends_at' => $sub->trial_ends_at, 'grace_ends_at' => null],
            'past_due' => ['status' => 'past_due', 'grace_ends_at' => $sub->grace_ends_at],
            default    => ['status' => 'active', 'trial_ends_at' => null, 'grace_ends_at' => null],
        })->save();
    }

    protected function tellOwners(Tenant $tenant, string $title, string $body, ?string $action = 'See your plan'): void
    {
        $owners = User::whereIn('id', TenantMembership::withoutTenantScope()->where('tenant_id', $tenant->id)->where('is_owner', true)->where('status', 'active')->pluck('user_id'))->get();
        DB::afterCommit(fn () => $owners->each(fn (User $u) => $u->notify(new BillingNotice($tenant->id, $title, $body, $action))));
    }
}
