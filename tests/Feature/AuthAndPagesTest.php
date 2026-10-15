<?php

use App\Models\User;

it('shows the branded login page', function () {
    $this->get('/login')->assertOk()->assertSee('Sign in to continue');
});

it('redirects guests from the dashboard to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('signs in and lands on the dashboard of the user\'s house', function () {
    [$tenant, $owner] = makeHouse('Adire Couture');

    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertOk()->assertSee('Adire Couture');
});

it('lets the owner open every foundation screen', function (string $url) {
    [$tenant, $owner] = makeHouse();

    $this->actingAs($owner)->get($url)->assertOk();
})->with([
    '/dashboard', '/users', '/roles', '/permissions', '/audit-logs',
    '/settings/house', '/settings/branches', '/profile/settings', '/notifications',
]);

it('shows the user overview and role details pages', function () {
    [$tenant, $owner] = makeHouse();
    $tailor = addStaff($tenant, 'Tailor');
    $roleId = \App\Models\Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Tailor')->value('id');

    $this->actingAs($owner)->get("/users/{$tailor->id}")->assertOk()->assertSee($tailor->name);
    $this->actingAs($owner)->get("/roles/{$roleId}")->assertOk()->assertSee($tailor->name);
});

it('blocks a tailor from staff management', function () {
    [$tenant] = makeHouse();
    $tailor = addStaff($tenant, 'Tailor');

    $this->actingAs($tailor)->get('/dashboard')->assertOk();
    $this->actingAs($tailor)->get('/users')->assertForbidden();
    $this->actingAs($tailor)->get('/settings/house')->assertForbidden();
});

it('signs out a user who belongs to no fashion house', function () {
    $loner = User::factory()->create();

    $this->actingAs($loner)->get('/dashboard')->assertRedirect('/login');
});

it('forces a temporary password to be changed first', function () {
    [$tenant] = makeHouse();
    $temp = addStaff($tenant, 'Cashier', ['must_change_password' => true]);

    $this->actingAs($temp)->get('/dashboard')->assertRedirect(route('profile.settings', ['tab' => 'security']));
    $this->actingAs($temp)->get('/profile/settings')->assertOk();
});

it('lets someone register a new fashion house as its owner', function () {
    $this->post('/register', [
        'business_name' => 'Kente Kings', 'name' => 'Ama Mensah', 'email' => 'ama@example.com',
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect('/dashboard');

    $user = User::where('email', 'ama@example.com')->first();
    $tenant = \App\Models\Tenant::where('name', 'Kente Kings')->first();

    expect($tenant->status)->toBe('trial')
        ->and($user->isMemberOf($tenant))->toBeTrue();

    $this->get('/dashboard')->assertOk()->assertSee('Kente Kings');
});
