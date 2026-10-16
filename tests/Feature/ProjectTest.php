<?php

use App\Models\Customer;
use App\Models\MaterialIssue;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplate;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Services\Catalogue\StyleService;
use App\Services\Orders\BillingService;
use App\Services\Projects\ProjectService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
    $this->actingAs($this->owner);
    CurrentTenant::run($this->house, function () {
        $this->bride = Customer::create(['first_name' => 'Amaka', 'last_name' => 'Obi', 'phone' => '08030000001', 'email' => 'amaka@example.com']);
        $styles = app(StyleService::class);
        $gown = $styles->create(['name' => 'Bridesmaid gown', 'audience' => 'female'], ['base_price' => 80000, 'lead_time_days' => 21],
            [MeasurementTemplate::where('code', 'trouser')->value('id')]);
        $styles->publish($gown->draft()->first());
        $this->gown = $gown->fresh('currentVersion');
        $agbada = $styles->create(['name' => 'Agbada set', 'audience' => 'male'], ['base_price' => 150000, 'lead_time_days' => 21],
            [MeasurementTemplate::where('code', 'trouser')->value('id')]);
        $styles->publish($agbada->draft()->first());
        $this->agbada = $agbada->fresh('currentVersion');
    });
    CurrentTenant::context()->clear();
});

function onProject($test, callable $fn, $user = null)
{
    $test->actingAs($user ?? $test->owner);

    return CurrentTenant::run($test->house, $fn);
}

/** A wedding with three bridesmaids (client pays) and one aso-ebi guest who pays for himself. */
function wedding($test): Project
{
    return onProject($test, function () use ($test) {
        $svc = app(ProjectService::class);
        $p = $svc->create(['name' => 'Amaka & Tunde wedding', 'type' => 'wedding', 'customer_id' => $test->bride->id,
            'event_date' => now()->addDays(100)->toDateString(), 'contract_amount' => 400000, 'deposit_amount' => 120000], $test->owner);
        $svc->addParticipantsFromText($p, "Ada Eze, 08031110001, Chief bridesmaid\nChioma Okeke, 08031110002\nBisi Ade", 'Bridesmaids', 'client');
        $svc->addParticipant($p, ['name' => 'Emeka Nwosu', 'phone' => '08031110009', 'role' => 'Guest', 'pays' => 'self']);

        return $p->fresh();
    });
}

/** Approved trouser measurements for a customer, through the normal screen. */
function measured($test, Customer $customer): MeasurementSession
{
    $version = onProject($test, fn () => MeasurementTemplate::where('code', 'trouser')->first()->currentVersion()->with('parameters')->first());
    $values = [];
    foreach ($version->parameters as $tp) {
        foreach ($tp->sides() as $side) {
            $values[$tp->id][$side] = match ($tp->data_type) { 'length' => '30', 'select' => $tp->options[0], default => 'ok' };
        }
    }
    $test->actingAs($test->owner)->post("/customers/{$customer->public_id}/measurements", [
        'template_version_id' => $version->id, 'display_unit' => 'in', 'values' => $values, 'action' => 'submit', 'approve_now' => 1,
    ])->assertRedirect();

    return MeasurementSession::withoutGlobalScopes()->where('customer_id', $customer->id)->where('status', 'approved')->latest('id')->firstOrFail();
}

it('puts each garment on exactly one order, so project money adds up without double counting (FR-PRJ-004)', function () {
    $p = wedding($this);
    onProject($this, function () use ($p) {
        $svc = app(ProjectService::class);
        expect($p->milestones()->count())->toBe(6);

        $maids = $p->participants()->where('group_name', 'Bridesmaids')->get();
        $svc->addGarments($p, $maids, ['kind' => 'bespoke', 'style_id' => $this->gown->id, 'quantity' => 1], $this->owner);
        $guest = $p->participants()->where('pays', 'self')->first();
        $svc->addGarments($p, collect([$guest]), ['kind' => 'bespoke', 'style_id' => $this->agbada->id, 'quantity' => 1], $this->owner);

        $orders = $p->orders()->get();
        expect($orders)->toHaveCount(2);
        $main = $p->mainOrder();
        $own = $orders->firstWhere('customer_id', '!=', $this->bride->id);
        expect($main->lines)->toHaveCount(3)->and($main->total)->toBe(240000.0)->and($main->deposit_required)->toBe(120000.0)
            ->and($main->lines->pluck('wearer')->all())->toBe(['Ada Eze', 'Chioma Okeke', 'Bisi Ade'])
            ->and($own->customer->name)->toBe('Emeka Nwosu')->and($own->total)->toBe(150000.0)
            ->and($guest->fresh()->customer_id)->toBe($own->customer_id);

        // Draft until the project is confirmed; then both orders are confirmed.
        expect($svc->summary($p)['money']['value'])->toBe(0.0)->and($svc->summary($p)['money']['planned'])->toBe(390000.0);
        $svc->confirm($p, $this->owner);
        expect($p->fresh()->status)->toBe('active')->and($main->fresh()->status)->toBe('confirmed')->and($own->fresh()->status)->toBe('confirmed');

        // Client pays the deposit; the guest pays half of his own.
        $billing = app(BillingService::class);
        $billing->recordPayment(['customer_id' => $this->bride->id, 'amount' => 120000, 'method' => 'bank_transfer', 'reference' => 'TRF-1', 'order_id' => $main->id]);
        $billing->recordPayment(['customer_id' => $own->customer_id, 'amount' => 75000, 'method' => 'cash', 'order_id' => $own->id]);

        $s = $svc->summary($p->fresh());
        expect($s['money']['value'])->toBe(390000.0)->and($s['money']['collected'])->toBe(195000.0)
            ->and($s['money']['outstanding'])->toBe(195000.0)->and($s['money']['client_paid'])->toBe(120000.0)
            ->and($s['money']['deposit_paid'])->toBeTrue()->and($s['money']['difference'])->toBe(-10000.0)
            ->and($s['garments']['total'])->toBe(4);

        // A garment added after confirmation is a revision of the confirmed order, not a new one.
        $svc->addParticipant($p, ['name' => 'Funke Bello', 'group_name' => 'Bridesmaids']);
        $svc->addGarments($p, $p->participants()->where('name', 'Funke Bello')->get(), ['kind' => 'bespoke', 'style_id' => $this->gown->id], $this->owner);
        expect($p->orders()->count())->toBe(2)->and($main->fresh()->status)->toBe('confirmed')->and($main->fresh()->revision)->toBe(1)
            ->and($svc->summary($p->fresh())['money']['value'])->toBe(470000.0);
    });
});

it('links each participant’s own approved measurements, and nobody else’s', function () {
    $p = wedding($this);
    [$line, $ada] = onProject($this, function () use ($p) {
        $ada = $p->participants()->where('name', 'Ada Eze')->first();
        $line = app(ProjectService::class)->addGarments($p, collect([$ada]), ['kind' => 'bespoke', 'style_id' => $this->gown->id], $this->owner)->first();

        return [$line, app(ProjectService::class)->ensureCustomer($ada)];
    });
    $adaSession = measured($this, $ada);
    $brideSession = measured($this, $this->bride);
    $stranger = onProject($this, fn () => Customer::create(['first_name' => 'Stranger', 'phone' => '08039999999']));
    $strangerSession = measured($this, $stranger);

    onProject($this, function () use ($line, $adaSession, $brideSession, $strangerSession) {
        $svc = app(ProjectService::class);
        expect($svc->linkMeasurement($line, $adaSession->id)->measurement_session_id)->toBe($adaSession->id);
        expect(fn () => $svc->linkMeasurement($line->fresh(), $strangerSession->id))->toThrow(ValidationException::class, 'person wearing');
        // The client (order customer) is also allowed — e.g. when the bride's own measurements are used.
        expect($svc->linkMeasurement($line->fresh(), $brideSession->id)->measurement_session_id)->toBe($brideSession->id);
    });
});

it('shows the client only published milestones and updates of their own project (scenario 39)', function () {
    Notification::fake();
    $p = wedding($this);
    [$token, $hidden, $shown] = onProject($this, function () use ($p) {
        $svc = app(ProjectService::class);
        \App\Models\CustomerConsent::create(['customer_id' => $this->bride->id, 'channel' => 'email', 'purpose' => 'transactional', 'granted' => true, 'recorded_at' => now()]);
        $token = $svc->enableTracking($p);
        $ms = $p->milestones()->get();
        $svc->publishMilestone($ms[0], $this->owner);
        $svc->saveMilestone($p, $ms[1], ['customer_visible' => false]);
        $svc->publishUpdate($svc->addUpdate($p, ['title' => 'Lace has arrived', 'body' => 'Your French lace came in today.'], [], $this->owner), $this->owner);
        $svc->addUpdate($p, ['title' => 'Internal: supplier late', 'body' => 'Do not show'], [], $this->owner);

        return [$token, $ms[1]->name, $ms[0]->name];
    });
    // The bride agreed to order updates by email, so she hears about publications.
    Notification::assertSentOnDemand(\App\Notifications\ProjectPublished::class);

    $this->post('/logout');
    $this->get("/t/{$token}")->assertOk()->assertSee('Amaka &amp; Tunde wedding', false)->assertSee($shown)->assertSee('Lace has arrived')
        ->assertDontSee($hidden)->assertDontSee('supplier late')->assertDontSee('80,000');

    // Another house's project and a made-up token show nothing.
    [$other, $otherOwner] = makeHouse('Other');
    $otherToken = CurrentTenant::run($other, function () use ($otherOwner) {
        $this->actingAs($otherOwner);
        $c = Customer::create(['first_name' => 'X']);

        return app(ProjectService::class)->enableTracking(app(ProjectService::class)->create(['name' => 'Other wedding', 'customer_id' => $c->id], $otherOwner));
    });
    $this->post('/logout');
    $this->get("/t/{$otherToken}")->assertOk()->assertSee('Other wedding')->assertDontSee('Amaka');
    $this->get('/t/' . str_repeat('a', 40))->assertNotFound();

    // Revoked: the old link stops working.
    onProject($this, fn () => app(ProjectService::class)->disableTracking($p->fresh()));
    $this->post('/logout');
    $this->get("/t/{$token}")->assertNotFound();
});

it('counts issued material and expenses as project cost, and checks the payment schedule', function () {
    $p = wedding($this);
    onProject($this, function () use ($p) {
        $svc = app(ProjectService::class);
        $svc->addGarments($p, $p->participants()->where('pays', 'client')->get(), ['kind' => 'bespoke', 'style_id' => $this->gown->id], $this->owner);
        $svc->confirm($p, $this->owner);
        $main = $p->mainOrder();

        $item = app(\App\Services\Inventory\ItemService::class)->create(['name' => 'French lace', 'type' => 'fabric', 'unit' => 'yd']);
        $branch = \App\Models\Branch::where('is_main', true)->first();
        app(\App\Services\Inventory\StockLedger::class)->post($item, $branch->id, 30, 'opening', ['unit_cost' => 9000]);
        app(\App\Services\Inventory\StockDocumentService::class)->issue(['branch_id' => $branch->id, 'order_id' => $main->id, 'project_id' => $p->id,
            'job_reference' => 'Bridesmaids'], [['item_id' => $item->id, 'quantity' => 12]]);
        $svc->addBudgetLine($p, ['category' => 'fabric', 'amount' => 150000]);
        $svc->addExpense($p, ['category' => 'labour', 'description' => 'Beader (3 days)', 'amount' => 45000, 'spent_on' => now()->toDateString()], null, $this->owner);

        $svc->addSchedule($p, ['label' => 'Deposit', 'due_date' => now()->subDay()->toDateString(), 'amount' => 120000]);
        $svc->addSchedule($p, ['label' => 'Balance', 'due_date' => now()->addDays(60)->toDateString(), 'amount' => 120000]);
        app(BillingService::class)->recordPayment(['customer_id' => $this->bride->id, 'amount' => 150000, 'method' => 'cash', 'order_id' => $main->id]);

        $s = $svc->summary($p->fresh());
        expect($s['costs']['materials'])->toBe(108000.0)->and($s['costs']['expenses'])->toBe(45000.0)->and($s['costs']['actual'])->toBe(153000.0)
            ->and($s['costs']['remaining_budget'])->toBe(-3000.0)->and($s['costs']['margin'])->toBe(87000.0)
            ->and($s['schedule']->pluck('state')->all())->toBe(['paid', 'part'])->and($s['schedule'][1]['covered'])->toBe(30000.0);
        // Material issued for the project's order is counted once even without project_id on it.
        expect(MaterialIssue::count())->toBe(1);
    });
});

it('withdraws participants, cancels and completes projects through their orders', function () {
    $p = wedding($this);
    onProject($this, function () use ($p) {
        $svc = app(ProjectService::class);
        expect(fn () => $svc->confirm($p, $this->owner))->toThrow(ValidationException::class, 'Add the participants');
        $maids = $p->participants()->where('pays', 'client')->get();
        $svc->addGarments($p, $maids, ['kind' => 'bespoke', 'style_id' => $this->gown->id], $this->owner);
        $svc->confirm($p, $this->owner);

        $svc->withdraw($maids[2], 'Travelling');
        $main = $p->mainOrder();
        expect($main->lines()->where('status', '!=', 'cancelled')->count())->toBe(2)->and($main->fresh()->total)->toBe(160000.0)
            ->and($maids[2]->fresh()->status)->toBe('withdrawn');

        expect(fn () => $svc->complete($p->fresh()))->toThrow(ValidationException::class, 'not been handed over');
        $svc->cancel($p->fresh(), 'Wedding postponed indefinitely', $this->owner);
        expect($p->fresh()->status)->toBe('cancelled')->and($main->fresh()->status)->toBe('cancelled')
            ->and(fn () => $svc->addParticipant($p->fresh(), ['name' => 'Late']))->toThrow(ValidationException::class);
    });
});

it('runs a project from the screens, and keeps roles and houses apart', function () {
    $this->actingAs($this->owner);
    $this->get('/projects')->assertOk();
    $this->get('/projects/create')->assertOk()->assertSee('Contract amount');
    $this->post('/projects', ['name' => 'Bello family Sallah', 'type' => 'group', 'customer_id' => $this->bride->id,
        'event_date' => now()->addDays(40)->toDateString(), 'contract_amount' => 300000])->assertRedirect();
    $p = onProject($this, fn () => Project::where('name', 'Bello family Sallah')->firstOrFail());
    expect(onProject($this, fn () => $p->milestones()->count()))->toBe(3);

    $this->post("/projects/{$p->public_id}/participants", ['bulk' => "Musa Bello, 08035550001, Father\nAisha Bello\nYusuf Bello", 'group_name' => 'Family', 'pays' => 'client'])->assertRedirect();
    $people = onProject($this, fn () => $p->participants()->pluck('id')->all());
    $this->post("/projects/{$p->public_id}/garments", ['participant_ids' => $people, 'kind' => 'bespoke', 'style_id' => $this->agbada->id, 'quantity' => 1])->assertRedirect();
    $this->post("/projects/{$p->public_id}/milestones", ['name' => 'Cloth bought', 'due_date' => now()->addDays(5)->toDateString(), 'customer_visible' => 1])->assertRedirect();
    $this->post("/projects/{$p->public_id}/updates", ['title' => 'Fabric chosen', 'body' => 'We chose the royal blue.', 'customer_visible' => 1, 'publish' => 1])->assertRedirect();
    $this->post("/projects/{$p->public_id}/expenses", ['category' => 'transport', 'description' => 'Dispatch to Kano', 'amount' => 12000, 'spent_on' => now()->toDateString()])->assertRedirect();
    $this->post("/projects/{$p->public_id}/schedule", ['label' => 'Deposit', 'due_date' => now()->addDays(3)->toDateString(), 'amount' => 150000])->assertRedirect();
    $this->post("/projects/{$p->public_id}/tracking", ['days' => 90])->assertRedirect();
    $this->post("/projects/{$p->public_id}/confirm")->assertRedirect();

    $this->get("/projects/{$p->public_id}")->assertOk()->assertSee('Musa Bello')->assertSee('Agbada set')->assertSee('Fabric chosen')
        ->assertSee('Dispatch to Kano')->assertSee('Client progress page')->assertSee('₦450,000');
    $order = onProject($this, fn () => $p->mainOrder());
    expect($order->status)->toBe('confirmed')->and($order->total)->toBe(450000.0);
    $this->get("/orders/{$order->public_id}")->assertOk()->assertSee('Bello family Sallah');
    $this->get('/projects?q=Sallah')->assertOk()->assertSee('Bello family Sallah');
    $this->getJson('/search?q=Sallah')->assertOk()->assertJsonPath('results.0.category', 'Projects');

    // A tailor sees only projects they work on, and never the money; a cashier has no access.
    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->get('/projects')->assertOk()->assertDontSee('Bello family Sallah');
    $this->actingAs($tailor)->get("/projects/{$p->public_id}")->assertNotFound();
    onProject($this, function () use ($order, $tailor) {
        app(\App\Services\Production\ProductionService::class)->planOrder($order->fresh(), [], $this->owner);
        \App\Models\ProductionTask::where('order_id', $order->id)->first()->update(['assigned_to' => $tailor->id]);
    });
    $this->actingAs($tailor)->get("/projects/{$p->public_id}")->assertOk()->assertSee('Musa Bello')->assertDontSee('₦450,000')->assertDontSee('Dispatch to Kano');
    $this->actingAs($tailor)->post("/projects/{$p->public_id}/participants", ['name' => 'X', 'pays' => 'client'])->assertForbidden();
    $this->actingAs(addStaff($this->house, 'Cashier'))->get('/projects')->assertForbidden();

    // Designers manage nothing here but can't publish either; managers can.
    $manager = addStaff($this->house, 'Manager');
    $ms = onProject($this, fn () => $p->milestones()->where('name', 'Cloth bought')->first());
    $this->actingAs($manager)->post("/projects/{$p->public_id}/milestones/{$ms->id}/publish")->assertRedirect();
    expect($ms->fresh()->isPublished())->toBeTrue();

    [, $otherOwner] = makeHouse('Other');
    $this->actingAs($otherOwner)->get("/projects/{$p->public_id}")->assertNotFound();
    $this->actingAs($otherOwner)->post("/projects/{$p->public_id}/participants", ['name' => 'Spy', 'pays' => 'client'])->assertNotFound();
});

it('exposes projects to the apps over the API, with a public tracking endpoint', function () {
    \Laravel\Sanctum\Sanctum::actingAs($this->owner);
    $id = $this->postJson('/api/v1/projects', ['name' => 'Staff uniforms 2027', 'type' => 'uniform', 'customer' => $this->bride->customer_number,
        'event_date' => now()->addDays(70)->toDateString()])->assertCreated()->assertJsonCount(5, 'data.milestones')->json('data.id');
    $people = $this->postJson("/api/v1/projects/{$id}/participants", ['people' => [['name' => 'Driver 1', 'group_name' => 'Drivers'], ['name' => 'Driver 2', 'group_name' => 'Drivers']]])
        ->assertCreated()->assertJsonCount(2, 'data')->json('data.*.id');
    $this->postJson("/api/v1/projects/{$id}/garments", ['participants' => $people, 'style' => $this->agbada->code, 'quantity' => 2])
        ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.line_total', 300000);
    $ms = $this->postJson("/api/v1/projects/{$id}/milestones", ['name' => 'Sizes collected', 'customer_visible' => true])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/projects/{$id}/milestones/{$ms}/publish")->assertOk()->assertJsonPath('data.published', true);
    $this->postJson("/api/v1/projects/{$id}/updates", ['body' => 'Sample is ready for approval', 'customer_visible' => true, 'publish' => true])->assertCreated()->assertJsonPath('data.status', 'published');
    $this->getJson("/api/v1/projects/{$id}")->assertOk()->assertJsonPath('data.garments.total', 2)->assertJsonPath('data.money.planned', 600000);
    $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.id', $id);

    $token = onProject($this, fn () => app(ProjectService::class)->enableTracking(Project::where('public_id', $id)->first()));
    $this->getJson("/api/v1/public/project-tracking/{$token}")->assertOk()->assertJsonPath('data.name', 'Staff uniforms 2027')
        ->assertJsonCount(1, 'data.milestones')->assertJsonPath('data.milestones.0.name', 'Sizes collected')->assertJsonMissingPath('data.money');
    $this->getJson('/api/v1/public/project-tracking/' . str_repeat('Z', 40))->assertNotFound();

    \Laravel\Sanctum\Sanctum::actingAs(addStaff($this->house, 'Tailor'));
    $this->getJson("/api/v1/projects/{$id}")->assertNotFound();   // not on this project
});
