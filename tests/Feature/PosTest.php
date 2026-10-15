<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\WebhookEvent;
use App\Services\Catalogue\ProductService;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\OrderService;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Pos\PosService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
    $this->actingAs($this->owner);
    CurrentTenant::run($this->house, function () {
        $this->branch = Branch::where('is_main', true)->first();
        $this->customer = Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08031234567', 'email' => 'ngozi@example.com']);
        $product = app(ProductService::class)->save(null, ['name' => 'Ankara shirt', 'status' => 'active', 'track_stock' => true, 'show_in_catalogue' => true],
            [['size' => 'M', 'price' => 15000, 'cost_price' => 6000], ['size' => 'L', 'price' => 15000, 'cost_price' => 6000]]);
        $this->shirtM = $product->variants->firstWhere('size', 'M');
        $this->itemM = InventoryItem::where('product_variant_id', $this->shirtM->id)->first();
        app(StockLedger::class)->post($this->itemM, $this->branch->id, 10, 'opening', ['unit_cost' => 6000]);
    });
    CurrentTenant::context()->clear();
});

function onTill($test, callable $fn)
{
    $test->actingAs($test->owner);

    return CurrentTenant::run($test->house, $fn);
}

it('sells, posts stock once even when the till retries (scenario 35), and balances the drawer', function () {
    onTill($this, function () {
        $svc = app(PosService::class);
        $shift = $svc->openShift($this->owner, $this->branch->id, 5000);
        $sale = ['client_ref' => 'till-1-0001', 'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 2]],
            'tenders' => [['method' => 'cash', 'amount' => 20000], ['method' => 'card', 'amount' => 10000, 'reference' => 'TID-9']]];

        $a = $svc->completeSale($shift, $sale, $this->owner);
        $b = $svc->completeSale($shift, $sale, $this->owner);   // retried

        expect($a->id)->toBe($b->id)->and(Sale::count())->toBe(1)
            ->and($a->total)->toBe(30000.0)->and($a->change_given)->toBe(0.0)
            ->and(app(StockLedger::class)->availableAt($this->itemM, $this->branch->id))->toBe(8.0)
            ->and($a->lines->first()->unit_cost)->toBe(6000.0);

        // Cash 5,000 float + 20,000; pay out 1,500 for thread.
        $svc->cashMovement($shift, 'pay_out', 1500, 'Thread from the market');
        expect($svc->summary($shift)['expected_cash'])->toBe(23500.0);
        expect(fn () => $svc->closeShift($shift, 23000, null, $this->owner))->toThrow(ValidationException::class);
        $closed = $svc->closeShift($shift->fresh(), 23000, 'Gave wrong change', $this->owner);
        expect($closed->variance)->toBe(-500.0)->and($closed->status)->toBe('closed');
    });
});

it('gives change only from cash and refuses short or over-card payments', function () {
    onTill($this, function () {
        $svc = app(PosService::class);
        $shift = $svc->openShift($this->owner, $this->branch->id, 0);
        $line = [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 1]];

        $s = $svc->completeSale($shift, ['client_ref' => 'r-1', 'lines' => $line, 'tenders' => [['method' => 'cash', 'amount' => 20000]]], $this->owner);
        expect($s->change_given)->toBe(5000.0)->and($s->tenders->first()->amount)->toBe(15000.0)->and($s->tenders->first()->received)->toBe(20000.0);

        expect(fn () => $svc->completeSale($shift, ['client_ref' => 'r-2', 'lines' => $line, 'tenders' => [['method' => 'card', 'amount' => 20000]]], $this->owner))
            ->toThrow(ValidationException::class);
        expect(fn () => $svc->completeSale($shift, ['client_ref' => 'r-3', 'lines' => $line, 'tenders' => [['method' => 'cash', 'amount' => 10000]]], $this->owner))
            ->toThrow(ValidationException::class);
        expect(Sale::count())->toBe(1);
    });
});

it('refuses to sell more than is in stock and rolls everything back', function () {
    onTill($this, function () {
        $svc = app(PosService::class);
        $shift = $svc->openShift($this->owner, $this->branch->id, 0);
        expect(fn () => $svc->completeSale($shift, ['client_ref' => 'x-1',
            'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 11]],
            'tenders' => [['method' => 'cash', 'amount' => 200000]]], $this->owner))->toThrow(ValidationException::class);
        expect(Sale::count())->toBe(0)->and(StockBalance::where('item_id', $this->itemM->id)->sum('on_hand'))->toEqual(10);
    });
});

it('takes a deposit for an order at the till (FR-POS-006) and voids it cleanly', function () {
    onTill($this, function () {
        $orders = app(OrderService::class);
        $order = $orders->saveOrder(null, ['customer_id' => $this->customer->id], [['kind' => 'service', 'description' => 'Wedding gown', 'unit_price' => 200000]]);
        $orders->transition($order, 'confirmed');

        $svc = app(PosService::class);
        $shift = $svc->openShift($this->owner, $this->branch->id, 0);
        $sale = $svc->completeSale($shift, ['client_ref' => 'dep-1',
            'lines' => [['type' => 'order', 'order_id' => $order->id, 'amount' => 100000], ['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 1]],
            'tenders' => [['method' => 'bank_transfer', 'amount' => 115000, 'reference' => 'NIP-1']]], $this->owner);

        expect($order->fresh()->balance())->toBe(100000.0)
            ->and($sale->customer_id)->toBe($this->customer->id)
            ->and(Payment::where('sale_id', $sale->id)->value('channel'))->toBe('pos');

        $svc->voidSale($sale, 'Rang up the wrong order', $this->owner);
        expect($order->fresh()->balance())->toBe(200000.0)
            ->and(app(StockLedger::class)->availableAt($this->itemM, $this->branch->id))->toBe(10.0)
            ->and($svc->summary($shift)['sales_count'])->toBe(0);
    });
});

it('returns part of a sale with a cash refund and restocks it (FR-POS-004)', function () {
    onTill($this, function () {
        $svc = app(PosService::class);
        $shift = $svc->openShift($this->owner, $this->branch->id, 10000);
        $sale = $svc->completeSale($shift, ['client_ref' => 'ret-1',
            'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 3, 'discount' => 1500]],
            'tenders' => [['method' => 'cash', 'amount' => 43500]]], $this->owner);
        $line = $sale->lines->first();

        $ret = $svc->returnItems($sale, [$line->id => 1], 'cash', true, 'Wrong size', $this->owner);
        expect($ret->amount)->toBe(14500.0)
            ->and(app(StockLedger::class)->availableAt($this->itemM, $this->branch->id))->toBe(8.0)
            ->and($svc->summary($shift)['expected_cash'])->toBe(39000.0);

        expect(fn () => $svc->returnItems($sale, [$line->id => 3], 'cash', true, 'again', $this->owner))->toThrow(ValidationException::class);
        $last = $svc->returnItems($sale->fresh(), [$line->id => 2], 'cash', false, 'Damaged', $this->owner);
        expect($ret->amount + $last->amount)->toBe(43500.0)                    // never more than was paid
            ->and(app(StockLedger::class)->availableAt($this->itemM, $this->branch->id))->toBe(8.0);   // damaged: not restocked
    });
});

it('needs "Override price" to sell below the price or over the discount limit', function () {
    $cashier = addStaff($this->house, 'Cashier');
    $this->actingAs($cashier);
    CurrentTenant::run($this->house, function () use ($cashier) {
        $svc = app(PosService::class);
        $shift = $svc->openShift($cashier, $this->branch->id, 0);
        $pay = [['method' => 'cash', 'amount' => 20000]];
        expect(fn () => $svc->completeSale($shift, ['client_ref' => 'p-1', 'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 1, 'unit_price' => 12000]], 'tenders' => $pay], $cashier))
            ->toThrow(ValidationException::class);
        expect(fn () => $svc->completeSale($shift, ['client_ref' => 'p-2', 'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 1, 'discount' => 3000]], 'tenders' => $pay], $cashier))
            ->toThrow(ValidationException::class);
        expect($svc->completeSale($shift, ['client_ref' => 'p-3', 'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 1, 'discount' => 1500]], 'tenders' => $pay], $cashier)->total)->toBe(13500.0);
    });
});

/* ------------------------------ online payments ----------------------------- */

function paystackHouse($test): void
{
    $test->house->update(['settings' => array_merge($test->house->settings ?? [], ['paystack' => ['enabled' => true, 'public_key' => 'pk_test_x', 'secret_key' => Crypt::encryptString('sk_test_secret')]])]);
}

it('confirms a Paystack payment once, however many times the webhook arrives (scenario 36)', function () {
    paystackHouse($this);
    $calls = 0;
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'r']]),
        'api.paystack.co/transaction/verify/*' => function () use (&$calls) {
            $calls++;

            return Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 5000000, 'currency' => 'NGN', 'channel' => 'card', 'id' => 777, 'paid_at' => now()->toIso8601String()]]);
        },
    ]);

    [$order, $intent] = onTill($this, function () {
        $orders = app(OrderService::class);
        $order = $orders->saveOrder(null, ['customer_id' => $this->customer->id], [['kind' => 'service', 'description' => 'Gown', 'unit_price' => 120000]]);
        $orders->transition($order, 'confirmed');
        $intent = app(OnlinePaymentService::class)->createLink($order->fresh(), null, 50000);

        return [$order, $intent];
    });
    expect($intent->authorization_url)->toBe('https://checkout.paystack.com/abc');
    auth()->logout();

    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 777, 'reference' => $intent->reference, 'amount' => 5000000, 'currency' => 'NGN']]);
    $sig = hash_hmac('sha512', $body, 'sk_test_secret');
    $url = "/webhooks/paystack/{$this->house->public_id}";

    $this->call('POST', $url, [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('POST', $url, [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('POST', $url, [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);

    expect(Payment::withoutGlobalScopes()->where('channel', 'online')->count())->toBe(1)
        ->and(PaymentIntent::withoutGlobalScopes()->first()->status)->toBe('succeeded')
        ->and(WebhookEvent::count())->toBe(1)
        ->and($calls)->toBe(1);
    CurrentTenant::run($this->house, fn () => expect($order->fresh()->balance())->toBe(70000.0));

    // The customer coming back afterwards does not pay twice either.
    $this->get('/pay/return?reference=' . $intent->reference)->assertOk()->assertSee('Thank you');
    expect(Payment::withoutGlobalScopes()->where('channel', 'online')->count())->toBe(1);
});

it('does not confirm when Paystack says the payment failed or the amount differs', function () {
    paystackHouse($this);
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']]),
        'api.paystack.co/transaction/verify/*' => Http::sequence()
            ->push(['status' => true, 'data' => ['status' => 'failed', 'amount' => 5000000, 'currency' => 'NGN']])
            ->push(['status' => true, 'data' => ['status' => 'success', 'amount' => 100, 'currency' => 'NGN']]),
    ]);

    onTill($this, function () {
        $orders = app(OrderService::class);
        $order = $orders->saveOrder(null, ['customer_id' => $this->customer->id], [['kind' => 'service', 'description' => 'Gown', 'unit_price' => 120000]]);
        $orders->transition($order, 'confirmed');
        $svc = app(OnlinePaymentService::class);

        $a = $svc->createLink($order->fresh(), null, 50000);
        expect($svc->confirm($a)->status)->toBe('failed');

        $b = $svc->createLink($order->fresh(), null, 50000);
        expect(fn () => $svc->confirm($b))->toThrow(ValidationException::class);
        expect(Payment::count())->toBe(0)->and($order->fresh()->balance())->toBe(120000.0);
    });
});

it('renders the till, sales, shift and receipt screens, and keeps houses and roles apart', function () {
    $this->actingAs($this->owner)->get('/pos')->assertOk()->assertSee('Open shift');
    $this->post('/pos/shifts', ['branch_id' => $this->branch->id, 'opening_float' => 2000])->assertRedirect('/pos');
    $this->get('/pos')->assertOk()->assertSee('posScan', false);
    $this->get('/pos/products?q=Ankara')->assertOk()->assertJsonPath('items.0.name', 'Ankara shirt');

    $res = $this->postJson('/pos/sales', ['client_ref' => 'web-till-0001', 'lines' => [['type' => 'product', 'variant_id' => $this->shirtM->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 20000]]])->assertOk()->assertJsonPath('sale.change', 5000);
    $sale = onTill($this, fn () => Sale::first());
    $shift = $sale->shift;

    $this->get('/pos/sales')->assertOk()->assertSee($sale->number);
    $this->get("/pos/sales/{$sale->public_id}")->assertOk()->assertSee('Void sale');
    $this->get("/pos/sales/{$sale->public_id}/receipt")->assertOk()->assertSee('TOTAL')->assertSee('Atelier');
    $this->get('/pos/shifts')->assertOk()->assertSee($shift->number);
    $this->get("/pos/shifts/{$shift->public_id}")->assertOk()->assertSee('Count &amp; close', false);
    $this->getJson('/search?q=' . $sale->number)->assertOk()->assertJsonPath('results.0.category', 'POS sales');

    [, $otherOwner] = makeHouse('Other');
    $this->actingAs($otherOwner)->get("/pos/sales/{$sale->public_id}")->assertNotFound();
    $this->actingAs($otherOwner)->get("/pos/shifts/{$shift->public_id}")->assertNotFound();

    $cashier = addStaff($this->house, 'Cashier');
    $this->actingAs($cashier)->get('/pos')->assertOk();
    $this->actingAs($cashier)->post("/pos/sales/{$sale->public_id}/void", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($cashier)->get("/pos/shifts/{$shift->public_id}")->assertForbidden();   // someone else's drawer
    $this->actingAs(addStaff($this->house, 'Tailor'))->get('/pos')->assertForbidden();

    // Blind count: a cashier on their own shift sees takings but not the expected cash.
    $this->actingAs($cashier)->post('/pos/shifts', ['branch_id' => $this->branch->id, 'opening_float' => 3000]);
    $own = onTill($this, fn () => \App\Models\CashShift::where('user_id', $cashier->id)->first());
    $this->actingAs($cashier)->get("/pos/shifts/{$own->public_id}")->assertOk()->assertSee('Shown after you count')->assertDontSee('₦3,000.00 +');
    \Laravel\Sanctum\Sanctum::actingAs($cashier);
    $this->getJson('/api/v1/pos/shifts/current')->assertOk()->assertJsonMissingPath('data.summary.expected_cash');

    // The owner closes with the right count: the drawer balances.
    $this->actingAs($this->owner)->post("/pos/shifts/{$shift->public_id}/close", ['counted_cash' => 17000])->assertRedirect();
    expect($shift->fresh()->status)->toBe('closed')->and($shift->fresh()->variance)->toBe(0.0);
});

it('opens a shift, sells idempotently and closes over the API', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/pos/shifts/current')->assertOk()->assertJsonPath('data', null);
    $shift = $this->postJson('/api/v1/pos/shifts/open', ['opening_float' => 1000])->assertCreated()->json('data.id');

    $sale = ['client_ref' => 'app-till-000042', 'customer' => $this->customer->public_id,
        'lines' => [['sku' => $this->shirtM->sku, 'quantity' => 2]], 'tenders' => [['method' => 'card', 'amount' => 30000, 'reference' => 'TID-1']]];
    $first = $this->postJson('/api/v1/pos/sales', $sale)->assertCreated()->assertJsonPath('data.total', 30000);
    $this->postJson('/api/v1/pos/sales', $sale)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.number', $first->json('data.number'));
    $this->postJson('/api/v1/pos/sales', ['client_ref' => 'app-till-000043', 'lines' => [['sku' => 'NOPE']], 'tenders' => [['method' => 'cash', 'amount' => 1]]])->assertUnprocessable();

    $this->getJson('/api/v1/pos/sales/' . $first->json('data.id'))->assertOk()->assertJsonCount(1, 'data.lines');
    $this->getJson('/api/v1/pos/shifts/current')->assertOk()->assertJsonPath('data.summary.sales_count', 1);
    $this->postJson("/api/v1/pos/shifts/{$shift}/close", ['counted_cash' => 1000])->assertOk()->assertJsonPath('data.variance', 0);
    expect(onTill($this, fn () => (float) StockBalance::sum('on_hand')))->toBe(8.0);
});
