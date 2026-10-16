<?php

it('shows the public landing page to everyone, with sign-in or dashboard links as fits', function () {
    config(['landing.app.android_url' => null, 'landing.app.ios_url' => null]);
    $this->get('/')->assertOk()->assertSee('From the tape measure to the final fitting.')
        ->assertSee(route('login'), false)->assertSee('Coming soon on')->assertSee('Demo app available');

    config(['landing.app.android_url' => 'https://example.com/fhmp-demo.apk']);
    $this->get('/')->assertOk()->assertSee('https://example.com/fhmp-demo.apk', false)->assertSee('Download the demo for');

    [, $owner] = makeHouse('Atelier');
    // Signed-in users see the page too, with a way back to their dashboard.
    $this->actingAs($owner)->get('/')->assertOk()->assertSee('Open your dashboard')->assertSee(route('dashboard'), false)
        ->assertDontSee('Set up your fashion house');
    $this->post('/logout');
    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/dashboard');

    // Single-house installs have no public sign-up.
    $this->post('/logout');
    config(['tenancy.mode' => 'single']);
    $this->get('/')->assertOk()->assertDontSee('Set up your fashion house');
});
