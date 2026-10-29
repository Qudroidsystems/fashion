<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\OutgoingMessage;
use App\Services\Messaging\CustomerUpdates;
use App\Services\Messaging\Messenger;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutDefer();   // run "after the response" work straight away
    Notification::fake();
    $GLOBALS['termii_fails'] = false;
    Http::fake(['api.ng.termii.com/*' => fn () => $GLOBALS['termii_fails']
        ? Http::response(['message' => 'Insufficient balance'], 400)
        : Http::response(['code' => 'ok', 'message_id' => '1'])]);
    [$this->house, $this->owner] = makeHouse('Updates House');
    $this->customer = CurrentTenant::run($this->house, fn () => Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08035550000', 'email' => 'ngozi@example.com']));
    $this->main = CurrentTenant::run($this->house, fn () => Branch::orderBy('id')->first());
    CurrentTenant::run($this->house, fn () => Messenger::saveSettings(['sms_provider' => 'termii', 'sms_sender' => 'Updates', 'termii_key' => 'TL-x']));
});

afterEach(fn () => Carbon::setTestNow());

function newOrder($test, float $price = 100000): Order
{
    $test->actingAs($test->owner);

    return CurrentTenant::run($test->house, fn () => app(OrderService::class)->saveOrder(null, ['customer_id' => $test->customer->id, 'branch_id' => $test->main->id],
        [['kind' => 'service', 'description' => 'Agbada', 'quantity' => 1, 'unit_price' => $price]]));
}

function outbox($test)
{
    return CurrentTenant::run($test->house, fn () => OutgoingMessage::orderBy('id')->get());
}

it('tells the customer by SMS when the order is confirmed and when they pay — one message each', function () {
    $order = newOrder($this);
    CurrentTenant::run($this->house, fn () => app(OrderService::class)->transition($order, 'confirmed'));
    CurrentTenant::run($this->house, fn () => app(BillingService::class)->recordPayment([
        'customer_id' => $this->customer->id, 'amount' => 40000, 'method' => 'transfer', 'order_id' => $order->id, 'branch_id' => $this->main->id,
    ]));

    $msgs = outbox($this);
    expect($msgs->pluck('purpose')->all())->toBe(['order_confirmed', 'payment_received'])
        ->and($msgs->pluck('status')->unique()->all())->toBe(['sent'])
        ->and($msgs[0]->body)->toContain('Hello Ngozi')->toContain($order->number)
        ->and($msgs[1]->body)->toContain('Balance on ' . $order->number)->toContain('60,000');

    // WhatsApp isn't set up, so SMS is first that works — and e-mail is not sent on top.
    $logs = CurrentTenant::run($this->house, fn () => MessageLog::orderBy('id')->get());
    expect($logs->where('status', 'sent')->pluck('channel')->all())->toBe(['sms', 'sms']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'termii') && $r['to'] === '2348035550000');
});

it('says when the order is ready, only once, and the house can switch updates off or reword them', function () {
    $order = newOrder($this);
    $u = fn () => app(CustomerUpdates::class);
    CurrentTenant::run($this->house, fn () => $u()->orderStatusChanged($order, 'ready'));
    CurrentTenant::run($this->house, fn () => $u()->orderStatusChanged($order, 'ready'));   // after an alteration
    expect(outbox($this)->where('purpose', 'order_ready'))->toHaveCount(1)
        ->and(outbox($this)->first()->body)->toContain('agbada')->toContain('ready for collection');

    $this->actingAs($this->owner)->put('/settings/customer-messages', [
        'events' => ['order_confirmed' => ['on' => 0], 'payment_received' => ['on' => 1, 'text' => 'Thanks {first_name}! We got {amount}. {balance_line}'],
            'order_ready' => ['on' => 1], 'fitting_reminder' => ['on' => 1], 'balance_reminder' => ['on' => 1], 'order_delivered' => ['on' => 0]],
        'channels' => ['email', '', ''], 'first_only' => 1, 'balance_after_days' => 3, 'balance_repeat_days' => 7, 'balance_max' => 3,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->house->refresh();

    CurrentTenant::run($this->house, fn () => app(OrderService::class)->transition($order, 'confirmed'));
    CurrentTenant::run($this->house, fn () => app(BillingService::class)->recordPayment(['customer_id' => $this->customer->id, 'amount' => 100000, 'method' => 'cash', 'order_id' => $order->id]));
    $paid = outbox($this)->firstWhere('purpose', 'payment_received');
    expect(outbox($this)->where('purpose', 'order_confirmed'))->toHaveCount(0)
        ->and($paid->body)->toBe("Thanks Ngozi! We got ₦100,000. Your order {$order->number} is fully paid. Thank you!")
        ->and($paid->channels)->toBe(['email']);

    $this->actingAs($this->owner)->get('/settings/customer-messages')->assertOk()->assertSee('Ready for collection')->assertSee('{balance_line}');
});

it('reminds about a balance after the order is ready, repeats, and stops when paid', function () {
    $order = newOrder($this, 50000);
    CurrentTenant::run($this->house, function () use ($order) {
        $order->forceFill(['status' => 'ready'])->save();
        OrderStatusEvent::create(['order_id' => $order->id, 'from_status' => 'in_production', 'to_status' => 'ready', 'customer_visible' => true]);
    });
    $u = fn () => app(CustomerUpdates::class);
    $at = fn (int $days) => CarbonImmutable::now($this->house->timezone)->addDays($days)->setTime(10, 0);

    expect(CurrentTenant::run($this->house, fn () => $u()->balanceReminders($at(1))))->toBe(0)       // too soon
        ->and(CurrentTenant::run($this->house, fn () => $u()->balanceReminders($at(3)->setTime(22, 0))))->toBe(0); // night
    Carbon::setTestNow($at(3));
    expect(CurrentTenant::run($this->house, fn () => $u()->balanceReminders($at(3))))->toBe(1)
        ->and(CurrentTenant::run($this->house, fn () => $u()->balanceReminders($at(4))))->toBe(0);
    expect(outbox($this)->last()->body)->toContain('₦50,000 is still due on order ' . $order->number);

    Carbon::setTestNow($at(10));
    expect(CurrentTenant::run($this->house, fn () => $u()->balanceReminders($at(10))))->toBe(1);

    CurrentTenant::run($this->house, fn () => app(BillingService::class)->recordPayment(['customer_id' => $this->customer->id, 'amount' => 50000, 'method' => 'cash', 'order_id' => $order->id]));
    Carbon::setTestNow($at(20));
    expect(CurrentTenant::run($this->house, fn () => $u()->balanceReminders($at(20))))->toBe(0);
});

it('retries a message that failed, and gives up after three tries', function () {
    $GLOBALS['termii_fails'] = true;
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->saveSettings(['events' => ['order_ready' => ['on' => 1]], 'channels' => ['sms'], 'first_only' => 1]));
    $order = newOrder($this);
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->orderStatusChanged($order, 'ready'));

    $m = outbox($this)->first();
    expect($m->status)->toBe('queued')->and($m->attempts)->toBe(1)->and($m->result)->toContain('Insufficient balance');

    Carbon::setTestNow(now()->addMinutes(11));
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->sendWaiting());
    Carbon::setTestNow(now()->addMinutes(30));
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->sendWaiting());
    expect($m->fresh()->status)->toBe('failed')->and($m->fresh()->attempts)->toBe(3);

    // A customer with no phone isn't retried at all.
    $this->customer->forceFill(['phone' => null])->save();
    $o2 = newOrder($this);
    CurrentTenant::run($this->house, fn () => app(CustomerUpdates::class)->orderStatusChanged($o2, 'ready'));
    expect(outbox($this)->last()->status)->toBe('failed')->and(outbox($this)->last()->result)->toContain('No phone number');
});

it('lets staff message the customer from the order and the customer page, and shows what was sent', function () {
    $order = newOrder($this);
    CurrentTenant::run($this->house, fn () => app(OrderService::class)->transition($order, 'confirmed'));

    $this->actingAs($this->owner)->get("/orders/{$order->public_id}")->assertOk()->assertSee('Messages to Ngozi')->assertSee('Order confirmed');
    $this->actingAs($this->owner)->post("/orders/{$order->public_id}/messages", ['text' => 'Your cap fabric arrived today.'])->assertRedirect()->assertSessionHas('success');
    $this->actingAs($this->owner)->post("/customers/{$this->customer->public_id}/messages", ['text' => 'Happy birthday!'])->assertRedirect();

    $custom = outbox($this)->where('purpose', 'custom');
    expect($custom)->toHaveCount(2)->and($custom->first()->created_by)->toBe($this->owner->id)->and($custom->first()->status)->toBe('sent');
    $this->actingAs($this->owner)->get("/customers/{$this->customer->public_id}")->assertOk()->assertSee('Happy birthday!');

    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->post("/orders/{$order->public_id}/messages", ['text' => 'hi'])->assertForbidden();
    $this->actingAs($tailor)->get('/settings/customer-messages')->assertForbidden();
});

it('never lets a messaging problem stop an order from saving', function () {
    CurrentTenant::run($this->house, function () {
        $t = \App\Support\Tenancy\CurrentTenant::get();
        $t->update(['settings' => array_merge($t->settings ?? [], ['customer_updates' => 'broken'])]);
    });
    $order = newOrder($this);
    CurrentTenant::run($this->house, fn () => app(OrderService::class)->transition($order, 'confirmed'));
    expect($order->fresh()->status)->toBe('confirmed');
});

it('shows the order’s messages in the staff app and sends one from there', function () {
    $order = newOrder($this);
    CurrentTenant::run($this->house, fn () => app(OrderService::class)->transition($order, 'confirmed'));

    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/orders/{$order->public_id}")->assertOk()
        ->assertJsonPath('data.messages.0.label', 'Order confirmed')->assertJsonPath('data.messages.0.status', 'sent')
        ->assertJsonPath('data.message_drafts.0.label', 'Ready for collection');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/orders/{$order->public_id}/messages", ['text' => 'See you Saturday'])
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.by', $this->owner->name);
});
