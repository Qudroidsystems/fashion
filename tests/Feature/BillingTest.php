<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['billing.paystack.secret_key' => 'sk_test_platform', 'billing.paystack.public_key' => 'pk_test_platform']);
    Notification::fake();
    [$this->house, $this->owner] = makeHouse('Atelier');
});

function sub(Tenant $t): Subscription
{
    return Subscription::where('tenant_id', $t->id)->with('plan')->first();
}

function addCustomers(Tenant $t, int $n): void
{
    CurrentTenant::run($t, function () use ($n) {
        for ($i = 0; $i < $n; $i++) {
            Customer::create(['first_name' => 'C' . $i, 'last_name' => 'Test', 'phone' => '0803' . str_pad((string) $i, 7, '0', STR_PAD_LEFT)]);
        }
    });
}

/** Paystack answers for the platform account. */
function paystackSays(array $verify = [], array $charge = []): void
{
    $GLOBALS['paystack'] = ['verify' => $verify, 'charge' => $charge];
    if (($GLOBALS['paystack_app'] ?? null) === app()) {
        return;
    }
    $GLOBALS['paystack_app'] = app();
    Http::fake(function ($request) {
        return match (true) {
            str_contains($request->url(), '/transaction/initialize') => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'x']]),
            str_contains($request->url(), '/transaction/verify/') => Http::response(['status' => true, 'data' => $GLOBALS['paystack']['verify']]),
            str_contains($request->url(), '/transaction/charge_authorization') => Http::response(['status' => true, 'data' => $GLOBALS['paystack']['charge']]),
            default => Http::response([], 404),
        };
    });
}

it('starts new houses on a Pro trial and moves them to Free when it ends, keeping their data', function () {
    $owner = User::factory()->create();
    $t = app(TenantProvisioner::class)->create(['name' => 'Fresh House', 'status' => 'trial'], $owner);
    expect(sub($t)->plan->code)->toBe('pro')->and(sub($t)->status)->toBe('trialing')->and($t->fresh()->status)->toBe('trial');
    addCustomers($t, 25);

    $this->travel(15)->days();
    app(SubscriptionService::class)->run();
    app(Entitlements::class)->forget();

    expect(sub($t)->plan->code)->toBe('free')->and($t->fresh()->status)->toBe('active')->and($t->fresh()->isUsable())->toBeTrue()
        ->and(Customer::withoutTenantScope()->where('tenant_id', $t->id)->count())->toBe(25);   // nothing deleted
    // Over the Free limit: can't add, can still read.
    expect(fn () => addCustomers($t, 1))->toThrow(ValidationException::class, 'Your Free plan allows 20 customers. Upgrade to Basic to add more.');
    $this->actingAs($owner)->get('/customers')->assertOk()->assertSee('C24');
});

it('enforces customer, staff and branch limits from the plan, which the platform can change', function () {
    onPlan($this->house, 'free');
    addCustomers($this->house, 20);
    expect(fn () => addCustomers($this->house, 1))->toThrow(ValidationException::class, 'allows 20 customers');
    $this->actingAs($this->owner)->post('/customers', ['first_name' => 'One', 'last_name' => 'More', 'phone' => '08099990000'])
        ->assertSessionHasErrors('plan_limit');

    addStaff($this->house, 'Tailor');   // owner + 1 = 2
    expect(fn () => addStaff($this->house, 'Tailor'))->toThrow(ValidationException::class, 'allows 2 staff logins');
    expect(fn () => CurrentTenant::run($this->house, fn () => \App\Models\Branch::create(['name' => 'Ikeja', 'code' => 'IKJ', 'type' => 'branch'])))
        ->toThrow(ValidationException::class, 'allows 1 branch');

    // The super admin raises the Free limit: it applies straight away.
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $free = Plan::where('code', 'free')->first();
    $this->actingAs($admin)->put("/platform/plans/{$free->id}", ['code' => 'free', 'name' => 'Starter', 'price_monthly' => 0, 'price_yearly' => 0,
        'limits' => ['customers' => 25, 'staff' => 2, 'branches' => 1], 'features' => ['measurement_links'], 'is_active' => 1, 'is_public' => 1, 'is_free' => 1, 'sort_order' => 1])
        ->assertRedirect();
    app(Entitlements::class)->forget();
    addCustomers($this->house, 5);
    expect(Customer::withoutTenantScope()->where('tenant_id', $this->house->id)->count())->toBe(25);
    $this->get('/')->assertOk()->assertSee('Starter')->assertSee('25 customers');

    // Only the platform admin edits plans.
    $this->actingAs($this->owner)->put("/platform/plans/{$free->id}", ['code' => 'free'])->assertForbidden();
});

it('makes locked features read-only on the web and refuses them in the app', function () {
    onPlan($this->house, 'free');
    $this->actingAs($this->owner);
    $this->get('/pos')->assertOk()->assertSee('Read-only.')->assertSee('Shop till with cashier shifts is not included in your Free plan');
    $this->post('/pos/shifts', ['branch_id' => 1, 'opening_float' => 0])->assertRedirect()->assertSessionHasErrors('plan');
    $this->get('/projects')->assertOk()->assertSee('Read-only.');
    $this->get('/customers/export')->assertRedirect('/billing');
    $this->get('/dashboard')->assertOk()->assertSee('ri-lock-line', false)->assertSee('Plan & billing', false);

    \Laravel\Sanctum\Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/customers')->assertForbidden()->assertJsonPath('code', 'plan_feature_locked')->assertJsonPath('feature', 'mobile_app');

    onPlan($this->house, 'basic');
    $this->getJson('/api/v1/customers')->assertOk();
    $this->actingAs($this->owner)->get('/pos')->assertOk()->assertDontSee('Read-only.');
    $this->get('/projects')->assertOk()->assertSee('Read-only.');   // weddings start at Pro
});

it('takes payment for a plan through Paystack, saves the card, and renews it', function () {
    onPlan($this->house, 'free');
    paystackSays();
    $this->actingAs($this->owner)->get('/billing')->assertOk()->assertSee('Choose Pro');
    $this->post('/billing/plan', ['plan' => 'pro', 'interval' => 'monthly'])->assertRedirect('https://checkout.paystack.com/abc');
    $invoice = SubscriptionInvoice::first();
    expect($invoice->amount)->toBe(15000.0)->and($invoice->status)->toBe('pending');

    // Paystack confirms (verified server-side, never trusting the redirect).
    paystackSays(verify: ['status' => 'success', 'amount' => 1500000, 'currency' => 'NGN', 'channel' => 'card', 'customer' => ['email' => 'owner@atelier.test'],
        'authorization' => ['authorization_code' => 'AUTH_abc', 'reusable' => true, 'brand' => 'visa', 'last4' => '4081', 'exp_month' => '09', 'exp_year' => '2028']]);
    $this->get('/billing/return?reference=' . $invoice->reference)->assertRedirect('/billing')->assertSessionHas('success');
    $s = sub($this->house);
    expect($s->plan->code)->toBe('pro')->and($s->status)->toBe('active')->and($s->card_label)->toBe('Visa •••• 4081, exp 09/28')
        ->and($s->current_period_end->isSameDay(now()->addMonth()))->toBeTrue()->and($invoice->fresh()->status)->toBe('paid');
    // A replayed webhook changes nothing.
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 99, 'reference' => $invoice->reference]]);
    $sig = hash_hmac('sha512', $body, 'sk_test_platform');
    $this->call('POST', '/webhooks/billing/paystack', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('POST', '/webhooks/billing/paystack', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('POST', '/webhooks/billing/paystack', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => 'bad', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
    expect(SubscriptionInvoice::where('status', 'paid')->count())->toBe(1);

    // A month later the saved card is charged for the next month.
    $this->travelTo($s->current_period_end->copy()->addMinute());
    paystackSays(charge: ['status' => 'success', 'amount' => 1500000, 'currency' => 'NGN', 'channel' => 'card', 'customer' => ['email' => 'owner@atelier.test'],
        'authorization' => ['authorization_code' => 'AUTH_abc', 'reusable' => true, 'brand' => 'visa', 'last4' => '4081']]);
    expect(app(SubscriptionService::class)->run()['renewed'])->toBe(1);
    expect(sub($this->house)->current_period_end->isSameDay(now()->addMonth()))->toBeTrue()
        ->and(SubscriptionInvoice::where('reason', 'renewal')->where('status', 'paid')->count())->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'charge_authorization') && $r['authorization_code'] === 'AUTH_abc' && $r['amount'] === 1500000);
});

it('keeps the plan through a grace period when renewal fails, then moves to Free', function () {
    onPlan($this->house, 'pro');
    Subscription::where('tenant_id', $this->house->id)->update(['current_period_end' => now()->subMinute(),
        'authorization' => Crypt::encryptString(json_encode(['authorization_code' => 'AUTH_x', 'email' => 'o@x.test']))]);
    paystackSays(charge: ['status' => 'failed', 'gateway_response' => 'Insufficient Funds']);

    expect(app(SubscriptionService::class)->run()['failed'])->toBe(1);
    $s = sub($this->house);
    expect($s->status)->toBe('past_due')->and($s->plan->code)->toBe('pro')->and($s->failure_reason)->toBe('Insufficient Funds')
        ->and($this->house->fresh()->status)->toBe('past_due');
    Notification::assertSentTo($this->owner, \App\Notifications\BillingNotice::class, fn ($n) => $n->title === 'We could not renew your plan');
    $this->actingAs($this->owner)->get('/billing')->assertOk()->assertSee('Pay now');

    $this->travel(8)->days();
    app(SubscriptionService::class)->run();
    expect(sub($this->house)->plan->code)->toBe('free')->and($this->house->fresh()->status)->toBe('active');
});

it('schedules a downgrade for the end of the paid period, and lets the platform set plans by hand', function () {
    onPlan($this->house, 'pro');
    Subscription::where('tenant_id', $this->house->id)->update(['current_period_end' => now()->addDays(10)]);
    $this->actingAs($this->owner)->post('/billing/plan', ['plan' => 'basic', 'interval' => 'monthly'])->assertSessionHas('success');
    expect(sub($this->house)->plan->code)->toBe('pro')->and(sub($this->house)->pendingPlan->code)->toBe('basic');

    $admin = User::factory()->create(['is_platform_admin' => true]);
    $business = Plan::where('code', 'business')->first();
    $this->actingAs($admin)->get('/platform/tenants')->assertOk()->assertSee('Pro');
    $this->get('/platform/plans')->assertOk()->assertSee('Save Business')->assertSee('Shown on the website');
    $this->post("/platform/tenants/{$this->house->public_id}/plan", ['plan_id' => $business->id, 'interval' => 'yearly', 'status' => 'active',
        'until' => now()->addYear()->toDateString(), 'paid' => 400000, 'note' => 'Bank transfer GTB'])->assertRedirect();
    $s = sub($this->house);
    expect($s->plan->code)->toBe('business')->and($s->interval)->toBe('yearly')->and($s->pending_plan_id)->toBeNull()
        ->and(SubscriptionInvoice::where('gateway', 'manual')->value('amount'))->toBe(400000.0);
    $this->actingAs($this->owner)->get('/billing')->assertOk()->assertSee('Business')->assertSee('₦400,000');
});

it('has no plans or limits in single-house mode', function () {
    onPlan($this->house, 'free');
    config(['tenancy.mode' => 'single']);
    addCustomers($this->house, 25);
    $e = app(Entitlements::class);
    expect(Customer::withoutTenantScope()->where('tenant_id', $this->house->id)->count())->toBe(25)
        ->and($e->has($this->house, 'pos'))->toBeTrue()->and($e->limit($this->house, 'customers'))->toBeNull();
});
