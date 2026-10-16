<?php

it('shows the public landing page to visitors and sends signed-in users to their dashboard', function () {
    config(['landing.app.android_url' => null, 'landing.app.ios_url' => null]);
    $this->get('/')->assertOk()->assertSee('From the tape measure to the final fitting.')
        ->assertSee(route('login'), false)->assertSee('Coming soon on')->assertSee('Demo app available');

    config(['landing.app.android_url' => 'https://example.com/fhmp-demo.apk']);
    $this->get('/')->assertOk()->assertSee('https://example.com/fhmp-demo.apk', false)->assertSee('Download the demo for');

    [, $owner] = makeHouse('Atelier');
    $this->actingAs($owner)->get('/')->assertRedirect(route('dashboard'));

    // Single-house installs have no public sign-up.
    auth()->logout();
    config(['tenancy.mode' => 'single']);
    $this->get('/')->assertOk()->assertDontSee('Set up your fashion house');
});
