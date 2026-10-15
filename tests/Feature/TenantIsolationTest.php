<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;

/*
| SRS acceptance scenario 30: an employee from Tenant A cannot read, update
| or infer Tenant B's records by changing IDs.
*/

beforeEach(function () {
    [$this->houseA, $this->ownerA] = makeHouse('House A');
    [$this->houseB, $this->ownerB] = makeHouse('House B');
    $this->staffB = addStaff($this->houseB, 'Tailor', ['name' => 'Bola From B']);
});

it('lists only its own staff', function () {
    $this->actingAs($this->ownerA)->get('/users')
        ->assertOk()
        ->assertSee($this->ownerA->name)
        ->assertDontSee('Bola From B')
        ->assertDontSee($this->ownerB->name);
});

it('cannot open, edit or delete another house\'s user by id', function () {
    $id = $this->staffB->id;

    $this->actingAs($this->ownerA)->get("/users/{$id}")->assertNotFound();
    $this->actingAs($this->ownerA)->putJson("/users/{$id}", [
        'name' => 'Hacked', 'email' => 'x@y.z', 'roles' => ['Tailor'],
    ])->assertNotFound();
    $this->actingAs($this->ownerA)->deleteJson("/users/{$id}")->assertNotFound();

    expect($this->staffB->fresh()->name)->toBe('Bola From B');
});

it('cannot see or change another house\'s roles', function () {
    $roleB = Role::withoutGlobalScopes()->where('tenant_id', $this->houseB->id)->where('name', 'Tailor')->first();

    $this->actingAs($this->ownerA)->get("/roles/{$roleB->id}")->assertNotFound();
    $this->actingAs($this->ownerA)->patch("/roles/{$roleB->id}", ['name' => 'X', 'permission' => [1]])->assertNotFound();
    $this->actingAs($this->ownerA)->delete("/roles/{$roleB->id}")->assertNotFound();
});

it('cannot assign another house\'s role or pull in another house\'s user', function () {
    $roleA = Role::withoutGlobalScopes()->where('tenant_id', $this->houseA->id)->where('name', 'Cashier')->first();
    $roleB = Role::withoutGlobalScopes()->where('tenant_id', $this->houseB->id)->where('name', 'Cashier')->first();

    // Another house's role id is rejected.
    $this->actingAs($this->ownerA)->post('/roles/assign-users', ['roleid' => $roleB->id, 'users' => [$this->ownerA->id]])
        ->assertSessionHasErrors('roleid');

    // Another house's user is silently ignored.
    $this->actingAs($this->ownerA)->post('/roles/assign-users', ['roleid' => $roleA->id, 'users' => [$this->staffB->id]]);
    expect(\DB::table('model_has_roles')->where('model_id', $this->staffB->id)->where('tenant_id', $this->houseA->id)->exists())->toBeFalse();
});

it('cannot touch another house\'s branches', function () {
    $branchB = Branch::withoutGlobalScopes()->where('tenant_id', $this->houseB->id)->first();

    $this->actingAs($this->ownerA)->put("/settings/branches/{$branchB->id}", [
        'name' => 'Hijacked', 'code' => 'HJ', 'type' => 'branch',
    ])->assertNotFound();

    expect($branchB->fresh()->name)->toBe('Main branch');
});

it('keeps roles per house: owner of A has no rights in B', function () {
    \App\Support\Tenancy\CurrentTenant::run($this->houseB, function () {
        expect($this->ownerA->fresh()->can('View user'))->toBeFalse();
    });
    \App\Support\Tenancy\CurrentTenant::run($this->houseA, function () {
        expect($this->ownerA->fresh()->can('View user'))->toBeTrue();
    });
});

it('cannot switch into a house it is not a member of', function () {
    $this->actingAs($this->ownerA)->post('/switch-tenant', ['tenant' => $this->houseB->public_id])->assertForbidden();
});

it('shows only its own audit trail and search results', function () {
    $this->actingAs($this->ownerA)->get('/audit-logs')->assertOk()->assertDontSee('Bola From B');
    $this->actingAs($this->ownerA)->getJson('/search?q=Bola')->assertOk()->assertJsonCount(0, 'results');
});

it('lets one person work in two houses and switch between them', function () {
    \App\Support\Tenancy\CurrentTenant::run($this->houseB, function () {
        \App\Models\TenantMembership::create(['user_id' => $this->ownerA->id, 'status' => 'active']);
        $this->ownerA->assignRole('Manager');
    });
    \App\Support\Tenancy\CurrentTenant::context()->clear();

    $this->actingAs($this->ownerA)->get('/dashboard')->assertSee('House A');
    $this->actingAs($this->ownerA)->post('/switch-tenant', ['tenant' => $this->houseB->public_id])
        ->assertRedirect('/dashboard')->assertSessionHas('tenant_id', $this->houseB->id);

    // (Test requests don't carry the session cookie, so pass the switched session along.)
    $this->actingAs($this->ownerA)->withSession(['tenant_id' => $this->houseB->id])->get('/dashboard')->assertSee('House B');
    // As a Manager in B, house settings are off-limits.
    $this->actingAs($this->ownerA)->withSession(['tenant_id' => $this->houseB->id])->get('/settings/house')->assertForbidden();
    // A forged session tenant id is ignored without a membership.
    [$houseC] = makeHouse('House C');
    $this->actingAs($this->ownerA)->withSession(['tenant_id' => $houseC->id])->get('/dashboard')->assertSee('House A')->assertDontSee('House C');
});
