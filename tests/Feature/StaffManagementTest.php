<?php

use App\Models\User;

beforeEach(function () {
    [$this->house, $this->owner] = makeHouse('Atelier');
});

it('creates a staff member with roles, branch and an audit record', function () {
    $branchId = \App\Models\Branch::withoutGlobalScopes()->where('tenant_id', $this->house->id)->value('id');

    $this->actingAs($this->owner)->postJson('/users', [
        'name' => 'Chidi Tailor', 'email' => 'chidi@example.com', 'roles' => ['Tailor'],
        'branch_id' => $branchId, 'job_title' => 'Head Tailor',
        'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertCreated()->assertJsonPath('user.roles.0', 'Tailor')->assertJsonPath('user.branch_id', $branchId);

    $chidi = User::where('email', 'chidi@example.com')->first();
    expect($chidi->isMemberOf($this->house))->toBeTrue();
    expect(\App\Models\AuditLog::withoutGlobalScopes()->where('action', 'user.created')->where('auditable_id', $chidi->id)->exists())->toBeTrue();
});

it('links an existing account instead of duplicating it', function () {
    [$other] = makeHouse('Other House');
    $existing = addStaff($other, 'Designer', ['email' => 'shared@example.com']);

    $this->actingAs($this->owner)->postJson('/users', [
        'name' => 'Shared', 'email' => 'shared@example.com', 'roles' => ['Cashier'],
    ])->assertCreated();

    expect(User::where('email', 'shared@example.com')->count())->toBe(1)
        ->and($existing->fresh()->isMemberOf($this->house))->toBeTrue();
});

it('does not let a manager hand out the Owner role', function () {
    $manager = addStaff($this->house, 'Manager');
    \App\Support\Tenancy\CurrentTenant::run($this->house, fn () => \App\Models\Role::where('name', 'Manager')->first()->givePermissionTo('Create user'));
    \App\Support\Tenancy\CurrentTenant::context()->clear();

    $this->actingAs($manager)->postJson('/users', [
        'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'roles' => ['Owner'],
        'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertStatus(422);
});

it('protects the last owner', function () {
    $this->actingAs($this->owner)->deleteJson("/users/{$this->owner->id}")->assertStatus(422);
    $this->actingAs($this->owner)->putJson("/users/{$this->owner->id}", [
        'name' => $this->owner->name, 'email' => $this->owner->email, 'roles' => ['Manager'],
    ])->assertStatus(422);
});

it('removes a person from the house and deletes the account when it has no other house', function () {
    $tailor = addStaff($this->house, 'Tailor');

    $this->actingAs($this->owner)->deleteJson("/users/{$tailor->id}")->assertOk();

    expect(User::find($tailor->id))->toBeNull();
});

it('cannot delete a default role or edit the Owner role', function () {
    $roles = \App\Models\Role::withoutGlobalScopes()->where('tenant_id', $this->house->id)->pluck('id', 'name');

    $this->actingAs($this->owner)->delete("/roles/{$roles['Tailor']}")->assertRedirect();
    expect(\App\Models\Role::withoutGlobalScopes()->find($roles['Tailor']))->not->toBeNull();

    $this->actingAs($this->owner)->patch("/roles/{$roles['Owner']}", ['name' => 'Owner', 'permission' => [1]])->assertForbidden();
});

it('creates a custom role with chosen permissions', function () {
    $permIds = \Spatie\Permission\Models\Permission::whereIn('name', ['View order', 'Update production'])->pluck('id')->all();

    $this->actingAs($this->owner)->post('/roles', [
        'name' => 'Finisher', 'description' => 'Final touches', 'badge' => '#aa3366', 'permission' => $permIds,
    ])->assertRedirect('/roles');

    $role = \App\Models\Role::withoutGlobalScopes()->where('tenant_id', $this->house->id)->where('name', 'Finisher')->first();
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['Update production', 'View order']);
});
