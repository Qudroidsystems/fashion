<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\TenantMembership;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Services\Reports\FinanceFacts;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\WeeklySummary;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Ade Couture');
    CurrentTenant::run($this->house, function () {
        $this->main = Branch::where('is_main', true)->first() ?? Branch::first();
        $this->lekki = Branch::create(['name' => 'Lekki studio', 'code' => 'LEK', 'type' => 'branch']);
        $this->customer = Customer::create(['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '08031234567']);
    });
    CurrentTenant::context()->clear();
});

/** A confirmed order for a made-to-measure service line at a branch, with an optional payment. */
function bookedOrder($test, float $price, ?Branch $branch = null, float $paid = 0): Order
{
    $test->actingAs($test->owner);

    return CurrentTenant::run($test->house, function () use ($test, $price, $branch, $paid) {
        $svc = app(OrderService::class);
        $order = $svc->saveOrder(null, ['customer_id' => $test->customer->id, 'branch_id' => ($branch ?? $test->main)->id],
            [['kind' => 'service', 'description' => 'Agbada', 'quantity' => 1, 'unit_price' => $price]]);
        $svc->transition($order, 'confirmed');
        if ($paid > 0) {
            app(BillingService::class)->recordPayment(['customer_id' => $test->customer->id, 'amount' => $paid, 'method' => 'cash',
                'order_id' => $order->id, 'branch_id' => ($branch ?? $test->main)->id]);
        }
        $order->forceFill(['branch_id' => ($branch ?? $test->main)->id])->save();

        return $order->fresh();
    });
}

/** Today in the house's time zone (reports and expense dates are local). */
function houseToday($test): string
{
    return now($test->house->timezone ?: config('app.timezone'))->toDateString();
}

function facts($test, string $period = 'today', ?object $user = null): FinanceFacts
{
    return CurrentTenant::run($test->house, fn () => new FinanceFacts(ReportFilters::make(['period' => $period], $user ?? $test->owner)));
}

it('keeps money collected, deposits, sales earned and expenses apart (FR-REP-005)', function () {
    $order = bookedOrder($this, 300000, paid: 100000);

    $f = facts($this);
    expect($f->collected())->toBe(100000.0)
        ->and($f->recognised())->toBe(0.0)            // not handed over: a deposit, not a sale
        ->and($f->depositsHeld())->toBe(100000.0);

    // Handed over: the full order value becomes a sale, and the deposit is no longer "held".
    CurrentTenant::run($this->house, fn () => $order->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save());
    $f = facts($this);
    expect($f->recognised())->toBe(300000.0)->and($f->depositsHeld())->toBe(0.0)->and($f->collected())->toBe(100000.0);

    // Expenses: recorded ones count, voided ones don't.
    $this->actingAs($this->owner)->post('/expenses', ['category' => 'Rent', 'description' => 'Shop rent', 'amount' => 50000,
        'spent_on' => houseToday($this), 'method' => 'bank_transfer'])->assertRedirect();
    $this->post('/expenses', ['category' => 'Power & fuel', 'description' => 'Diesel', 'amount' => 8000, 'spent_on' => houseToday($this), 'method' => 'cash']);
    $diesel = CurrentTenant::run($this->house, fn () => Expense::where('description', 'Diesel')->first());
    $this->post("/expenses/{$diesel->public_id}/void", ['reason' => 'Entered twice'])->assertRedirect();

    $f = facts($this);
    expect($f->expenses())->toBe(50000.0)->and($f->netProfit())->toBe(250000.0);

    $this->get('/reports/profit?period=today')->assertOk()->assertSee('₦300,000')->assertSee('Rent')->assertSee('Estimated profit')->assertSee('₦250,000');
    $this->get('/reports/sales?period=today')->assertOk()->assertSee('Money collected')->assertSee('₦100,000');
    $this->get('/reports/receivables')->assertOk()->assertSee($order->number)->assertSee('Handed over — not fully paid')->assertSee('₦200,000');
});

it('opens every report for the owner, and the dashboard shows the same money', function () {
    bookedOrder($this, 120000, paid: 20000);
    $this->actingAs($this->owner);

    $this->get('/reports')->assertOk()->assertSee('Sales &amp; money collected', false)->assertSee('Weddings &amp; projects', false);
    foreach (array_keys(app(ReportRegistry::class)->all()) as $key) {
        $this->get("/reports/{$key}?period=this_year")->assertOk()->assertSee('How these figures are worked out');
    }
    foreach (['stock-valuation', 'order-throughput', 'project-profitability', 'profit-and-loss'] as $alias) {
        $this->get("/reports/{$alias}")->assertOk();
    }
    $this->get('/reports/nope')->assertNotFound();

    $this->get('/dashboard')->assertOk()->assertSee('Collected today')->assertSee('₦20,000')
        ->assertSee('Owed to you')->assertSee('₦100,000')->assertSee('Order pipeline');
});

it('shows each person only the reports and figures their role allows (FR-REP-006)', function () {
    bookedOrder($this, 50000, paid: 10000);
    $tailor = addStaff($this->house, 'Tailor');
    $this->actingAs($tailor)->get('/reports')->assertForbidden();
    $this->get('/reports/sales')->assertForbidden();
    $this->get('/dashboard')->assertOk()->assertDontSee('Collected today')->assertDontSee('Owed to you');

    // A manager sees orders and production reports but not the money ones, and only their own branch.
    $manager = addStaff($this->house, 'Manager');
    CurrentTenant::run($this->house, fn () => TenantMembership::where('user_id', $manager->id)->update(['branch_id' => $this->lekki->id]));
    bookedOrder($this, 70000, $this->lekki);
    $this->actingAs($manager)->get('/reports')->assertOk()->assertSee('Orders: pipeline')->assertDontSee('Profit &amp; loss', false);
    $this->get('/reports/profit')->assertForbidden();
    $this->get('/reports/orders?period=today&branch=' . $this->main->id)->assertOk()->assertSee('Your branch only')
        ->assertDontSee('All branches');
    $this->get("/reports/orders/export/csv?period=today&branch={$this->main->id}")->assertOk();

    // The same limit applies to the export: one order (Lekki's), where the whole house has two.
    expect($this->get('/reports/orders/export/csv?period=today')->streamedContent())->toContain('"Orders confirmed",1,');
    $this->actingAs($this->owner);
    expect($this->get('/reports/orders/export/csv?period=today')->streamedContent())->toContain('"Orders confirmed",2,');
    $this->actingAs($manager);

    // An accountant sees every branch.
    $acc = addStaff($this->house, 'Accountant');
    CurrentTenant::run($this->house, fn () => TenantMembership::where('user_id', $acc->id)->update(['branch_id' => $this->lekki->id]));
    $this->actingAs($acc)->get('/reports/sales?period=today')->assertOk()->assertDontSee('Your branch only')->assertSee('₦10,000');

    // No "Export reports": can look, can't download.
    $designer = addStaff($this->house, 'Designer');
    CurrentTenant::run($this->house, fn () => $designer->givePermissionTo('View reports'));
    $this->actingAs($designer)->get('/reports/orders/export/pdf')->assertForbidden();
});

it('exports what is on screen as CSV, Excel and PDF, and logs it', function () {
    bookedOrder($this, 80000, paid: 30000);
    $this->actingAs($this->owner);

    $csv = $this->get('/reports/sales/export/csv?period=today')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($csv)->toContain('Ade Couture — Sales & money collected')->toContain('"Money collected",30000');

    $res = $this->get('/reports/receivables/export/xlsx');
    $res->assertOk();
    $file = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($file, $res->streamedContent());
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    expect($book->getSheetNames())->toContain('Every balance')
        ->and($book->getSheetByName('Every balance')->getCell('H2')->getValue())->toEqual(50000);

    $pdf = $this->get('/reports/sales/export/pdf?period=today');
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    expect(CurrentTenant::run($this->house, fn () => AuditLog::where('action', 'report.exported')->count()))->toBe(3);
});

it('follows the plan: full reports from Pro, spreadsheets with exports, PDF on every plan', function () {
    onPlan($this->house, 'basic');
    $this->actingAs($this->owner);
    $this->get('/reports')->assertOk()->assertSee('Upgrade');
    $this->get('/reports/profit')->assertOk()->assertSee('This report is not in your plan yet')->assertDontSee('How these figures are worked out');
    $this->get('/reports/profit/export/pdf')->assertForbidden();
    $this->get('/reports/sales')->assertOk()->assertSee('How these figures are worked out');
    $this->get('/reports/sales/export/pdf')->assertOk();
    $this->from('/reports/sales')->get('/reports/sales/export/csv')->assertRedirect('/reports/sales')->assertSessionHas('warning');

    onPlan($this->house, 'pro');
    $this->get('/reports/profit')->assertOk()->assertSee('How these figures are worked out');
    $this->get('/reports/sales/export/xlsx')->assertOk();
});

it('serves the same reports over the API', function () {
    bookedOrder($this, 40000, paid: 15000);
    \Laravel\Sanctum\Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/reports')->assertOk()->assertJsonFragment(['key' => 'sales']);
    $this->getJson('/api/v1/reports/sales?period=today')->assertOk()
        ->assertJsonPath('data.cards.0.label', 'Money collected')->assertJsonPath('data.cards.0.value', 15000)
        ->assertJsonPath('filters.period', 'today');
    $this->getJson('/api/v1/reports/stock-valuation')->assertOk()->assertJsonPath('report.key', 'stock');

    onPlan($this->house, 'basic');
    $this->getJson('/api/v1/reports/profit')->assertStatus(402)->assertJsonPath('feature', 'reports_full');

    $tailor = addStaff($this->house, 'Tailor');
    \Laravel\Sanctum\Sanctum::actingAs($tailor);
    $this->getJson('/api/v1/reports/sales')->assertForbidden();
});

it('keeps expenses honest: same-day corrections only, void with a reason, never deleted', function () {
    $this->actingAs($this->owner)->post('/expenses', ['category' => 'Transport & logistics', 'description' => 'Fabric run to Balogun',
        'amount' => 12000, 'spent_on' => houseToday($this), 'method' => 'cash', 'paid_to' => 'Okada'])->assertRedirect('/expenses');
    $e = CurrentTenant::run($this->house, fn () => Expense::first());
    expect($e->number)->toStartWith('EXP-');

    $this->put("/expenses/{$e->public_id}", ['category' => 'Transport & logistics', 'description' => 'Fabric run to Balogun market',
        'amount' => 15000, 'spent_on' => houseToday($this), 'method' => 'cash'])->assertRedirect();
    expect($e->fresh()->amount)->toBe(15000.0);

    // Future dates are refused; yesterday's entries can't be edited, only voided.
    $this->post('/expenses', ['category' => 'Rent', 'description' => 'x', 'amount' => 1, 'spent_on' => now()->addDays(5)->toDateString(), 'method' => 'cash'])
        ->assertSessionHasErrors('spent_on');
    CurrentTenant::run($this->house, fn () => $e->forceFill(['created_at' => now()->subDays(2)])->save());
    $this->get("/expenses/{$e->public_id}/edit")->assertForbidden();
    $this->post("/expenses/{$e->public_id}/void", [])->assertSessionHasErrors('reason');
    $this->post("/expenses/{$e->public_id}/void", ['reason' => 'Wrong house'])->assertRedirect();
    expect($e->fresh()->status)->toBe('void')
        ->and(fn () => CurrentTenant::run($this->house, fn () => $e->fresh()->delete()))->toThrow(LogicException::class);

    $this->get('/expenses')->assertOk()->assertSee('Voided: Wrong house');
    $this->actingAs(addStaff($this->house, 'Tailor'))->get('/expenses')->assertForbidden();
    [, $other] = makeHouse('Other');
    $this->actingAs($other)->post("/expenses/{$e->public_id}/void", ['reason' => 'x'])->assertNotFound();
});

it('sends a Monday summary of last week once, to the owner and finance staff only', function () {
    Notification::fake();
    $acc = addStaff($this->house, 'Accountant');
    $tailor = addStaff($this->house, 'Tailor');
    $this->travelTo(now()->startOfWeek()->addDays(7)->setTime(7, 30));   // next Monday
    bookedOrder($this, 90000);
    $this->travelTo(now()->subDays(3));                                 // a payment last week (Friday)
    $this->actingAs($this->owner);
    CurrentTenant::run($this->house, fn () => app(BillingService::class)->recordPayment(['customer_id' => $this->customer->id, 'amount' => 25000, 'method' => 'cash']));
    $this->travelBack();
    $this->travelTo(now()->startOfWeek()->addDays(7)->setTime(7, 30));

    $this->artisan('fhmp:weekly-summary')->assertSuccessful();
    Notification::assertSentTo($this->owner, \App\Notifications\WeeklySummaryNotice::class, fn ($n) => $n->f['collected'] === 25000.0);
    Notification::assertSentTo($acc, \App\Notifications\WeeklySummaryNotice::class);
    Notification::assertNotSentTo($tailor, \App\Notifications\WeeklySummaryNotice::class);

    $this->artisan('fhmp:weekly-summary')->assertSuccessful();   // already sent this week
    Notification::assertSentToTimes($this->owner, \App\Notifications\WeeklySummaryNotice::class, 1);

    // A house can switch it off.
    $this->house->forceFill(['settings' => ['reports' => ['weekly_summary' => false]]])->save();
    expect(CurrentTenant::run($this->house, fn () => app(WeeklySummary::class)->sendFor($this->house->fresh(), true)))->toBe(0);
});

it('lets the admin pick any date range, or all time, and print it', function () {
    $tz = $this->house->timezone ?: config('app.timezone');
    $old = bookedOrder($this, 60000, paid: 20000);
    $new = bookedOrder($this, 90000, paid: 40000);
    // The first order and its payment happened back in March.
    CurrentTenant::run($this->house, function () use ($old, $tz) {
        $march = now($tz)->setDate(now($tz)->year, 3, 10)->setTime(12, 0)->utc();
        $old->forceFill(['confirmed_at' => $march, 'created_at' => $march])->save();
        \App\Models\Payment::whereHas('allocations', fn ($q) => $q->where('order_id', $old->id))->update(['received_at' => $march]);
    });
    $this->actingAs($this->owner);
    $year = now($tz)->year;

    // Any range: just March, then everything from 1 Jan to today.
    $this->get("/reports/sales?period=custom&from={$year}-03-01&to={$year}-03-31")->assertOk()
        ->assertSee("1 Mar – 31 Mar {$year}")->assertSee('₦20,000')->assertDontSee('₦60,000');
    $res = $this->get("/reports/sales?period=custom&from={$year}-01-01&to=" . houseToday($this))->assertOk()->assertSee('₦60,000');

    // Dates typed over a quick pick win; a reversed range is put the right way round.
    $f = CurrentTenant::run($this->house, fn () => ReportFilters::make(['period' => 'this_month', 'from' => "{$year}-03-01", 'to' => "{$year}-03-31"], $this->owner));
    expect($f->preset)->toBe('custom')->and($f->fromDate())->toBe("{$year}-03-01");
    $f = CurrentTenant::run($this->house, fn () => ReportFilters::make(['period' => 'custom', 'from' => "{$year}-03-31", 'to' => "{$year}-03-01"], $this->owner));
    expect($f->fromDate())->toBe("{$year}-03-01")->and($f->toDate())->toBe("{$year}-03-31");

    // All time starts with the house's earliest record.
    $this->get('/reports/sales?period=all')->assertOk()->assertSee('All time (since 10 Mar')->assertSee('₦60,000');

    // Money owed: all time by default; dates pick which orders are counted (balances still as of now).
    $this->get('/reports/receivables')->assertOk()->assertSee($old->number)->assertSee($new->number);
    $this->get("/reports/receivables?period=custom&from={$year}-03-01&to={$year}-03-31")->assertOk()
        ->assertSee($old->number)->assertDontSee($new->number)->assertSee('orders and invoices dated');

    // Print opens the same report as a PDF in the browser (no "Export reports" needed); exports keep the range.
    $pdf = $this->get("/reports/sales/print?period=custom&from={$year}-03-01&to={$year}-03-31")->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($pdf->headers->get('content-disposition'))->toStartWith('inline');
    $csv = $this->get("/reports/sales/export/csv?period=custom&from={$year}-03-01&to={$year}-03-31")->streamedContent();
    expect($csv)->toContain("Period: 1 Mar – 31 Mar {$year}")->toContain('"Money collected",20000');

    $designer = addStaff($this->house, 'Designer');
    CurrentTenant::run($this->house, fn () => $designer->givePermissionTo(['View reports']));
    $this->actingAs($designer)->get('/reports/orders/print')->assertOk();
    $this->get('/reports/orders/export/pdf')->assertForbidden();
});
