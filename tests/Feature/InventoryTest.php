<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\PurchasingService;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\StockLedger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
    CurrentTenant::run($this->house, function () {
        $this->shop = Branch::where('is_main', true)->first();
        $this->store = Branch::create(['name' => 'Warehouse', 'code' => 'WH', 'type' => 'warehouse']);
        $this->supplier = Supplier::create(['code' => 'SUP-0001', 'name' => 'Balogun Textiles']);
        $this->lace = app(ItemService::class)->create(['name' => 'French lace', 'type' => 'fabric', 'unit' => 'yd', 'reorder_level' => 10]);
        $this->buttons = app(ItemService::class)->create(['name' => 'Gold buttons', 'type' => 'accessory', 'unit' => 'pcs']);
    });
    CurrentTenant::context()->clear();
});

/** Run inside the house as a user. */
function inHouse($test, ?object $user = null, ?callable $fn = null)
{
    $test->actingAs($user ?? $test->owner);

    return CurrentTenant::run($test->house, $fn);
}

/** Receive stock directly (approved PO path tested separately). */
function receive($test, InventoryItem $item, float $qty, float $cost, ?int $branchId = null, ?string $lot = null)
{
    return inHouse($test, null, fn () => app(PurchasingService::class)->receive(
        ['branch_id' => $branchId ?? $test->shop->id, 'supplier_id' => $test->supplier->id],
        [['item_id' => $item->id, 'quantity' => $qty, 'unit_cost' => $cost, 'lot_no' => $lot]]
    ));
}

it('keeps fractional fabric balances, a ledger that reconciles, and weighted-average cost', function () {
    receive($this, $this->lace, 20.5, 4000);
    receive($this, $this->lace, 10, 5000);

    inHouse($this, null, function () {
        $ledger = app(StockLedger::class);
        $lace = $this->lace->fresh();
        expect($ledger->availability($lace)['on_hand'])->toBe(30.5)
            ->and(round($lace->average_cost, 2))->toBe(round((20.5 * 4000 + 10 * 5000) / 30.5, 2))
            ->and($ledger->reconcile())->toBeEmpty()
            ->and(round($ledger->valuation(), 2))->toBe(round(30.5 * $lace->average_cost, 2));

        $m = StockMovement::first();
        expect(fn () => $m->update(['quantity' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $m->delete())->toThrow(LogicException::class);
    });
});

it('refuses to issue more than is in stock', function () {
    receive($this, $this->lace, 5, 4000);

    inHouse($this, null, function () {
        expect(fn () => app(StockDocumentService::class)->issue(
            ['branch_id' => $this->shop->id, 'job_reference' => 'Gown'],
            [['item_id' => $this->lace->id, 'quantity' => 6]]
        ))->toThrow(ValidationException::class);
        expect(StockBalance::where('item_id', $this->lace->id)->sum('on_hand'))->toEqual(5);
    });
});

it('approves purchase orders with separation of duties and receives them in parts', function () {
    $manager = addStaff($this->house, 'Manager');
    $storekeeper = addStaff($this->house, 'Storekeeper');

    // Storekeeper cannot approve → stays pending.
    $po = inHouse($this, $storekeeper, function () use ($storekeeper) {
        $svc = app(PurchasingService::class);
        $po = $svc->savePurchaseOrder(null, ['supplier_id' => $this->supplier->id, 'branch_id' => $this->store->id],
            [['item_id' => $this->lace->id, 'quantity' => 40, 'unit_cost' => 4500]]);   // 180,000 > 100,000 limit

        return $svc->submit($po, $storekeeper);
    });
    expect($po->status)->toBe('pending_approval')->and($po->number)->toStartWith('PO-');

    // Manager submitted? No — storekeeper did, so manager may approve.
    inHouse($this, $manager, fn () => app(PurchasingService::class)->approve($po->fresh(), $manager));
    expect($po->fresh()->status)->toBe('approved');

    // A manager's own big PO needs someone else.
    $own = inHouse($this, $manager, function () use ($manager) {
        $svc = app(PurchasingService::class);
        $po = $svc->savePurchaseOrder(null, ['supplier_id' => $this->supplier->id, 'branch_id' => $this->shop->id],
            [['item_id' => $this->buttons->id, 'quantity' => 1000, 'unit_cost' => 150]]);

        return $svc->submit($po, $manager);
    });
    expect($own->status)->toBe('pending_approval');
    expect(fn () => inHouse($this, $manager, fn () => app(PurchasingService::class)->approve($own->fresh(), $manager)))
        ->toThrow(ValidationException::class);

    // Receive 25 then 15.
    inHouse($this, $storekeeper, function () use ($po) {
        $svc = app(PurchasingService::class);
        $line = $po->lines()->first();
        $svc->receive([], [['purchase_order_line_id' => $line->id, 'quantity' => 25, 'lot_no' => 'R-1']], $po->fresh());
        expect($po->fresh()->status)->toBe('partially_received')
            ->and(app(StockLedger::class)->availability($this->lace->fresh(), $this->store->id))->toMatchArray(['on_hand' => 25.0, 'incoming' => 15.0, 'atp' => 40.0]);
        $svc->receive([], [['purchase_order_line_id' => $line->id, 'quantity' => 15, 'lot_no' => 'R-2']], $po->fresh());
        expect($po->fresh()->status)->toBe('received');
    });
});

it('transfers between locations and moves reservations into material issues (scenario 38)', function () {
    receive($this, $this->lace, 30, 4000, $this->store->id);
    $customer = inHouse($this, null, fn () => Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08031234567']));

    inHouse($this, null, function () use ($customer) {
        $docs = app(StockDocumentService::class);
        $ledger = app(StockLedger::class);
        $docs->transfer($this->store->id, $this->shop->id, [['item_id' => $this->lace->id, 'quantity' => 12]]);
        expect($ledger->availableAt($this->lace, $this->shop->id))->toBe(12.0)
            ->and($ledger->availableAt($this->lace, $this->store->id))->toBe(18.0);

        $res = $ledger->reserve($this->lace, $this->shop->id, 8, ['customer_id' => $customer->id, 'reference' => 'Wedding gown']);
        expect($ledger->availableAt($this->lace, $this->shop->id))->toBe(4.0);

        // Someone else's job cannot take the reserved lace…
        expect(fn () => $docs->issue(['branch_id' => $this->shop->id, 'job_reference' => 'Other job'], [['item_id' => $this->lace->id, 'quantity' => 6]]))
            ->toThrow(ValidationException::class);

        // …but the customer's own issue draws on her reservation.
        $issue = $docs->issue(['branch_id' => $this->shop->id, 'customer_id' => $customer->id, 'job_reference' => 'Wedding gown'],
            [['item_id' => $this->lace->id, 'quantity' => 7.5]]);
        expect($res->fresh()->consumed)->toBe(7.5)
            ->and($ledger->availability($this->lace, $this->shop->id))->toMatchArray(['on_hand' => 4.5, 'reserved' => 0.5])
            ->and(StockMovement::where('source_type', 'material_issue')->where('source_id', $issue->id)->value('customer_id'))->toBe($customer->id)
            ->and($issue->total_cost)->toBe(30000.0);

        // 1.25 yd comes back as a remnant lot.
        $docs->returnFromProduction($issue, [$issue->lines->first()->id => 1.25], asRemnant: true);
        $remnant = \App\Models\StockLot::where('kind', 'remnant')->first();
        expect($remnant)->not->toBeNull()
            ->and((float) StockBalance::where('lot_id', $remnant->id)->value('on_hand'))->toBe(1.25)
            ->and($issue->fresh()->total_cost)->toBe(25000.0)
            ->and($ledger->reconcile())->toBeEmpty();

        $ledger->release($res->fresh());
        expect($ledger->availability($this->lace, $this->shop->id)['reserved'])->toBe(0.0);
    });
});

it('posts counts as differences and needs a second person above the limit (scenario 41)', function () {
    receive($this, $this->lace, 20, 4000);
    $manager = addStaff($this->house, 'Manager');

    // Small count difference: owner may self-approve.
    inHouse($this, null, function () {
        $docs = app(StockDocumentService::class);
        $count = $docs->saveAdjustment(null, ['kind' => 'count', 'branch_id' => $this->shop->id, 'reason' => 'Monthly count'],
            [['item_id' => $this->lace->id, 'counted_quantity' => 19]]);
        expect($count->lines->first()->quantity_change)->toBe(-1.0);
        $count = $docs->submitAdjustment($count, $this->owner);
        expect($count->status)->toBe('approved')
            ->and(app(StockLedger::class)->availableAt($this->lace, $this->shop->id))->toBe(19.0);
    });

    // Big wastage by the manager: cannot approve own; owner can.
    $waste = inHouse($this, $manager, function () use ($manager) {
        $docs = app(StockDocumentService::class);
        $adj = $docs->saveAdjustment(null, ['kind' => 'wastage', 'branch_id' => $this->shop->id, 'reason' => 'Water damage'],
            [['item_id' => $this->lace->id, 'quantity' => 10]]);   // 40,000 > 20,000

        return $docs->submitAdjustment($adj, $manager);
    });
    expect($waste->status)->toBe('pending_approval');
    expect(fn () => inHouse($this, $manager, fn () => app(StockDocumentService::class)->approveAdjustment($waste->fresh(), $manager)))
        ->toThrow(ValidationException::class);
    inHouse($this, null, fn () => app(StockDocumentService::class)->approveAdjustment($waste->fresh(), $this->owner));

    inHouse($this, null, function () {
        expect(app(StockLedger::class)->availableAt($this->lace, $this->shop->id))->toBe(9.0)
            ->and(\App\Models\AuditLog::where('action', 'stock_adjustment.approved')->count())->toBe(2);
    });
});

it('keeps customer material out of the stock value', function () {
    $customer = inHouse($this, null, fn () => Customer::create(['first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '08111111111']));

    inHouse($this, null, function () use ($customer) {
        $item = app(ItemService::class)->create(['name' => 'Ada’s aso-oke', 'type' => 'customer_material', 'unit' => 'yd', 'customer_id' => $customer->id]);
        $docs = app(StockDocumentService::class);
        $adj = $docs->saveAdjustment(null, ['kind' => 'customer_material', 'branch_id' => $this->shop->id, 'customer_id' => $customer->id],
            [['item_id' => $item->id, 'quantity' => 6]]);
        $docs->submitAdjustment($adj, $this->owner);

        expect(app(StockLedger::class)->availableAt($item, $this->shop->id))->toBe(6.0)
            ->and(app(StockLedger::class)->valuation())->toBe(0.0);
    });
});

it('alerts once when stock drops below the reorder level', function () {
    receive($this, $this->lace, 15, 4000);

    inHouse($this, null, function () {
        $docs = app(StockDocumentService::class);
        $docs->issue(['branch_id' => $this->shop->id, 'job_reference' => 'Job 1'], [['item_id' => $this->lace->id, 'quantity' => 6]]);
        $docs->issue(['branch_id' => $this->shop->id, 'job_reference' => 'Job 2'], [['item_id' => $this->lace->id, 'quantity' => 2]]);
    });

    expect(\Illuminate\Support\Facades\DB::table('notifications')->where('type', \App\Notifications\LowStock::class)->count())->toBe(1);
});

it('creates a stock item for each variant of a stock-tracked product', function () {
    inHouse($this, null, function () {
        $product = app(\App\Services\Catalogue\ProductService::class)->save(null,
            ['name' => 'Ankara shirt', 'status' => 'active', 'track_stock' => true, 'show_in_catalogue' => true],
            [['size' => 'M', 'price' => 15000], ['size' => 'L', 'price' => 15000]]);
        expect(InventoryItem::where('type', 'product')->count())->toBe(2);

        $m = $product->variants->firstWhere('size', 'M');
        receive($this, InventoryItem::where('product_variant_id', $m->id)->first(), 3, 6000);

        // Drop size M: its item keeps history but is switched off.
        app(\App\Services\Catalogue\ProductService::class)->save($product->fresh(), ['name' => 'Ankara shirt'],
            [['id' => $product->variants->firstWhere('size', 'L')->id, 'size' => 'L', 'price' => 15000]]);
        expect(InventoryItem::where('type', 'product')->where('is_active', true)->count())->toBe(1)
            ->and(InventoryItem::where('type', 'product')->count())->toBe(2);
    });
});

it('renders every inventory screen for the owner', function () {
    receive($this, $this->lace, 12, 4000, null, null);
    $po = inHouse($this, null, fn () => app(PurchasingService::class)->savePurchaseOrder(null,
        ['supplier_id' => $this->supplier->id, 'branch_id' => $this->shop->id], [['item_id' => $this->buttons->id, 'quantity' => 100, 'unit_cost' => 50]]));
    $issue = inHouse($this, null, fn () => app(StockDocumentService::class)->issue(['branch_id' => $this->shop->id, 'job_reference' => 'Test job'],
        [['item_id' => $this->lace->id, 'quantity' => 2]]));
    $adj = inHouse($this, null, fn () => app(StockDocumentService::class)->saveAdjustment(null, ['kind' => 'wastage', 'branch_id' => $this->shop->id],
        [['item_id' => $this->lace->id, 'quantity' => 1]]));
    $grn = \App\Models\GoodsReceipt::withoutGlobalScopes()->first();
    $this->actingAs($this->owner);

    foreach ([
        '/inventory', '/inventory?show=low', '/inventory/movements', "/inventory/items/{$this->lace->public_id}", '/inventory/items/create',
        "/inventory/items/{$this->lace->public_id}/edit", '/suppliers', '/suppliers/create', "/suppliers/{$this->supplier->public_id}",
        '/purchase-orders', '/purchase-orders/create', "/purchase-orders/{$po->public_id}", "/purchase-orders/{$po->public_id}/edit",
        '/goods-receipts', '/goods-receipts/create', "/goods-receipts/{$grn->public_id}",
        '/stock-transfers', '/stock-transfers/create', '/stock-adjustments', '/stock-adjustments/create?kind=wastage',
        '/stock-adjustments/create?kind=count', "/stock-adjustments/create?kind=count&sheet=1&branch_id={$this->shop->id}",
        '/stock-adjustments/create?kind=opening', '/stock-adjustments/create?kind=customer_material', "/stock-adjustments/{$adj->public_id}",
        "/stock-adjustments/{$adj->public_id}/edit", '/material-issues', '/material-issues/create', "/material-issues/{$issue->public_id}",
    ] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get("/inventory/items/{$this->lace->public_id}")->assertSee('French lace')->assertSee('10 yd');
});

it('runs a purchase order end to end through the web screens', function () {
    $this->actingAs($this->owner);
    $this->post('/purchase-orders', [
        'supplier_id' => $this->supplier->id, 'branch_id' => $this->shop->id, 'order_date' => now()->toDateString(), 'action' => 'submit',
        'lines' => [['item_id' => $this->lace->id, 'quantity' => 10, 'unit_cost' => 4000], ['item_id' => '', 'quantity' => '']],
    ])->assertRedirect();
    $po = PurchaseOrder::withoutGlobalScopes()->first();
    expect($po->status)->toBe('approved');   // owner, 40,000 within the 100,000 limit

    $line = $po->lines()->first();
    $this->post("/purchase-orders/{$po->public_id}/receive", ['lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 10, 'lot_no' => 'R-9']]])
        ->assertRedirect();
    expect($po->fresh()->status)->toBe('received');
    $this->get('/inventory')->assertSee('French lace')->assertSee('10 yd');
});

it('enforces inventory permissions and keeps stock inside its house', function () {
    receive($this, $this->lace, 5, 4000);
    $cashier = addStaff($this->house, 'Cashier');
    $tailor = addStaff($this->house, 'Tailor');
    [, $otherOwner] = makeHouse('Other');

    // Cashiers see availability (SRS §9) but cannot change stock.
    $this->actingAs($cashier)->get('/inventory')->assertOk()->assertDontSee('Stock value');
    $this->actingAs($cashier)->get('/inventory/items/create')->assertForbidden();
    $this->actingAs($cashier)->get('/stock-adjustments/create?kind=wastage')->assertForbidden();
    $this->actingAs($cashier)->get('/material-issues/create')->assertForbidden();
    $this->actingAs($tailor)->get('/purchase-orders/create')->assertForbidden();
    $this->actingAs($otherOwner)->get("/inventory/items/{$this->lace->public_id}")->assertNotFound();
    $this->actingAs($otherOwner)->get('/inventory')->assertOk()->assertDontSee('French lace');
});

it('exposes inventory over the API, with idempotent material issues', function () {
    receive($this, $this->lace, 20, 4000, null, null);
    \Laravel\Sanctum\Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/inventory-items?q=lace')->assertOk()->assertJsonPath('data.0.on_hand', 20);
    $this->getJson("/api/v1/inventory-items/{$this->lace->code}")->assertOk()->assertJsonPath('data.availability.available', 20);

    $body = ['branch_id' => $this->shop->id, 'job_reference' => 'Gown', 'lines' => [['item' => $this->lace->public_id, 'quantity' => 3]]];
    $first = $this->withHeader('Idempotency-Key', 'issue-123')->postJson('/api/v1/material-issues', $body)->assertCreated();
    $this->withHeader('Idempotency-Key', 'issue-123')->postJson('/api/v1/material-issues', $body)->assertCreated()
        ->assertJsonPath('data.number', $first->json('data.number'));
    $this->flushHeaders();
    expect(StockBalance::withoutGlobalScopes()->where('item_id', $this->lace->id)->sum('on_hand'))->toEqual(17);

    $this->postJson('/api/v1/stock-counts', ['branch_id' => $this->shop->id, 'lines' => [['item' => $this->lace->code, 'counted_quantity' => 16.5]]])
        ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.differences.0.change', -0.5);

    $po = $this->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $this->supplier->public_id, 'branch_id' => $this->shop->id, 'submit' => true,
        'lines' => [['item' => $this->buttons->code, 'quantity' => 200, 'unit_cost' => 50]],
    ])->assertCreated()->assertJsonPath('data.status', 'approved');
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po->json('data.id'),
        'lines' => [['purchase_order_line_id' => $po->json('data.lines.0.id'), 'quantity' => 200]]])
        ->assertCreated()->assertJsonPath('data.purchase_order_status', 'received');

    $this->getJson('/api/v1/stock-movements?item=' . $this->buttons->code)->assertOk()->assertJsonPath('data.0.type', 'receipt');
    $this->getJson('/api/v1/stock-balances')->assertOk()->assertJsonCount(2, 'data');
});

it('lets a sole approver self-approve above the limit and audits it', function () {
    inHouse($this, null, function () {
        $docs = app(StockDocumentService::class);
        $adj = $docs->saveAdjustment(null, ['kind' => 'opening', 'branch_id' => $this->shop->id],
            [['item_id' => $this->lace->id, 'quantity' => 50, 'unit_cost' => 9500]]);   // 475,000 > 20,000
        expect($docs->submitAdjustment($adj, $this->owner)->status)->toBe('approved')
            ->and(\App\Models\AuditLog::where('action', 'stock_adjustment.approved')->latest('id')->value('description'))->toContain('Self-approved');
    });

    // Once a manager joins, the owner's big changes need the manager.
    addStaff($this->house, 'Manager');
    inHouse($this, null, function () {
        $docs = app(StockDocumentService::class);
        $adj = $docs->saveAdjustment(null, ['kind' => 'wastage', 'branch_id' => $this->shop->id], [['item_id' => $this->lace->id, 'quantity' => 10]]);
        expect($docs->submitAdjustment($adj, $this->owner)->status)->toBe('pending_approval');
    });
});
