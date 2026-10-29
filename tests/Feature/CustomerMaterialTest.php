<?php

use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\MessageLog;
use App\Services\Materials\MaterialService;
use App\Services\Messaging\Messenger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

afterEach(fn () => Carbon::setTestNow());

function materialHouse(): array
{
    [$tenant, $owner] = makeHouse('Material House');
    $customer = CurrentTenant::run($tenant, fn () => Customer::create(['first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '08031112222', 'email' => 'ada@example.com']));

    return [$tenant, $owner, $customer];
}

function withTermii($tenant): void
{
    CurrentTenant::run($tenant, fn () => Messenger::saveSettings(['sms_provider' => 'termii', 'sms_sender' => 'FashionHse', 'termii_key' => 'TL-key']));
}

it('receives a customer’s material with a tag number, photos and a shelf', function () {
    Storage::fake('public');
    [$tenant, $owner, $customer] = materialHouse();

    $this->actingAs($owner)->post('/materials', [
        'customer_id' => $customer->id, 'kind' => 'lace', 'purpose' => 'sew', 'description' => 'Swiss lace',
        'colour' => 'Gold', 'quantity' => '5', 'unit' => 'yd', 'location' => 'Shelf B2',
        'photos' => [UploadedFile::fake()->image('lace.jpg')],
    ])->assertRedirect();

    $m = CurrentTenant::run($tenant, fn () => CustomerMaterial::first());
    expect($m->number)->toStartWith('MAT-')
        ->and($m->status)->toBe('held')
        ->and($m->summary)->toBe('5 yards Gold Swiss lace')
        ->and($m->photos)->toHaveCount(1);
    Storage::disk('public')->assertExists($m->photos[0]);

    $this->actingAs($owner)->get('/materials')->assertOk()->assertSee($m->number)->assertSee('Shelf B2');
    $this->actingAs($owner)->get("/materials/{$m->public_id}")->assertOk()->assertSee('Swiss lace')->assertSee('Received');
    $this->actingAs($owner)->get("/materials/{$m->public_id}/tag")->assertOk()->assertSee($m->number)->assertSee('<svg', false);
    $this->actingAs($owner)->get("/customers/{$customer->public_id}")->assertOk()->assertSee($m->number);
});

it('keeps it with the house until it is given back, and records who collected it', function () {
    [$tenant, $owner, $customer] = materialHouse();
    $m = CurrentTenant::run($tenant, fn () => app(MaterialService::class)->receive(['customer_id' => $customer->id, 'kind' => 'fabric', 'description' => 'Ankara'], $owner));

    $this->actingAs($owner)->post("/materials/{$m->public_id}/move", ['to' => 'in_use', 'location' => 'With Bisi'])->assertRedirect();
    expect($m->fresh()->status)->toBe('in_use');

    $this->actingAs($owner)->post('/materials/return', [
        'materials' => [$m->public_id], 'returned_to' => 'Ada Obi', 'quantities' => [$m->public_id => '1.5'],
    ])->assertRedirect();
    $m->refresh();
    expect($m->status)->toBe('returned')->and($m->returned_to)->toBe('Ada Obi')->and($m->returned_quantity)->toBe(1.5);

    $this->actingAs($owner)->post('/materials/return', ['materials' => [$m->public_id], 'returned_to' => 'Ada'])->assertSessionHasErrors('materials');
});

it('reminds the customer by e-mail and SMS once an order is handed over and the fabric is still here', function () {
    Notification::fake();
    Http::fake(['api.ng.termii.com/*' => Http::response(['code' => 'ok', 'message_id' => '1'])]);
    [$tenant, $owner, $customer] = materialHouse();
    withTermii($tenant);
    Carbon::setTestNow(now($tenant->timezone)->setTime(10, 0)->utc());

    $m = CurrentTenant::run($tenant, fn () => app(MaterialService::class)->receive([
        'customer_id' => $customer->id, 'kind' => 'lace', 'description' => 'Lace', 'collect_by' => now($tenant->timezone)->subDays(3)->toDateString(),
    ], $owner));

    $n = CurrentTenant::run($tenant, fn () => app(MaterialService::class)->sendDue());
    expect($n)->toBe(1);
    $logs = CurrentTenant::run($tenant, fn () => MessageLog::orderBy('channel')->get());
    expect($logs->pluck('status', 'channel')->all())->toBe(['email' => 'sent', 'sms' => 'sent', 'whatsapp' => 'skipped'])
        ->and($logs->first()->body)->toContain($m->number)->toContain('Hello Ada');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'termii') && $r['to'] === '2348031112222' && $r['from'] === 'FashionHse');
    expect($m->fresh()->reminders_sent)->toBe(1);

    // Not again the same day, nor before the repeat interval.
    expect(CurrentTenant::run($tenant, fn () => app(MaterialService::class)->sendDue(\Carbon\CarbonImmutable::now()->addDays(3))))->toBe(0);
    expect(CurrentTenant::run($tenant, fn () => app(MaterialService::class)->sendDue(\Carbon\CarbonImmutable::now()->addDays(7))))->toBe(1);

    // Staff got the daily list.
    Notification::assertSentTo($owner, \App\Notifications\MaterialsAwaitingReturn::class);
});

it('does not remind while the order is still being made, nor at night, nor someone who said no', function () {
    Notification::fake();
    Http::fake();
    [$tenant, $owner, $customer] = materialHouse();
    withTermii($tenant);
    $svc = fn () => app(MaterialService::class);
    $order = CurrentTenant::run($tenant, fn () => \App\Models\Order::create([
        'number' => 'ORD-T-1', 'customer_id' => $customer->id, 'status' => 'in_production', 'total' => 1000,
    ]));
    $m = CurrentTenant::run($tenant, fn () => $svc()->receive(['customer_id' => $customer->id, 'order_id' => $order->id, 'kind' => 'fabric', 'description' => 'Ankara'], $owner));
    $day = \Carbon\CarbonImmutable::now($tenant->timezone)->setTime(11, 0)->addDays(30);
    expect(CurrentTenant::run($tenant, fn () => $svc()->dueOn($m->fresh())))->toBeNull()
        ->and(CurrentTenant::run($tenant, fn () => $svc()->sendDue($day)))->toBe(0);

    // Order handed over: now due.
    $order->forceFill(['status' => 'delivered'])->save();
    expect(CurrentTenant::run($tenant, fn () => $svc()->sendDue($day->setTime(23, 0))))->toBe(0);     // night
    CurrentTenant::run($tenant, fn () => app(\App\Services\Crm\CustomerService::class)->syncConsents($customer, ['sms' => ['transactional' => false], 'email' => ['transactional' => false], 'whatsapp' => ['transactional' => false]]));
    expect(CurrentTenant::run($tenant, fn () => $svc()->sendDue($day)))->toBe(1);
    $statuses = CurrentTenant::run($tenant, fn () => MessageLog::pluck('status')->unique()->values()->all());
    expect($statuses)->toBe(['skipped']);
    expect($m->fresh()->reminders_sent)->toBe(0);
    Http::assertNothingSent();
});

it('gives leftover material back with the garment at handover', function () {
    [$tenant, $owner, $customer] = materialHouse();
    $order = CurrentTenant::run($tenant, function () use ($customer) {
        $o = \App\Models\Order::create(['number' => 'ORD-T-2', 'customer_id' => $customer->id, 'status' => 'ready', 'total' => 0]);
        $o->lines()->create(['description' => 'Agbada', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0, 'status' => 'ready']);

        return $o;
    });
    $m = CurrentTenant::run($tenant, fn () => app(MaterialService::class)->receive(['customer_id' => $customer->id, 'order_id' => $order->id, 'kind' => 'fabric', 'description' => 'Guinea'], $owner));

    $this->actingAs($owner)->get("/orders/{$order->public_id}")->assertOk()->assertSee($m->number)->assertSee('Give back with this handover');
    $this->actingAs($owner)->post("/orders/{$order->public_id}/deliveries", [
        'line_ids' => [$order->lines()->first()->id], 'method' => 'collection', 'recipient_name' => 'Ada Obi', 'confirmation' => 'signature',
        'material_ids' => [$m->public_id],
    ])->assertRedirect();

    $m->refresh();
    expect($m->status)->toBe('returned')->and($m->delivery_id)->not->toBeNull()->and($m->returned_to)->toBe('Ada Obi');
});

it('lets staff remind a customer now, and only the right people manage materials', function () {
    Notification::fake();
    [$tenant, $owner, $customer] = materialHouse();
    $m = CurrentTenant::run($tenant, fn () => app(MaterialService::class)->receive(['customer_id' => $customer->id, 'kind' => 'garment', 'purpose' => 'alteration', 'description' => 'Wedding gown'], $owner));

    $this->actingAs($owner)->post("/customers/{$customer->public_id}/materials/remind", ['channels' => ['email']])->assertRedirect()->assertSessionHas('success');
    expect(CurrentTenant::run($tenant, fn () => MessageLog::where('status', 'sent')->count()))->toBe(1);

    $tailor = addStaff($tenant, 'Tailor');
    $this->actingAs($tailor)->get('/materials')->assertOk();
    $this->actingAs($tailor)->post("/materials/{$m->public_id}/close", ['how' => 'used', 'note' => ''])->assertForbidden();

    $accountant = addStaff($tenant, 'Accountant');
    $this->actingAs($accountant)->get('/materials')->assertForbidden();

    // Another house can't see it.
    [, $other] = makeHouse('Other House');
    $this->actingAs($other)->get("/materials/{$m->public_id}")->assertNotFound();
});

it('sends by WhatsApp with the approved template, and shows the messaging settings and log', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
    [$tenant, $owner, $customer] = materialHouse();
    CurrentTenant::run($tenant, function () use ($tenant) {
        $s = $tenant->settings ?? [];
        data_set($s, 'store.whatsapp.cloud_token', \Illuminate\Support\Facades\Crypt::encryptString('TOKEN'));
        data_set($s, 'store.whatsapp.cloud_phone_id', '123');
        $tenant->update(['settings' => $s]);
    });
    $this->actingAs($owner)->put('/settings/messaging', ['sms_provider' => 'none', 'whatsapp_template' => 'material_reminder', 'whatsapp_lang' => 'en'])->assertRedirect();
    $m = CurrentTenant::run($tenant, fn () => app(MaterialService::class)->receive(['customer_id' => $customer->id, 'kind' => 'lace', 'description' => 'Lace'], $owner));

    $this->actingAs($owner)->post("/customers/{$customer->public_id}/materials/remind", ['channels' => ['whatsapp']])->assertSessionHas('success');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/123/messages') && $r['type'] === 'template'
        && $r['template']['name'] === 'material_reminder' && $r['template']['components'][0]['parameters'][0]['text'] === 'Ada');

    $this->actingAs($owner)->get('/settings/messaging')->assertOk()->assertSee('Recent messages')->assertSee('Ada Obi');
    $this->actingAs($owner)->get('/materials/create?customer=' . $customer->public_id)->assertOk()->assertSee('Take photo')->assertSee('Choose pictures')->assertSee('getUserMedia');
    $this->actingAs($owner)->get("/materials/{$m->public_id}/edit")->assertOk();
    $this->actingAs($owner)->get('/materials?show=all&q=Lace')->assertOk()->assertSee($m->number);
});

it('works from the staff app: receive with photos, scan the tag, return, remind', function () {
    Storage::fake('public');
    Notification::fake();
    [$tenant, $owner, $customer] = materialHouse();

    $res = $this->actingAs($owner, 'sanctum')->post('/api/v1/materials', [
        'customer' => $customer->public_id, 'kind' => 'aso_oke', 'description' => 'Aso-oke', 'colour' => 'Navy', 'quantity' => 3, 'unit' => 'pcs',
        'location' => 'Rack 1', 'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ], ['Accept' => 'application/json'])->assertCreated();
    $id = $res->json('data.id');
    expect($res->json('data.photos'))->toHaveCount(2)->and($res->json('data.number'))->toStartWith('MAT-');

    // Scanning the tag's QR (a link to the record) finds it; so does the number.
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/materials/lookup?code=' . urlencode(route('materials.show', $id)))->assertOk()->assertJsonPath('data.id', $id);
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/materials/lookup?code=' . strtolower($res->json('data.number')))->assertOk()->assertJsonPath('data.id', $id);
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/materials?customer=' . $customer->public_id)->assertOk()->assertJsonPath('meta.counts.with_house', 1);
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/materials/meta')->assertOk()->assertJsonStructure(['data' => ['kinds', 'channels']]);

    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/customers/{$customer->public_id}/materials/remind", ['channels' => ['email']])->assertOk()->assertJsonPath('data.sent.0', 'email');
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/materials/return', ['materials' => [$id], 'returned_to' => 'Ada'])->assertOk();
    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/materials/{$id}")->assertOk()->assertJsonPath('data.status', 'returned')->assertJsonPath('data.with_house', false);
});
