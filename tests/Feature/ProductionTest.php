<?php

use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\Fitting;
use App\Models\FittingReminder;
use App\Models\MeasurementTemplate;
use App\Models\Order;
use App\Models\ProductionEvent;
use App\Models\ProductionTask;
use App\Models\QualityCheck;
use App\Services\Catalogue\StyleService;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Services\Production\DeliveryService;
use App\Services\Production\FittingService;
use App\Services\Production\ProductionService;
use App\Services\Production\QualityService;
use App\Services\Production\RiskService;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
    $this->tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($this->owner);
    CurrentTenant::run($this->house, function () {
        $this->customer = Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08031234567', 'email' => 'ngozi@example.com']);
        $styles = app(StyleService::class);
        $style = $styles->create(['name' => 'Mermaid lace gown', 'audience' => 'female'], ['base_price' => 250000, 'lead_time_days' => 21],
            [MeasurementTemplate::where('code', 'womens_gown')->value('id')]);
        $styles->publish($style->draft()->first());
        $this->gown = $style->fresh('currentVersion');
    });
    CurrentTenant::context()->clear();
});

function inWorkroom($test, callable $fn, $user = null)
{
    $test->actingAs($user ?? $test->owner);

    return CurrentTenant::run($test->house, $fn);
}

/** A confirmed order: one gown, one hemming service, one charge. */
function confirmedOrder($test, array $extra = []): Order
{
    return inWorkroom($test, function () use ($test, $extra) {
        $svc = app(OrderService::class);
        $order = $svc->saveOrder(null, array_merge(['customer_id' => $test->customer->id, 'promised_date' => now()->addDays(30)->toDateString()], $extra), [
            ['kind' => 'bespoke', 'style_id' => $test->gown->id, 'quantity' => 1],
            ['kind' => 'service', 'description' => 'Hem trousers', 'quantity' => 1, 'unit_price' => 5000],
            ['kind' => 'charge', 'description' => 'Delivery', 'quantity' => 1, 'unit_price' => 2000],
        ]);

        return $svc->transition($order, 'confirmed');
    });
}

function gownTasks(Order $order)
{
    return ProductionTask::where('order_line_id', $order->lines[0]->id)->orderBy('position')->get();
}

it('plans each garment from its workflow, with due dates worked back from the promise date', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, function () use ($order) {
        $created = app(ProductionService::class)->planOrder($order, [], $this->owner);
        expect($created)->toHaveCount(7);   // 5 gown stages + 2 service stages; the charge needs no work

        $gown = gownTasks($order);
        expect($gown->pluck('name')->all())->toBe(['Cutting', 'Sewing', 'Fitting', 'Finishing', 'Quality check'])
            ->and($gown->pluck('status')->all())->toBe(['pending', 'blocked', 'blocked', 'blocked', 'blocked'])
            ->and($gown->last()->due_date->lt($order->promised_date))->toBeTrue()
            ->and($gown->first()->due_date->lte($gown[1]->due_date))->toBeTrue()
            ->and($order->fresh()->status)->toBe('in_production')
            ->and($order->lines[2]->fresh()->status)->toBe('ready');

        // Running it again adds nothing.
        expect(app(ProductionService::class)->planOrder($order->fresh(), [], $this->owner))->toHaveCount(0);
    });
});

it('moves work stage by stage, records who did what, and only lets tailors touch their own work', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, fn () => app(ProductionService::class)->planOrder($order, [], $this->owner));
    [$cut, $sew] = gownTasks($order);

    inWorkroom($this, function () use ($cut, $sew) {
        $svc = app(ProductionService::class);
        expect(fn () => $svc->start($sew, $this->tailor))->toThrow(ValidationException::class, 'waiting on “Cutting”');
        $svc->assign($cut, $this->tailor->id, $this->owner);
    });

    $other = addStaff($this->house, 'Tailor');
    inWorkroom($this, function () use ($cut, $sew, $other) {
        $svc = app(ProductionService::class);
        expect($svc->canWork($other, $cut->fresh()))->toBeFalse()
            ->and($svc->canWork($this->tailor, $cut->fresh()))->toBeTrue()
            ->and($svc->canWork($this->owner, $cut->fresh()))->toBeTrue();

        $svc->start($cut->fresh(), $this->tailor);
        $svc->complete($cut->fresh(), $this->tailor, 'Cut 4 yards');
        expect($sew->fresh()->status)->toBe('pending')
            ->and(ProductionEvent::where('task_id', $cut->id)->pluck('type')->all())->toBe(['created', 'assigned', 'started', 'completed'])
            ->and(ProductionEvent::where('task_id', $cut->id)->where('type', 'completed')->value('user_id'))->toBe($this->tailor->id);

        // History can't be rewritten.
        expect(fn () => ProductionEvent::first()->update(['note' => 'x']))->toThrow(LogicException::class);
    }, $this->tailor);
});

it('books fittings with reminders, and moving one cancels the old reminders (scenario 40)', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, function () use ($order) {
        app(ProductionService::class)->planOrder($order, [], $this->owner);
        $fits = app(FittingService::class);
        $f = $fits->schedule(['order_id' => $order->id, 'order_line_id' => $order->lines[0]->id,
            'scheduled_at' => now($this->house->timezone)->addDays(5)->setTime(11, 0)->toDateTimeString()], $this->owner);

        expect($f->reminders()->pluck('hours_before')->sort()->values()->all())->toBe([2, 24])
            ->and($f->task->name)->toBe('Fitting')
            ->and($order->fresh()->status)->toBe('fitting');

        $moved = $fits->reschedule($f, now($this->house->timezone)->addDays(8)->setTime(15, 0)->toDateTimeString(), 'Customer travelling', $this->owner);
        expect($f->fresh()->status)->toBe('rescheduled')
            ->and($f->reminders()->pluck('status')->unique()->all())->toBe(['cancelled'])
            ->and($moved->reminders()->where('status', 'pending')->count())->toBe(2)
            ->and($f->fresh()->rescheduled_to_id)->toBe($moved->id);

        // Only the new booking's reminders go out — to staff in-app, and to the customer by email with consent.
        CustomerConsent::create(['customer_id' => $this->customer->id, 'channel' => 'email', 'purpose' => 'transactional', 'granted' => true, 'recorded_at' => now()]);
        Notification::fake();
        Carbon::setTestNow(now($this->house->timezone)->addDays(8)->setTime(1, 0));   // house clock, so it holds near midnight UTC too
        expect($fits->sendDue())->toBe(1);
        Notification::assertSentOnDemand(\App\Notifications\FittingReminderDue::class, fn ($n, $ch, $notifiable) => $notifiable->routes['mail'] === 'ngozi@example.com');
        expect(FittingReminder::where('fitting_id', $f->id)->where('status', 'sent')->exists())->toBeFalse()
            ->and($fits->sendDue())->toBe(0);   // never twice
        Carbon::setTestNow();
    });
});

it('turns fitting alterations and failed quality checks into work that holds the garment back', function () {
    Notification::fake();
    $order = confirmedOrder($this);
    inWorkroom($this, function () use ($order) {
        $svc = app(ProductionService::class);
        $svc->planOrder($order, [], $this->owner);
        [$cut, $sew, $fit, $finish, $qc] = gownTasks($order);
        $svc->complete($cut, $this->owner);
        $svc->complete($sew->fresh(), $this->tailor, 'Sewn');

        $fits = app(FittingService::class);
        $f = $fits->schedule(['order_id' => $order->id, 'order_line_id' => $order->lines[0]->id, 'scheduled_at' => now()->addDay()->toDateTimeString()], $this->owner);
        $fits->record($f, 'attended', 'alterations', 'Waist loose', [
            ['name' => 'Take in waist 1"', 'responsible_party' => 'customer', 'cost_treatment' => 'free', 'assigned_to' => $this->tailor->id],
        ], $this->owner);
        $alt = ProductionTask::where('kind', 'alteration')->first();
        expect($fit->fresh()->status)->toBe('blocked')
            ->and($alt->blocks_task_id)->toBe($fit->id)
            ->and($alt->reason)->toBe('Found at fitting ' . $f->number)
            ->and($order->fresh()->status)->toBe('in_production');

        expect(fn () => $svc->complete($alt, $this->tailor))->toThrow(ValidationException::class);   // say what was done
        $svc->complete($alt, $this->tailor, 'Taken in');
        expect($fit->fresh()->status)->toBe('pending');

        $f2 = $fits->schedule(['order_id' => $order->id, 'order_line_id' => $order->lines[0]->id, 'scheduled_at' => now()->addDays(2)->toDateTimeString()], $this->owner);
        $fits->record($f2, 'attended', 'approved', 'Perfect', [], $this->owner);
        expect($fit->fresh()->status)->toBe('done')->and($finish->fresh()->status)->toBe('pending');
        $svc->complete($finish->fresh(), $this->tailor);

        // QC can't be ticked off directly; a failure creates rework for the last tailor.
        expect(fn () => $svc->complete($qc->fresh(), $this->owner))->toThrow(ValidationException::class);
        $qs = app(QualityService::class);
        $fail = $qs->record($qc->fresh(), null, [['passed' => true], ['passed' => true], ['passed' => false, 'note' => 'puckered side seam'], ['passed' => true], ['passed' => true], ['passed' => true]], null, $this->owner);
        $rework = $fail->reworkTask;
        expect($fail->result)->toBe('fail')
            ->and($rework->assigned_to)->toBe($this->tailor->id)
            ->and($rework->kind)->toBe('rework')
            ->and($qc->fresh()->status)->toBe('blocked');
        Notification::assertSentTo($this->tailor, \App\Notifications\QualityCheckFailed::class);

        $svc->complete($rework, $this->tailor, 'Resewn');
        $all = array_fill(0, 6, ['passed' => true]);
        expect($qs->record($qc->fresh(), null, $all, null, $this->owner)->result)->toBe('pass')
            ->and($order->lines[0]->fresh()->status)->toBe('ready')
            ->and($order->fresh()->status)->toBe('in_production');   // the hemming isn't finished yet

        foreach (ProductionTask::where('order_line_id', $order->lines[1]->id)->orderBy('position')->get() as $t) {
            $t->type === 'qc' ? $qs->record($t->fresh(), null, $all, null, $this->owner) : $svc->complete($t->fresh(), $this->owner);
        }
        expect($order->fresh()->status)->toBe('ready')
            ->and(QualityCheck::count())->toBe(3);
    });
});

it('hands over only finished garments, and only when paid unless the house allows a balance', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, function () use ($order) {
        $svc = app(ProductionService::class);
        $svc->planOrder($order, [0 => 0] + [$order->lines[0]->id => 0, $order->lines[1]->id => 0], $this->owner);   // nothing to make
        expect($order->fresh()->status)->toBe('ready');

        $handover = fn () => app(DeliveryService::class)->handOver($order->fresh(), ['line_ids' => $order->lines->pluck('id')->all(), 'recipient_name' => 'Ada (sister)', 'confirmation' => 'id_checked'], $this->owner);
        expect($handover)->toThrow(ValidationException::class, 'still owes');

        $this->house->update(['settings' => array_merge($this->house->settings, ['release_with_balance' => true])]);
        CurrentTenant::context()->clear();
    });
    inWorkroom($this, function () use ($order) {
        expect(fn () => app(DeliveryService::class)->handOver($order->fresh(), ['line_ids' => [$order->lines[0]->id], 'recipient_name' => 'Ada'], $this->owner))
            ->toThrow(ValidationException::class, 'Write down');

        $d = app(DeliveryService::class)->handOver($order->fresh(), ['line_ids' => [$order->lines[0]->id], 'recipient_name' => 'Ada', 'outstanding_conditions' => 'Pays balance Friday'], $this->owner);
        expect($d->balance_at_handover)->toBe(257000.0)->and($order->fresh()->status)->toBe('ready');

        app(BillingService::class)->recordPayment(['customer_id' => $this->customer->id, 'amount' => 257000, 'method' => 'cash', 'order_id' => $order->id]);
        app(DeliveryService::class)->handOver($order->fresh(), ['line_ids' => [$order->lines[1]->id, $order->lines[2]->id], 'recipient_name' => 'Ngozi', 'method' => 'delivery'], $this->owner);
        expect($order->fresh()->status)->toBe('delivered')
            ->and($order->fresh()->events->first()->note)->toBe('Delivered to Ngozi');
    });
});

it('flags late delivery with reasons and tells managers once per rise', function () {
    Notification::fake();
    $order = confirmedOrder($this, ['promised_date' => now()->addDays(3)->toDateString()]);
    inWorkroom($this, function () use ($order) {
        app(ProductionService::class)->planOrder($order, [], $this->owner);
        $risk = app(RiskService::class);
        $a = $risk->refresh($order->fresh());
        expect($a['level'])->toBe('high')
            ->and(collect($a['reasons'])->pluck('text')->implode(' '))->toContain('working day(s) of work left');
        Notification::assertSentToTimes($this->owner, \App\Notifications\DeliveryAtRisk::class, 1);

        $risk->refresh($order->fresh());   // same level: no repeat
        Notification::assertSentToTimes($this->owner, \App\Notifications\DeliveryAtRisk::class, 1);
        expect($order->fresh()->risk_level)->toBe('high');
    });
});

it('stops work, fittings and reminders when the order is cancelled, and blocks marking it ready by hand', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, function () use ($order) {
        app(ProductionService::class)->planOrder($order, [], $this->owner);
        expect(fn () => app(OrderService::class)->transition($order->fresh(), 'ready'))->toThrow(ValidationException::class, 'still open');

        $f = app(FittingService::class)->schedule(['order_id' => $order->id, 'scheduled_at' => now()->addDays(4)->toDateTimeString()], $this->owner);
        app(OrderService::class)->cancel($order->fresh(), 'Wedding called off');
        expect(ProductionTask::open()->count())->toBe(0)
            ->and($f->fresh()->status)->toBe('cancelled')
            ->and(FittingReminder::where('status', 'pending')->count())->toBe(0);
    });
});

it('renders the board, task, fittings, setup and order screens, and keeps houses and roles apart', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, fn () => app(ProductionService::class)->planOrder($order, [], $this->owner));
    $cut = gownTasks($order)->first();

    $this->actingAs($this->owner);
    $this->get('/production')->assertOk()->assertSee($order->number)->assertSee('Cutting');
    $this->get("/production/tasks/{$cut->public_id}")->assertOk()->assertSee('Mermaid lace gown');
    $this->get("/orders/{$order->public_id}")->assertOk()->assertSee('Production');
    $this->get('/fittings')->assertOk();
    $this->get('/fittings/create?order=' . $order->public_id)->assertOk();
    $this->get('/production/setup')->assertOk()->assertSee('Bespoke garment');
    $wf = inWorkroom($this, fn () => \App\Models\ProductionWorkflow::first());
    $this->get("/production/workflows/{$wf->id}/edit")->assertOk();
    $this->getJson('/search?q=' . $cut->number)->assertOk()->assertJsonPath('results.0.category', 'Production');

    // Settings: production options save.
    $this->get('/settings/house')->assertOk()->assertSee('Working days');
    $this->put('/settings/house', ['name' => 'Atelier', 'timezone' => 'Africa/Lagos', 'currency_code' => 'NGN', 'measurement_display_unit' => 'in',
        'production_working_days' => [1, 2, 3, 4, 5], 'fitting_reminder_hours' => '2, 48', 'production_task_limit' => 8, 'release_with_balance' => 1])->assertRedirect();
    $saved = $this->house->fresh()->settings;
    expect($saved['production_working_days'])->toBe([1, 2, 3, 4, 5])->and($saved['fitting_reminder_hours'])->toBe([48, 2])
        ->and($saved['production_task_limit'])->toBe(8)->and($saved['release_with_balance'])->toBeTrue();
    $this->put('/settings/house', ['name' => 'Atelier', 'timezone' => 'Africa/Lagos', 'currency_code' => 'NGN', 'measurement_display_unit' => 'in', 'fitting_reminder_hours' => 'soon'])
        ->assertSessionHasErrors('fitting_reminder_hours');

    // Tailor: sees the board and can work unassigned or own tasks, but not setup or planning.
    $this->actingAs($this->tailor)->get('/production')->assertOk();
    $this->actingAs($this->tailor)->get('/production/setup')->assertForbidden();
    $this->actingAs($this->tailor)->post("/orders/{$order->public_id}/production", [])->assertForbidden();
    $this->actingAs($this->tailor)->post("/production/tasks/{$cut->public_id}/start")->assertRedirect();
    expect($cut->fresh()->status)->toBe('in_progress')->and($cut->fresh()->assigned_to)->toBe($this->tailor->id);
    $other = addStaff($this->house, 'Tailor');
    $this->actingAs($other)->post("/production/tasks/{$cut->public_id}/complete")->assertForbidden();   // someone else's task

    // Cashier has no production access; another house can't see the task.
    $this->actingAs(addStaff($this->house, 'Cashier'))->get('/production')->assertForbidden();
    [, $otherOwner] = makeHouse('Other');
    $this->actingAs($otherOwner)->get("/production/tasks/{$cut->public_id}")->assertNotFound();
});

it('runs production from the staff app over the API', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, fn () => app(ProductionService::class)->planOrder($order, [$order->lines[1]->id => 0, $order->lines[2]->id => 0], $this->owner));
    $tasks = gownTasks($order);

    \Laravel\Sanctum\Sanctum::actingAs($this->tailor);
    $this->getJson('/api/v1/production/tasks')->assertOk()->assertJsonPath('data.0.number', $tasks[0]->number)->assertJsonPath('data.0.can_work', true);
    $this->postJson("/api/v1/production/tasks/{$tasks[0]->public_id}/events", ['type' => 'start'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/production/tasks/{$tasks[0]->public_id}/events", ['type' => 'complete', 'note' => 'Cut 4.5 yd'])->assertOk()->assertJsonPath('data.status', 'done');
    $this->postJson("/api/v1/production/tasks/{$tasks[1]->number}/events", ['type' => 'complete'])->assertOk();
    $this->getJson("/api/v1/production/tasks/{$tasks[0]->public_id}")->assertOk()->assertJsonPath('data.events.0.type', 'completed');
    $this->postJson('/api/v1/fittings', ['order' => $order->number, 'scheduled_at' => now()->addDays(2)->format('Y-m-d 10:00')])->assertForbidden();   // tailors don't book

    \Laravel\Sanctum\Sanctum::actingAs($this->owner);
    $fit = $this->postJson('/api/v1/fittings', ['order' => $order->number, 'line' => $order->lines[0]->id, 'scheduled_at' => now()->addDays(2)->format('Y-m-d 10:00')])
        ->assertCreated()->assertJsonCount(2, 'data.reminders')->json('data.id');
    $this->postJson("/api/v1/fittings/{$fit}/record", ['status' => 'attended', 'outcome' => 'alterations', 'alterations' => [['name' => 'Take in waist 1"', 'responsible_party' => 'house', 'cost_treatment' => 'free']]])
        ->assertOk()->assertJsonPath('data.outcome', 'alterations');
    $alt = inWorkroom($this, fn () => ProductionTask::where('kind', 'alteration')->first());
    $this->postJson("/api/v1/production/tasks/{$alt->public_id}/events", ['type' => 'complete', 'note' => 'Taken in'])->assertOk();
    $fitTask = $tasks[2]->fresh();
    $this->postJson("/api/v1/production/tasks/{$fitTask->public_id}/events", ['type' => 'complete', 'note' => 'Fits now'])->assertOk();
    $this->postJson("/api/v1/production/tasks/{$tasks[3]->public_id}/events", ['type' => 'complete'])->assertOk();

    $qc = $tasks[4]->fresh();
    $n = count($this->getJson("/api/v1/production/tasks/{$qc->public_id}")->json('data.checklist'));
    $this->postJson('/api/v1/quality-checks', ['task' => $qc->public_id, 'answers' => array_fill(0, $n, ['passed' => true])])->assertCreated()->assertJsonPath('data.result', 'pass');
    expect($order->fresh()->status)->toBe('ready');

    $this->postJson('/api/v1/alteration-tasks', ['order' => $order->public_id, 'name' => 'Extra button', 'reason' => 'Customer asked', 'responsible_party' => 'customer', 'cost_treatment' => 'chargeable', 'cost' => 1500])
        ->assertCreated()->assertJsonPath('data.kind', 'alteration');
    $this->postJson('/api/v1/deliveries', ['order' => $order->number, 'lines' => [$order->lines[0]->id], 'method' => 'collection', 'recipient_name' => 'Ngozi', 'confirmation' => 'none'])
        ->assertUnprocessable();   // still owes money
});

it('issues material from a production task and ties it to that garment (FR-PRD-004, scenario 38)', function () {
    $order = confirmedOrder($this);
    inWorkroom($this, fn () => app(ProductionService::class)->planOrder($order, [], $this->owner));
    $cut = gownTasks($order)->first();
    [$lace, $branch] = inWorkroom($this, function () {
        $item = app(\App\Services\Inventory\ItemService::class)->create(['name' => 'French lace', 'type' => 'fabric', 'unit' => 'yd', 'opening_cost' => 9000]);
        $branch = \App\Models\Branch::where('is_main', true)->first();
        app(\App\Services\Inventory\StockLedger::class)->post($item, $branch->id, 20, 'opening', ['unit_cost' => 9000]);

        return [$item, $branch];
    });

    $this->actingAs($this->owner)->get("/material-issues/create?task={$cut->public_id}")->assertOk()->assertSee($order->number . ' — Mermaid lace gown (Cutting)', false);
    $this->post('/material-issues', ['task_id' => $cut->id, 'branch_id' => $branch->id, 'job_reference' => 'Gown bodice',
        'lines' => [['item_id' => $lace->id, 'quantity' => 4.5]]])->assertRedirect("/production/tasks/{$cut->public_id}");

    $issue = inWorkroom($this, fn () => \App\Models\MaterialIssue::first());
    expect($issue->task_id)->toBe($cut->id)->and($issue->order_line_id)->toBe($order->lines[0]->id)->and($issue->order_id)->toBe($order->id)
        ->and((float) $issue->total_cost)->toBe(40500.0);
    $this->get("/production/tasks/{$cut->public_id}")->assertOk()->assertSee($issue->number);
});
