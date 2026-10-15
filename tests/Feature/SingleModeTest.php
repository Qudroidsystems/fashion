<?php

it('runs as one fashion house in single mode', function () {
    config(['tenancy.mode' => 'single', 'tenancy.default_tenant' => 'solo']);
    [$tenant, $owner] = makeHouse('Solo Studio', ['slug' => 'solo']);

    $this->get('/register')->assertNotFound();
    $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('Solo Studio');
    $this->actingAs($owner)->post('/switch-tenant', ['tenant' => $tenant->public_id])->assertNotFound();
});
