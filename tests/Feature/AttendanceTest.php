<?php

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Services\Attendance\AttendanceService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

/** The code the entrance screen shows now, for a house. */
function entranceCode($tenant, ?int $at = null): string
{
    return CurrentTenant::run($tenant, function () use ($at) {
        $svc = app(AttendanceService::class);
        $branch = Branch::active()->orderBy('id')->first();
        if ($at === null) {
            return $svc->rotatingCode($branch)['code'];
        }
        $m = new ReflectionMethod($svc, 'sign');

        return $m->invoke($svc, $branch, (string) $svc->window($at));
    });
}

afterEach(fn () => Carbon::setTestNow());

it('clocks staff in and out with the entrance code', function () {
    [$tenant] = makeHouse('Scan House');
    $tailor = addStaff($tenant, 'Tailor', ['name' => 'Bisi']);
    Carbon::setTestNow(now($tenant->timezone)->setTime(8, 5)->utc());

    $this->actingAs($tailor, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => entranceCode($tenant)])
        ->assertCreated()->assertJsonPath('data.direction', 'in')->assertJsonPath('data.today.status', 'in');

    // Scanning again straight away doesn't add a second record.
    $this->actingAs($tailor, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => entranceCode($tenant)])
        ->assertOk()->assertJsonPath('data.repeated', true);

    Carbon::setTestNow(now()->addHours(9));
    $this->actingAs($tailor, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => entranceCode($tenant)])
        ->assertCreated()->assertJsonPath('data.direction', 'out')
        ->assertJsonPath('data.today.status', 'out')
        ->assertJsonPath('data.today.worked_minutes', 540)
        ->assertJsonPath('data.today.late_minutes', null);

    expect(CurrentTenant::run($tenant, fn () => AttendanceEvent::count()))->toBe(2);
});

it('refuses expired, forged and other houses’ codes', function () {
    [$a] = makeHouse('House A');
    [$b] = makeHouse('House B');
    $staff = addStaff($a, 'Tailor');

    $old = entranceCode($a, now()->getTimestamp() - 300);
    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => $old])
        ->assertStatus(422)->assertJsonPath('field_errors.code.0', 'This code has expired. Scan the code on the screen again.');

    $forged = substr(entranceCode($a), 0, -3) . 'abc';
    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => $forged])->assertStatus(422);

    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => entranceCode($b)])
        ->assertStatus(422)->assertJsonPath('field_errors.code.0', 'This code belongs to another fashion house.');

    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => 'hello'])->assertStatus(422);
    expect(CurrentTenant::run($a, fn () => AttendanceEvent::count()))->toBe(0);
});

it('accepts the printed code only when the house switches it on, and a replaced one stops working', function () {
    [$tenant, $owner] = makeHouse('Print House');
    $staff = addStaff($tenant, 'Tailor');
    $printed = fn () => CurrentTenant::run($tenant->fresh(), fn () => app(AttendanceService::class)->printedCode(Branch::active()->first()));

    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => $printed()])->assertStatus(422);

    $this->actingAs($owner)->put('/attendance/settings', ['mode' => 'both', 'start_time' => '08:00', 'grace_minutes' => 15])->assertRedirect();
    $old = $printed();
    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => $old])->assertCreated();

    $this->actingAs($owner)->post('/attendance/printed-code')->assertRedirect();
    Carbon::setTestNow(now()->addMinutes(5));
    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => $old])
        ->assertStatus(422)->assertJsonPath('field_errors.code.0', 'This printed code has been replaced. Scan the new one.');
    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => $printed()])->assertCreated()->assertJsonPath('data.direction', 'out');
});

it('shows the manager who is in, who is late and who has not come', function () {
    [$tenant, $owner] = makeHouse('Board House');
    $early = addStaff($tenant, 'Tailor', ['name' => 'Early Ade']);
    $late = addStaff($tenant, 'Tailor', ['name' => 'Late Bola']);
    addStaff($tenant, 'Cashier', ['name' => 'Absent Chi']);

    Carbon::setTestNow(now($tenant->timezone)->setTime(7, 50)->utc());
    $this->actingAs($early, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => entranceCode($tenant)])->assertCreated();
    Carbon::setTestNow(now($tenant->timezone)->setTime(9, 0)->utc());
    $this->actingAs($late, 'sanctum')->postJson('/api/v1/attendance/scan', ['code' => entranceCode($tenant)])->assertCreated();

    $res = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/attendance')->assertOk();
    $staff = collect($res->json('data.staff'))->keyBy('name');
    expect($staff['Early Ade']['status'])->toBe('in')
        ->and($staff['Early Ade']['late_minutes'])->toBeNull()
        ->and($staff['Late Bola']['late_minutes'])->toBe(45)
        ->and($staff['Absent Chi']['status'])->toBe('absent')
        ->and($res->json('data.counts.late'))->toBe(1);

    $this->actingAs($owner)->get('/attendance')->assertOk()->assertSee('Early Ade')->assertSee('45 min late')->assertSee('Not in yet');
    $this->actingAs($owner)->get('/attendance/entrance')->assertOk()->assertSee('Scan attendance');
    $this->actingAs($owner)->getJson('/attendance/entrance/code')->assertOk()->assertJsonStructure(['svg', 'expires_in']);

    // A tailor can scan but can't see everyone.
    $this->actingAs($early, 'sanctum')->getJson('/api/v1/attendance')->assertForbidden();
    $this->actingAs($early)->get('/attendance')->assertForbidden();
});

it('lets a manager add a forgotten scan, kept as a correction', function () {
    [$tenant, $owner] = makeHouse('Fix House');
    $staff = addStaff($tenant, 'Tailor', ['name' => 'Forgot Femi']);

    $this->actingAs($owner)->post('/attendance', [
        'user_id' => $staff->id, 'direction' => 'in', 'date' => now($tenant->timezone)->toDateString(), 'time' => '00:01', 'note' => 'Phone was flat',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $e = CurrentTenant::run($tenant, fn () => AttendanceEvent::first());
    expect($e->method)->toBe('manual')->and($e->recorded_by)->toBe($owner->id)->and($e->note)->toBe('Phone was flat');
    expect(fn () => $e->update(['note' => 'x']))->toThrow(LogicException::class);

    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/attendance/me')->assertOk()
        ->assertJsonPath('data.today.status', 'in')->assertJsonPath('data.days.0.events.0.method', 'manual');
});

it('grants new permissions to an existing role in every house', function () {
    [$tenant] = makeHouse('Old House');
    $m = addStaff($tenant, 'Manager');
    CurrentTenant::run($tenant, fn () => \App\Models\Role::where('tenant_id', $tenant->id)->where('name', 'Manager')->first()->revokePermissionTo('View attendance'));
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($m, 'sanctum')->getJson('/api/v1/attendance')->assertForbidden();

    $this->artisan('fhmp:grant', ['role' => 'Manager', 'permissions' => ['View attendance']])->assertSuccessful();
    $this->actingAs($m->fresh(), 'sanctum')->getJson('/api/v1/attendance')->assertOk();
    $this->artisan('fhmp:grant', ['role' => 'Manager', 'permissions' => ['Nope']])->assertFailed();
});
