<?php

it('logs in over the API and returns the user\'s houses', function () {
    [$tenant, $owner] = makeHouse('Api House');

    $res = $this->postJson('/api/v1/auth/login', ['login' => $owner->email, 'password' => 'password', 'device_name' => 'pixel'])
        ->assertOk()
        ->assertJsonPath('data.tenants.0.name', 'Api House');

    $token = $res->json('data.token');

    $this->withToken($token)->getJson('/api/v1/tenants/current')
        ->assertOk()->assertJsonPath('data.name', 'Api House');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()->assertJsonPath('data.current_tenant.name', 'Api House');
});

it('rejects an X-Tenant header for a house the user is not in', function () {
    [$a, $ownerA] = makeHouse('A');
    [$b] = makeHouse('B');

    $this->actingAs($ownerA, 'sanctum')
        ->withHeader('X-Tenant', $b->slug)
        ->getJson('/api/v1/tenants/current')
        ->assertForbidden()
        ->assertJsonPath('code', 'tenant_forbidden');
});

it('uses the error envelope with a correlation id', function () {
    $this->postJson('/api/v1/auth/login', ['login' => 'nobody@x.y', 'password' => 'nope', 'device_name' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['field_errors' => ['login'], 'correlation_id']);
});

it('blocks a suspended house', function () {
    [$tenant, $owner] = makeHouse('Paused');
    $tenant->update(['status' => 'suspended']);

    $this->actingAs($owner)->get('/dashboard')->assertForbidden()->assertSee('suspended');
    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/tenants/current')->assertForbidden()->assertJsonPath('code', 'tenant_unavailable');
});
