<?php

use App\Models\Customer;
use App\Support\Tenancy\CurrentTenant;

/*
 * Endpoints added for the staff mobile app: dashboard, staff, fittings list,
 * notifications, quotation detail, order extras and lookups.
 */

it('serves the dashboard with only the blocks the role may see', function () {
    [$tenant, $owner] = makeHouse('Dash House');

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['date', 'money' => ['collected_today', 'collected_month'], 'chart', 'orders' => ['open', 'pipeline']]]);

    $tailor = addStaff($tenant, 'Tailor');
    $res = $this->actingAs($tailor, 'sanctum')->getJson('/api/v1/dashboard');
    if ($res->status() === 200) {
        expect($res->json('data'))->not->toHaveKey('money');
    } else {
        $res->assertForbidden();
    }
});

it('lists the house staff without leaking other houses', function () {
    [$a, $ownerA] = makeHouse('Staff A');
    [$b] = makeHouse('Staff B');
    $tailor = addStaff($a, 'Tailor', ['name' => 'Bisi Tailor']);
    addStaff($b, 'Tailor', ['name' => 'Other House Person']);

    $names = collect($this->actingAs($ownerA, 'sanctum')->getJson('/api/v1/staff')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Bisi Tailor')->not->toContain('Other House Person');
});

it('lists fittings in a date range', function () {
    [$tenant, $owner] = makeHouse('Fit House');
    $customer = CurrentTenant::run($tenant, fn () => Customer::create(['first_name' => 'Ada', 'phone' => '08030000001']));

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/fittings', [
        'customer' => $customer->public_id,
        'scheduled_at' => now($tenant->timezone)->addDays(2)->setTime(11, 0)->format('Y-m-d H:i'),
    ])->assertCreated();

    $rows = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/fittings?from=' . now()->toDateString() . '&to=' . now()->addDays(6)->toDateString())
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['customer'])->toBe('Ada')
        ->and($rows[0]['status'])->toBe('scheduled');
});

it('moves a fitting with reschedule', function () {
    [$tenant, $owner] = makeHouse('Move House');
    $customer = CurrentTenant::run($tenant, fn () => Customer::create(['first_name' => 'Kemi', 'phone' => '08030000002']));
    $first = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/fittings', [
        'customer' => $customer->public_id,
        'scheduled_at' => now($tenant->timezone)->addDays(2)->setTime(10, 0)->format('Y-m-d H:i'),
    ])->assertCreated()->json('data');

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/fittings', [
        'customer' => $customer->public_id,
        'reschedule' => $first['id'],
        'scheduled_at' => now($tenant->timezone)->addDays(3)->setTime(15, 0)->format('Y-m-d H:i'),
    ])->assertCreated()->assertJsonPath('data.status', 'scheduled');

    $rows = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/fittings?from=' . now()->toDateString() . '&to=' . now()->addDays(6)->toDateString())->json('data'));
    expect($rows)->toHaveCount(1);   // the moved one is hidden
});

it('lists and reads notifications for the current house only', function () {
    [$tenant, $owner] = makeHouse('Bell House');
    $owner->notifications()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test', 'tenant_id' => $tenant->id,
        'data' => ['title' => 'Stock low', 'body' => 'Lace', 'url' => url('/orders/ABC123')],
    ]);
    [$other] = makeHouse('Elsewhere');
    $owner->notifications()->create([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test', 'tenant_id' => $other->id,
        'data' => ['title' => 'Not mine'],
    ]);

    $res = $this->actingAs($owner, 'sanctum')->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/notifications')->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.target'))->toBe(['type' => 'order', 'id' => 'ABC123'])
        ->and($res->json('meta.unread'))->toBe(1);

    $id = $res->json('data.0.id');
    $this->actingAs($owner, 'sanctum')->withHeader('X-Tenant', $tenant->slug)->postJson("/api/v1/notifications/{$id}/read")->assertOk();
    expect($owner->fresh()->unreadNotifications()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('sends lookups with the current house', function () {
    [, $owner] = makeHouse('Lookup House');

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/tenants/current')
        ->assertOk()
        ->assertJsonPath('data.lookups.payment_methods.cash', 'Cash')
        ->assertJsonPath('data.lookups.order_transitions.draft', ['confirmed']);
});

it('adds next steps, money documents and fittings to the order detail', function () {
    [$tenant, $owner] = makeHouse('Order Extras');
    $customer = CurrentTenant::run($tenant, fn () => Customer::create(['first_name' => 'Tola', 'phone' => '08030000003']));

    $order = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/orders', [
        'customer_id' => $customer->public_id,
        'lines' => [['kind' => 'service', 'description' => 'Hemming', 'unit_price' => 5000]],
        'confirm' => true,
    ])->assertCreated()->json('data');

    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/payments', [
        'customer_id' => $customer->public_id, 'order_id' => $order['id'], 'amount' => 2000, 'method' => 'cash',
    ], ['Idempotency-Key' => 'k-' . uniqid()])->assertCreated();

    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/orders/{$order['id']}")
        ->assertOk()
        ->assertJsonPath('data.next_statuses', ['in_production'])
        ->assertJsonPath('data.paid', 2000)
        ->assertJsonPath('data.payments.0.amount', 2000)
        ->assertJsonStructure(['data' => ['invoices', 'fittings', 'risk_reasons']]);
});

it('shows one quotation with its lines', function () {
    [$tenant, $owner] = makeHouse('Quote House');
    $customer = CurrentTenant::run($tenant, fn () => Customer::create(['first_name' => 'Uche', 'phone' => '08030000004']));
    $q = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/quotations', [
        'customer_id' => $customer->public_id,
        'lines' => [['kind' => 'service', 'description' => 'Agbada embroidery', 'unit_price' => 30000]],
    ])->assertCreated()->json('data');

    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/quotations/{$q['id']}")
        ->assertOk()->assertJsonPath('data.lines.0.description', 'Agbada embroidery');
});
