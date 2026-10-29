<?php

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\OutgoingMessage;
use App\Services\Messaging\CustomerUpdates;
use App\Services\Messaging\Messenger;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutDefer();
    Notification::fake();
    Http::fake(['api.ng.termii.com/*' => Http::response(['code' => 'ok', 'message_id' => '1'])]);
    [$this->house, $this->owner] = makeHouse('Promo House');
    CurrentTenant::run($this->house, fn () => Messenger::saveSettings(['sms_provider' => 'termii', 'sms_sender' => 'Promo', 'termii_key' => 'TL-x']));
    $make = function (string $name, array $consents, array $attrs = []) {
        return CurrentTenant::run($this->house, function () use ($name, $consents, $attrs) {
            $c = Customer::create(['first_name' => $name, 'phone' => '0803' . random_int(1000000, 9999999)] + $attrs);
            foreach ($consents as $channel => $yes) {
                CustomerConsent::create(['customer_id' => $c->id, 'channel' => $channel, 'purpose' => 'marketing', 'granted' => $yes, 'recorded_at' => now()]);
            }

            return $c;
        });
    };
    $this->yes = $make('Yemi', ['sms' => true]);
    $this->vip = $make('Vicky', ['sms' => true], ['is_vip' => true]);
    $this->no = $make('Nora', ['sms' => false]);
    $this->unasked = $make('Uche', []);
});

afterEach(fn () => Carbon::setTestNow());

it('only reaches customers who agreed to promotions, and sends them steadily from the outbox', function () {
    $this->actingAs($this->owner)->getJson('/campaigns/audience?audience_type=all&channels[]=sms')->assertOk()->assertJson(['in_group' => 4, 'reachable' => 2]);
    $this->actingAs($this->owner)->getJson('/campaigns/audience?audience_type=vip&channels[]=sms')->assertJson(['in_group' => 1, 'reachable' => 1]);

    $this->actingAs($this->owner)->post('/campaigns', [
        'name' => 'Christmas', 'body' => 'Hello {first_name}, 10% off at {house} this week!', 'audience_type' => 'all', 'channels' => ['sms'], 'when' => 'now',
    ])->assertRedirect()->assertSessionHas('success');

    $c = CurrentTenant::run($this->house, fn () => Campaign::first());
    expect($c->recipients)->toBe(2)->and($c->status)->toBe('sending');
    $msgs = CurrentTenant::run($this->house, fn () => OutgoingMessage::where('purpose', 'campaign')->get());
    expect($msgs->pluck('customer_id')->sort()->values()->all())->toBe(collect([$this->yes->id, $this->vip->id])->sort()->values()->all())
        ->and($msgs->first()->status)->toBe('queued')                                       // not sent inside the request
        ->and($msgs->firstWhere('customer_id', $this->yes->id)->body)->toStartWith('Hello Yemi, 10% off at Promo House')->toContain('reply STOP');

    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->sendWaiting());
    $this->actingAs($this->owner)->get("/campaigns/{$c->public_id}")->assertOk()->assertSee('Yemi')->assertSee('Sent');
    expect($c->fresh()->status)->toBe('sent');
});

it('re-checks consent when each message goes, and can be scheduled or stopped', function () {
    $this->actingAs($this->owner)->post('/campaigns', [
        'name' => 'New collection', 'body' => 'Our new collection is in.', 'audience_type' => 'all', 'channels' => ['sms'],
        'when' => 'later', 'send_at' => now($this->house->timezone)->addDays(2)->format('Y-m-d\TH:i'),
    ])->assertRedirect();
    $c = CurrentTenant::run($this->house, fn () => Campaign::first());
    expect($c->status)->toBe('scheduled');

    // Yemi changes their mind before it goes.
    CurrentTenant::run($this->house, fn () => CustomerConsent::where(['customer_id' => $this->yes->id, 'channel' => 'sms', 'purpose' => 'marketing'])->update(['granted' => false]));
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->sendWaiting());
    expect(CurrentTenant::run($this->house, fn () => OutgoingMessage::where('status', 'sent')->count()))->toBe(0);   // not yet

    Carbon::setTestNow(now()->addDays(2)->addHour());
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->sendWaiting());
    $byCustomer = CurrentTenant::run($this->house, fn () => OutgoingMessage::where('purpose', 'campaign')->pluck('status', 'customer_id'));
    expect($byCustomer[$this->vip->id])->toBe('sent')->and($byCustomer[$this->yes->id])->toBe('failed');

    // Stopping a draft.
    $this->actingAs($this->owner)->post('/campaigns', ['name' => 'Draft', 'body' => 'x', 'audience_type' => 'vip', 'channels' => ['sms'], 'when' => 'draft'])->assertRedirect();
    $d = CurrentTenant::run($this->house, fn () => Campaign::where('name', 'Draft')->first());
    $this->actingAs($this->owner)->post("/campaigns/{$d->public_id}/cancel")->assertRedirect();
    expect($d->fresh()->status)->toBe('cancelled');
});

it('refuses a campaign nobody can receive, and only managers send campaigns', function () {
    $this->actingAs($this->owner)->post('/campaigns', ['name' => 'X', 'body' => 'x', 'audience_type' => 'all', 'channels' => ['email'], 'when' => 'now'])
        ->assertSessionHasErrors('audience');
    $cashier = addStaff($this->house, 'Cashier');
    $this->actingAs($cashier)->get('/campaigns')->assertForbidden();
    $this->actingAs($this->owner)->get('/campaigns/create')->assertOk()->assertSee('Who gets it');
});

it('wishes customers happy birthday once, unless they said no to promotions', function () {
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->saveSettings(['events' => ['birthday' => ['on' => 1]], 'channels' => ['sms'], 'first_only' => 1]));
    $today = CarbonImmutable::now($this->house->timezone)->setTime(10, 0);
    $this->unasked->forceFill(['birthday' => $today->subYears(30)->toDateString()])->save();
    $this->no->forceFill(['birthday' => $today->subYears(25)->toDateString()])->save();

    $u = fn () => app(CustomerUpdates::class);
    expect(CurrentTenant::run($this->house, fn () => $u()->birthdays($today)))->toBe(2)
        ->and(CurrentTenant::run($this->house, fn () => $u()->birthdays($today->addHour())))->toBe(0);
    $st = CurrentTenant::run($this->house, fn () => OutgoingMessage::where('purpose', 'birthday')->pluck('status', 'customer_id'));
    expect($st[$this->unasked->id])->toBe('sent')->and($st[$this->no->id])->toBe('failed');
});
