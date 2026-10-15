<?php

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\FollowUpTask;
use App\Support\Tenancy\CurrentTenant;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
});

function customerPayload(array $over = []): array
{
    return array_merge([
        'first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '0803 123 4567',
        'email' => 'ngozi@example.com', 'gender' => 'female', 'birthday' => '1990-05-12',
        'tags' => 'bride, referral', 'is_vip' => '1', 'preferences' => ['fit' => 'Fitted'],
        'consents' => ['sms' => ['transactional' => '1', 'marketing' => '0']],
    ], $over);
}

it('creates a customer with an automatic number, tags and consent', function () {
    $this->actingAs($this->owner)->post('/customers', customerPayload())->assertRedirect();

    $c = Customer::withoutGlobalScopes()->where('tenant_id', $this->house->id)->first();
    expect($c->customer_number)->toBe('CUS-00001')
        ->and($c->phone_normalized)->toBe('8031234567')
        ->and($c->is_vip)->toBeTrue()
        ->and($c->tags()->pluck('name')->sort()->values()->all())->toBe(['bride', 'referral'])
        ->and($c->consents()->where('channel', 'sms')->where('purpose', 'marketing')->value('granted'))->toBeFalse();

    $this->actingAs($this->owner)->get('/customers/' . $c->public_id)->assertOk()->assertSee('Ngozi Eze')->assertSee('CUS-00001');
    $this->actingAs($this->owner)->get('/customers')->assertOk()->assertSee('Ngozi Eze');
});

it('numbers customers per house independently', function () {
    [$other, $otherOwner] = makeHouse('Other');

    $this->actingAs($this->owner)->post('/customers', customerPayload());
    $this->actingAs($this->owner)->post('/customers', customerPayload(['first_name' => 'Ada', 'phone' => '0811111111', 'email' => 'ada@x.com', 'last_name' => 'Obi']));
    $this->actingAs($otherOwner)->post('/customers', customerPayload());

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $this->house->id)->orderBy('id')->pluck('customer_number')->all())->toBe(['CUS-00001', 'CUS-00002'])
        ->and(Customer::withoutGlobalScopes()->where('tenant_id', $other->id)->value('customer_number'))->toBe('CUS-00001');
});

it('warns about a possible duplicate and allows saving anyway', function () {
    $this->actingAs($this->owner)->post('/customers', customerPayload());

    $this->actingAs($this->owner)->post('/customers', customerPayload(['first_name' => 'N.', 'email' => null, 'phone' => '+234 803 123 4567']))
        ->assertSessionHas('duplicates');
    expect(Customer::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAs($this->owner)->post('/customers', customerPayload(['first_name' => 'N.', 'email' => null, 'phone' => '+234 803 123 4567', 'confirm_duplicate' => '1']))
        ->assertRedirect();
    expect(Customer::withoutGlobalScopes()->count())->toBe(2);
});

it('does not flag duplicates from another house', function () {
    [$other, $otherOwner] = makeHouse('Other');
    $this->actingAs($otherOwner)->post('/customers', customerPayload());

    $this->actingAs($this->owner)->post('/customers', customerPayload())->assertSessionMissing('duplicates');
});

it('merges a duplicate into the kept record', function () {
    $this->actingAs($this->owner)->post('/customers', customerPayload(['tags' => 'bride']));
    $this->actingAs($this->owner)->post('/customers', customerPayload(['first_name' => 'Ngozi', 'last_name' => 'Eze-Okafor', 'email' => 'other@x.com', 'whatsapp' => '0900000000', 'tags' => 'corporate', 'confirm_duplicate' => '1']));
    [$keep, $dupe] = Customer::withoutGlobalScopes()->orderBy('id')->get()->all();

    CurrentTenant::run($this->house, fn () => CustomerNote::create(['customer_id' => $dupe->id, 'body' => 'Likes ankara']));
    CurrentTenant::context()->clear();

    $this->actingAs($this->owner)->post("/customers/{$dupe->public_id}/merge", ['target' => $keep->public_id])
        ->assertRedirect("/customers/{$keep->public_id}");

    $keep->refresh(); $dupe->refresh();
    expect($dupe->status)->toBe('merged')->and($dupe->merged_into_id)->toBe($keep->id)
        ->and($keep->whatsapp)->toBe('0900000000')
        ->and($keep->tags()->pluck('name')->sort()->values()->all())->toBe(['bride', 'corporate'])
        ->and(CustomerNote::withoutGlobalScopes()->where('customer_id', $keep->id)->where('body', 'Likes ankara')->exists())->toBeTrue();
});

it('hides sensitive notes from roles without permission', function () {
    $this->actingAs($this->owner)->post('/customers', customerPayload());
    $c = Customer::withoutGlobalScopes()->first();

    $this->actingAs($this->owner)->post("/customers/{$c->public_id}/notes", ['body' => 'Secret: pregnant, adjust waist', 'is_sensitive' => '1']);
    $this->actingAs($this->owner)->post("/customers/{$c->public_id}/notes", ['body' => 'Prefers Saturday fittings']);

    $tailor = addStaff($this->house, 'Tailor');   // can view customers, not sensitive notes
    $this->actingAs($tailor)->get("/customers/{$c->public_id}")
        ->assertOk()->assertSee('Prefers Saturday fittings')->assertDontSee('Secret: pregnant')->assertSee('1 sensitive note');

    $this->actingAs($this->owner)->get("/customers/{$c->public_id}")->assertSee('Secret: pregnant');
});

it('tracks follow-ups and the overdue queue', function () {
    $this->actingAs($this->owner)->post('/customers', customerPayload());
    $c = Customer::withoutGlobalScopes()->first();

    $this->actingAs($this->owner)->post("/customers/{$c->public_id}/follow-ups", ['title' => 'Call about fitting', 'due_at' => now()->subDay()->format('Y-m-d\TH:i')])->assertRedirect();
    $task = FollowUpTask::withoutGlobalScopes()->first();
    expect($task->assigned_to)->toBe($this->owner->id);

    $this->actingAs($this->owner)->get('/follow-ups?show=overdue')->assertOk()->assertSee('Call about fitting');
    $this->actingAs($this->owner)->patch("/follow-ups/{$task->id}", ['status' => 'done'])->assertRedirect();
    expect($task->fresh()->status)->toBe('done');
});

it('isolates customers between houses', function () {
    [$other, $otherOwner] = makeHouse('Other');
    $this->actingAs($otherOwner)->post('/customers', customerPayload(['first_name' => 'Zebulon']));
    $theirs = Customer::withoutGlobalScopes()->where('tenant_id', $other->id)->first();
    $this->flushSession();   // drop the "Zebulon added" flash message of the other user's request

    $this->actingAs($this->owner)->get('/customers')->assertDontSee('Zebulon');
    $this->actingAs($this->owner)->get("/customers/{$theirs->public_id}")->assertNotFound();
    $this->actingAs($this->owner)->put("/customers/{$theirs->public_id}", customerPayload())->assertNotFound();
    $this->actingAs($this->owner)->post("/customers/{$theirs->public_id}/notes", ['body' => 'x'])->assertNotFound();
    $this->actingAs($this->owner)->getJson('/search?q=Zebulon')->assertJsonCount(0, 'results');
    $this->actingAs($this->owner)->get('/customers/export')->assertOk()->assertDontSee('Zebulon');
});

it('blocks roles without customer access', function () {
    $store = addStaff($this->house, 'Storekeeper');
    $this->actingAs($store)->get('/customers')->assertForbidden();

    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->get('/customers')->assertOk();
    $this->actingAs($tailor)->post('/customers', customerPayload())->assertForbidden();
});

it('exposes customers over the API with duplicate check and idempotency', function () {
    $token = $this->owner->createToken('test')->plainTextToken;
    $payload = ['first_name' => 'Tola', 'phone' => '08055555555'];

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'abc123456789')->postJson('/api/v1/customers', $payload)
        ->assertCreated()->json('data');
    // A retry with the same key replays the response instead of creating a second customer.
    $this->withToken($token)->withHeader('Idempotency-Key', 'abc123456789')->postJson('/api/v1/customers', $payload)
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(Customer::withoutGlobalScopes()->count())->toBe(1);

    // Without a key, the duplicate check answers 409.
    $this->flushHeaders()->withToken($token)->postJson('/api/v1/customers', $payload)->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');

    $this->withToken($token)->patchJson("/api/v1/customers/{$first['id']}", ['is_vip' => true])->assertOk()->assertJsonPath('data.is_vip', true);
    $this->withToken($token)->getJson('/api/v1/customers?q=Tola')->assertOk()->assertJsonPath('meta.total', 1);
    $this->withToken($token)->getJson("/api/v1/customers/{$first['id']}/timeline")->assertOk();
});
