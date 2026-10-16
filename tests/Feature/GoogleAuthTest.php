<?php

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.google.client_id' => 'gid.apps.googleusercontent.com', 'services.google.client_secret' => 'gsecret']);
});

/** Start the Google flow and come back with Google's answer for this profile. */
function googleReturns($test, array $profile): \Illuminate\Testing\TestResponse
{
    $GLOBALS['google_profile'] = $profile;
    if (($GLOBALS['google_app'] ?? null) !== app()) {
        $GLOBALS['google_app'] = app();
        Http::fake(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            ? Http::response(['access_token' => 'ya29.token', 'id_token' => 'x'])
            : Http::response($GLOBALS['google_profile']));
    }
    $go = $test->get('/auth/google');
    $go->assertRedirect();
    parse_str(parse_url($go->headers->get('Location'), PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('gid.apps.googleusercontent.com')->and($q['scope'])->toBe('openid email profile');

    return $test->get('/auth/google/callback?code=4/abc&state=' . $q['state']);
}

it('signs up a new person with Google and has them complete their account (their fashion house)', function () {
    $this->get('/login')->assertOk()->assertSee('Continue with Google');
    $this->get('/register')->assertOk()->assertSee('Sign up with Google');

    googleReturns($this, ['sub' => '1098', 'email' => 'Adunni@Gmail.com', 'email_verified' => true, 'name' => 'Adunni Bello'])
        ->assertRedirect('/welcome');
    $user = User::where('email', 'adunni@gmail.com')->firstOrFail();
    expect($user->google_id)->toBe('1098')->and($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);

    // Anywhere they go, they are asked to finish first.
    $this->get('/dashboard')->assertRedirect('/welcome');
    $this->get('/welcome')->assertOk()->assertSee('Complete your account')->assertSee('adunni@gmail.com');
    $this->post('/welcome', ['name' => 'Adunni Bello', 'business_name' => 'Adunni Couture', 'phone_number' => '08031112222',
        'city' => 'Lekki', 'currency_code' => 'NGN', 'timezone' => 'Africa/Lagos'])->assertRedirect('/dashboard');

    $house = Tenant::where('name', 'Adunni Couture')->firstOrFail();
    expect(TenantMembership::withoutTenantScope()->where('tenant_id', $house->id)->where('user_id', $user->id)->value('is_owner'))->toBeTruthy()
        ->and(Subscription::where('tenant_id', $house->id)->first()->status)->toBe('trialing')
        ->and($user->fresh()->phone_number)->toBe('08031112222');
    $this->get('/dashboard')->assertOk()->assertSee('Adunni Couture');
    $this->get('/welcome')->assertRedirect('/dashboard');
});

it('signs existing users in with Google by their verified e-mail, and refuses what it should', function () {
    [, $owner] = makeHouse('Atelier');
    $this->post('/logout');
    googleReturns($this, ['sub' => '777', 'email' => $owner->email, 'email_verified' => true, 'name' => 'X'])->assertRedirect('/dashboard');
    expect($owner->fresh()->google_id)->toBe('777');
    $this->post('/logout');

    // Unverified e-mail, a tampered state, a disabled user.
    googleReturns($this, ['sub' => '9', 'email' => 'nobody@example.com', 'email_verified' => false])->assertRedirect('/login')->assertSessionHas('error');
    $this->get('/auth/google');
    $this->get('/auth/google/callback?code=x&state=forged')->assertRedirect('/login')->assertSessionHas('error', 'That Google sign-in link has expired. Please try again.');
    $owner->forceFill(['is_disabled' => true])->save();
    googleReturns($this, ['sub' => '777', 'email' => $owner->email, 'email_verified' => true])->assertRedirect('/login')->assertSessionHas('error');
    $this->assertGuest();

    // Without public sign-up, strangers can't create accounts with Google.
    config(['tenancy.self_signup' => false]);
    googleReturns($this, ['sub' => '55', 'email' => 'stranger@example.com', 'email_verified' => true])->assertRedirect('/login')
        ->assertSessionHas('error', 'There is no account for stranger@example.com. Ask your fashion house to invite you.');
    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();

    // Not configured: no button, no route.
    config(['services.google.client_id' => null]);
    $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
    $this->get('/auth/google')->assertNotFound();
});
