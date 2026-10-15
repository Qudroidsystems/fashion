<?php

use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\Invoice;
use App\Models\MeasurementTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use App\Services\Catalogue\StyleService;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
    $this->actingAs($this->owner);
    CurrentTenant::run($this->house, function () {
        $this->customer = Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08031234567']);
        $styles = app(StyleService::class);
        $style = $styles->create(['name' => 'Mermaid lace gown', 'audience' => 'female'], [
            'base_price' => 250000, 'price_options' => [['label' => 'Hand beading', 'amount' => 60000]], 'lead_time_days' => 21,
        ], [MeasurementTemplate::where('code', 'womens_gown')->value('id')]);
        $styles->publish($style->draft()->first());
        $this->gown = $style->fresh('currentVersion');
    });
    CurrentTenant::context()->clear();
});

function asOwner($test, callable $fn)
{
    $test->actingAs($test->owner);

    return CurrentTenant::run($test->house, $fn);
}

function gownLine($test, array $over = []): array
{
    return array_merge(['kind' => 'bespoke', 'style_id' => $test->gown->id, 'quantity' => 1, 'options' => ['Hand beading']], $over);
}

it('prices lines on the server and pins the style version (scenario 31)', function () {
    $order = asOwner($this, fn () => app(OrderService::class)->saveOrder(null, ['customer_id' => $this->customer->id, 'order_discount' => 10000], [
        gownLine($this),
        ['kind' => 'service', 'description' => 'Bridesmaid alteration', 'quantity' => 3, 'unit_price' => 5000.333],
    ]));

    expect($order->number)->toStartWith('ORD-')
        ->and($order->lines[0]->unit_price)->toBe(310000.0)                     // 250,000 + beading
        ->and($order->lines[0]->style_version_id)->toBe($this->gown->current_version_id)
        ->and($order->lines[1]->line_total)->toBe(15000.99)
        ->and($order->subtotal)->toBe(325000.99)
        ->and($order->total)->toBe(315000.99)
        ->and($order->deposit_required)->toBe(157500.5);

    // A new style version does not change the order line.
    asOwner($this, function () {
        $styles = app(StyleService::class);
        $draft = $styles->updateDraft($styles->draftFor($this->gown->fresh()), ['base_price' => 300000]);
        $styles->publish($draft);
    });
    expect($order->lines[0]->fresh()->style_version_id)->toBe($this->gown->current_version_id)
        ->and($order->lines[0]->fresh()->unit_price)->toBe(310000.0);
});

it('needs "Override price" for big discounts and prices below the catalogue', function () {
    $designer = addStaff($this->house, 'Designer');
    $this->actingAs($designer);

    CurrentTenant::run($this->house, function () {
        $svc = app(OrderService::class);
        expect(fn () => $svc->saveOrder(null, ['customer_id' => $this->customer->id, 'order_discount' => 50000], [gownLine($this)]))
            ->toThrow(ValidationException::class);   // 16% > 10%
        expect(fn () => $svc->saveOrder(null, ['customer_id' => $this->customer->id], [gownLine($this, ['unit_price' => 200000])]))
            ->toThrow(ValidationException::class);
        expect($svc->saveOrder(null, ['customer_id' => $this->customer->id, 'order_discount' => 20000], [gownLine($this)])->total)->toBe(290000.0);
    });
});

it('turns an accepted quotation into a confirmed order with a revision trail', function () {
    asOwner($this, function () {
        $svc = app(OrderService::class);
        $q = $svc->saveQuotation(null, ['customer_id' => $this->customer->id, 'title' => 'Wedding gown', 'deposit_percent' => 60], [gownLine($this)]);
        $svc->sendQuotation($q);
        $order = $svc->acceptQuotation($q->fresh());

        expect($q->fresh()->status)->toBe('converted')
            ->and($order->status)->toBe('confirmed')
            ->and($order->deposit_required)->toBe(186000.0)
            ->and($order->events()->count())->toBe(1);

        // Changing a confirmed order needs a reason and keeps a snapshot.
        expect(fn () => $svc->saveOrder($order, [], [gownLine($this, ['id' => $order->lines[0]->id, 'quantity' => 2])]))->toThrow(ValidationException::class);
        $order = $svc->saveOrder($order->fresh(), ['title' => 'Wedding gown'], [gownLine($this, ['id' => $order->lines[0]->id, 'quantity' => 2])], 'Second gown for the reception');
        expect($order->revision)->toBe(1)->and($order->total)->toBe(620000.0)
            ->and($order->revisions()->first()->total_before)->toBe(310000.0);

        expect(fn () => $svc->transition($order, 'delivered'))->toThrow(ValidationException::class);
        $svc->transition($order, 'in_production');
        expect($order->fresh()->lines[0]->status)->toBe('in_production');
    });
});

it('derives balances from payments, invoices and allocations (FR-ORD-006)', function () {
    asOwner($this, function () {
        $svc = app(OrderService::class);
        $bill = app(BillingService::class);
        $order = $svc->saveOrder(null, ['customer_id' => $this->customer->id], [gownLine($this)]);   // 310,000
        $svc->transition($order, 'confirmed');

        $deposit = $bill->issue($bill->invoiceOrder($order->fresh(), 'deposit'));
        expect($deposit->total)->toBe(155000.0)->and($deposit->number)->toStartWith('INV-');

        // Part-payment by transfer goes to the deposit invoice first.
        $p1 = $bill->recordPayment(['customer_id' => $this->customer->id, 'amount' => 100000, 'method' => 'bank_transfer', 'reference' => 'GTB-7781', 'order_id' => $order->id]);
        expect($deposit->fresh()->balance())->toBe(55000.0)
            ->and($order->fresh()->balance())->toBe(210000.0)
            ->and($p1->unallocated())->toBe(0.0);

        // Same bank reference twice is refused unless confirmed.
        expect(fn () => $bill->recordPayment(['customer_id' => $this->customer->id, 'amount' => 100000, 'method' => 'bank_transfer', 'reference' => 'GTB-7781']))
            ->toThrow(ValidationException::class);

        // Cash with no target is held as credit, then applied.
        $p2 = $bill->recordPayment(['customer_id' => $this->customer->id, 'amount' => 250000, 'method' => 'cash']);
        expect($p2->unallocated())->toBe(250000.0);
        $bill->allocate($p2, $order->fresh(), null);
        expect($order->fresh()->balance())->toBe(0.0)
            ->and($deposit->fresh()->status_label)->toBe('Paid')
            ->and($p2->fresh()->unallocated())->toBe(40000.0)
            ->and($bill->customerSummary($this->customer))->toMatchArray(['owed' => 0.0, 'credit' => 40000.0, 'paid' => 350000.0]);

        // Can't invoice more than is left.
        expect(fn () => $bill->invoiceOrder($order->fresh(), 'balance', 1000))->toThrow(ValidationException::class);
    });
});

it('limits refunds to what is refundable and needs approval (scenario 37)', function () {
    $manager = addStaff($this->house, 'Manager');
    [$order, $payment] = asOwner($this, function () {
        $svc = app(OrderService::class);
        $order = $svc->saveOrder(null, ['customer_id' => $this->customer->id], [gownLine($this, ['options' => []])]);   // 250,000
        $svc->transition($order, 'confirmed');
        $payment = app(BillingService::class)->recordPayment(['customer_id' => $this->customer->id, 'amount' => 125000, 'method' => 'cash', 'order_id' => $order->id]);
        $svc->cancel($order->fresh(), 'Wedding called off');

        return [$order->fresh(), $payment];
    });
    expect($order->balance())->toBe(-125000.0);   // cancelled: paid money is the customer's

    $this->actingAs($manager);
    CurrentTenant::run($this->house, function () use ($order, $payment, $manager) {
        $bill = app(BillingService::class);
        expect(fn () => $bill->requestRefund($payment, ['amount' => 130000, 'reason' => 'Cancelled', 'order_id' => $order->id]))->toThrow(ValidationException::class);
        $refund = $bill->requestRefund($payment, ['amount' => 100000, 'reason' => 'Cancelled — keep 25,000 for fabric bought', 'order_id' => $order->id]);
        // Another person exists who can approve, so the requester may not.
        expect(fn () => $bill->approveRefund($refund, $manager))->toThrow(ValidationException::class);
        expect(fn () => $bill->requestRefund($payment, ['amount' => 30000, 'reason' => 'Again', 'order_id' => $order->id]))->toThrow(ValidationException::class);
    });

    asOwner($this, function () use ($order) {
        $refund = \App\Models\Refund::first();
        app(BillingService::class)->approveRefund($refund, $this->owner);
        expect($refund->fresh()->status)->toBe('paid')
            ->and($order->fresh()->balance())->toBe(-25000.0);
    });
});

it('reverses a bounced payment without deleting anything', function () {
    asOwner($this, function () {
        $svc = app(OrderService::class);
        $bill = app(BillingService::class);
        $order = $svc->saveOrder(null, ['customer_id' => $this->customer->id], [gownLine($this)]);
        $svc->transition($order, 'confirmed');
        $p = $bill->recordPayment(['customer_id' => $this->customer->id, 'amount' => 50000, 'method' => 'cheque', 'order_id' => $order->id]);
        $bill->reverse($p, 'Cheque bounced', $this->owner);

        expect($order->fresh()->balance())->toBe(310000.0)
            ->and(\App\Models\PaymentAllocation::count())->toBe(2)
            ->and(fn () => $p->fresh()->update(['amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $p->fresh()->delete())->toThrow(LogicException::class);
    });
});

it('reserves order materials and releases them when the order is cancelled (FR-ORD-011)', function () {
    asOwner($this, function () {
        $lace = app(\App\Services\Inventory\ItemService::class)->create(['name' => 'French lace', 'type' => 'fabric', 'unit' => 'yd']);
        $branch = \App\Models\Branch::first();
        app(\App\Services\Inventory\StockLedger::class)->post($lace, $branch->id, 20, 'opening', ['unit_cost' => 9000]);

        $svc = app(OrderService::class);
        $order = $svc->saveOrder(null, ['customer_id' => $this->customer->id], [gownLine($this)]);
        $svc->transition($order, 'confirmed');
        $svc->addMaterial($order, $lace, 6, $branch->id);
        expect(app(\App\Services\Inventory\StockLedger::class)->availableAt($lace, $branch->id))->toBe(14.0);

        // Issuing for the order uses its reservation.
        app(\App\Services\Inventory\StockDocumentService::class)->issue(['branch_id' => $branch->id, 'order_id' => $order->id, 'customer_id' => $this->customer->id, 'job_reference' => $order->number],
            [['item_id' => $lace->id, 'quantity' => 4]]);
        expect(StockReservation::where('order_id', $order->id)->value('consumed'))->toBe(4.0);

        $svc->cancel($order->fresh(), 'Customer changed her mind');
        expect(StockReservation::where('order_id', $order->id)->value('status'))->toBe('released')
            ->and(app(\App\Services\Inventory\StockLedger::class)->availableAt($lace, $branch->id))->toBe(16.0);
    });
});

it('runs quote → order → invoice → payment through the web screens', function () {
    $this->actingAs($this->owner);
    $this->post('/quotations', ['customer_id' => $this->customer->id, 'title' => 'Wedding gown', 'action' => 'send',
        'lines' => [['kind' => 'bespoke', 'style_id' => $this->gown->id, 'quantity' => 1, 'options' => ['Hand beading']],
                    ['kind' => 'service', 'description' => '', 'quantity' => 1]]])->assertRedirect();
    $q = \App\Models\Quotation::withoutGlobalScopes()->first();
    expect($q->status)->toBe('sent')->and($q->total)->toBe(310000.0);

    $this->post("/quotations/{$q->public_id}/accept", ['promised_date' => now()->addDays(20)->toDateString()])->assertRedirect();
    $order = Order::withoutGlobalScopes()->first();
    expect($order->status)->toBe('confirmed');

    $this->post("/orders/{$order->public_id}/invoices", ['kind' => 'deposit'])->assertRedirect();
    $inv = Invoice::withoutGlobalScopes()->first();
    $this->post("/invoices/{$inv->public_id}/issue")->assertRedirect();

    $this->post('/payments', ['customer_id' => $this->customer->id, 'amount' => 155000, 'method' => 'bank_transfer', 'reference' => 'TRF-1', 'apply_to' => 'invoice:' . $inv->id])
        ->assertRedirect();
    expect($inv->fresh()->status_label)->toBe('Paid');
    $this->post("/orders/{$order->public_id}/status", ['to' => 'in_production', 'note' => 'Cutting started'])->assertRedirect();

    foreach (['/orders', '/orders?view=late', "/orders/{$order->public_id}", "/orders/{$order->public_id}/edit", '/orders/create',
        '/quotations', "/quotations/{$q->public_id}", '/quotations/create', '/invoices', '/invoices?status=all', "/invoices/{$inv->public_id}", '/invoices/create',
        '/payments', '/payments/create', '/payments/create?order=' . $order->public_id, '/payments/' . Payment::withoutGlobalScopes()->value('public_id'),
        "/customers/{$this->customer->public_id}", '/material-issues/create?order=' . $order->public_id] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get("/orders/{$order->public_id}")->assertSee('Cutting started')->assertSee('₦155,000');
});

it('keeps orders and payments inside their house and enforces permissions', function () {
    $order = asOwner($this, fn () => app(OrderService::class)->saveOrder(null, ['customer_id' => $this->customer->id], [gownLine($this)]));
    [, $otherOwner] = makeHouse('Other');
    $tailor = addStaff($this->house, 'Tailor');
    $cashier = addStaff($this->house, 'Cashier');

    $this->actingAs($otherOwner)->get("/orders/{$order->public_id}")->assertNotFound();
    $this->actingAs($otherOwner)->get('/orders')->assertOk()->assertDontSee($order->number);

    $this->actingAs($tailor)->get("/orders/{$order->public_id}")->assertOk()->assertDontSee('₦310,000');   // no money for tailors
    $this->actingAs($tailor)->get('/orders/create')->assertForbidden();
    $this->actingAs($tailor)->get('/payments')->assertForbidden();
    $this->actingAs($cashier)->get('/payments/create')->assertOk();
    $this->actingAs($cashier)->post("/orders/{$order->public_id}/cancel", ['reason' => 'x'])->assertForbidden();
});

it('exposes quotes, orders, invoices and payments over the API', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->owner);

    $q = $this->postJson('/api/v1/quotations', ['customer_id' => $this->customer->public_id, 'send' => true,
        'lines' => [['kind' => 'bespoke', 'style' => $this->gown->code, 'options' => ['Hand beading']]]])
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.total', 310000);
    $order = $this->postJson('/api/v1/quotations/' . $q->json('data.id') . '/accept', [])->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.balance', 310000);
    $id = $order->json('data.id');

    $inv = $this->postJson('/api/v1/invoices', ['order_id' => $id, 'kind' => 'deposit', 'issue' => true])->assertCreated()
        ->assertJsonPath('data.total', 155000)->assertJsonPath('data.status', 'issued');

    $pay = ['customer_id' => $this->customer->public_id, 'amount' => 155000, 'method' => 'bank_transfer', 'reference' => 'NIP-998', 'order_id' => $id];
    $first = $this->withHeader('Idempotency-Key', 'pay-abc-123')->postJson('/api/v1/payments', $pay)->assertCreated();
    $this->withHeader('Idempotency-Key', 'pay-abc-123')->postJson('/api/v1/payments', $pay)->assertCreated()->assertJsonPath('data.number', $first->json('data.number'));
    $this->flushHeaders();
    expect(Payment::withoutGlobalScopes()->count())->toBe(1);

    $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('data.balance', 155000);
    $this->postJson("/api/v1/orders/{$id}/status-events", ['status' => 'in_production', 'note' => 'Cutting'])->assertOk()->assertJsonPath('data.status', 'in_production');
    $this->patchJson("/api/v1/orders/{$id}", ['promised_date' => now()->addDays(30)->toDateString()])->assertUnprocessable();   // needs a reason
    $this->patchJson("/api/v1/orders/{$id}", ['promised_date' => now()->addDays(30)->toDateString(), 'revision_reason' => 'Customer travelling'])->assertOk()
        ->assertJsonPath('data.revision', 1);
    $this->getJson('/api/v1/orders?status=open')->assertOk()->assertJsonCount(1, 'data');
});
