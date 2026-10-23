<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.google.client_id' => 'web.apps.googleusercontent.com',
        'services.google.client_secret' => 'secret',
        'services.google.mobile_client_ids' => ['ios.apps.googleusercontent.com'],
    ]);
});

/** What Google's tokeninfo endpoint answers; can be changed within one test. */
function fakeGoogleToken(array $claims): void
{
    $GLOBALS['google_claims'] = $claims + [
        'iss' => 'https://accounts.google.com', 'exp' => (string) (time() + 600), 'email_verified' => 'true',
    ];
    if (($GLOBALS['google_fake_app'] ?? null) !== app()) {
        $GLOBALS['google_fake_app'] = app();
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => fn () => Http::response($GLOBALS['google_claims'])]);
    }
}

it('signs up a new person with Google from the app, then creates their house', function () {
    fakeGoogleToken(['sub' => '555', 'email' => 'Ngozi@Gmail.com', 'name' => 'Ngozi Eze', 'aud' => 'ios.apps.googleusercontent.com']);

    $res = $this->postJson('/api/v1/auth/google', ['id_token' => 'eyJ.fake', 'device_name' => 'iPhone'])
        ->assertOk()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.needs_onboarding', true);
    $token = $res->json('data.token');
    expect(User::where('email', 'ngozi@gmail.com')->value('google_id'))->toBe('555');

    $this->withToken($token)->getJson('/api/v1/auth/onboarding')->assertOk()->assertJsonPath('data.needs_house', true);
    $this->withToken($token)->postJson('/api/v1/auth/onboarding', [
        'name' => 'Ngozi Eze', 'business_name' => 'Ngozi Atelier', 'phone_number' => '08035550000',
        'currency_code' => 'NGN', 'timezone' => 'Africa/Lagos',
    ])->assertCreated()->assertJsonPath('data.tenant.name', 'Ngozi Atelier');

    expect(Tenant::where('name', 'Ngozi Atelier')->exists())->toBeTrue();
    $this->withToken($token)->postJson('/api/v1/auth/onboarding', [
        'name' => 'Ngozi Eze', 'business_name' => 'Second', 'phone_number' => '0803', 'currency_code' => 'NGN', 'timezone' => 'Africa/Lagos',
    ])->assertStatus(409);
});

it('links an existing account by verified e-mail and refuses bad tokens', function () {
    [, $owner] = makeHouse('Google House');

    fakeGoogleToken(['sub' => '777', 'email' => $owner->email, 'aud' => 'web.apps.googleusercontent.com']);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'device_name' => 'Pixel'])
        ->assertOk()->assertJsonPath('data.needs_onboarding', false)->assertJsonPath('data.tenants.0.name', 'Google House');
    expect($owner->fresh()->google_id)->toBe('777');

    // A token minted for someone else's app is refused.
    fakeGoogleToken(['sub' => '777', 'email' => $owner->email, 'aud' => 'someone-else.apps.googleusercontent.com']);
    $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'device_name' => 'Pixel'])->assertStatus(422);
});

it('only lets existing people in when public sign-up is off', function () {
    config(['tenancy.self_signup' => false]);
    fakeGoogleToken(['sub' => '9', 'email' => 'stranger@gmail.com', 'aud' => 'web.apps.googleusercontent.com']);

    $this->postJson('/api/v1/auth/google', ['id_token' => 'x', 'device_name' => 'Pixel'])->assertStatus(422);
    expect(User::where('email', 'stranger@gmail.com')->exists())->toBeFalse();
});

it('resets a password from the console without putting it on the command line', function () {
    $user = User::factory()->create(['email' => 'admin@fashionhouse.test']);
    $user->createToken('old phone');

    $this->artisan('fhmp:reset-password', ['email' => 'admin@fashionhouse.test'])
        ->expectsQuestion("New password for {$user->name} ({$user->email})", 'n3w-Secret!')
        ->expectsQuestion('Type it again', 'n3w-Secret!')
        ->assertExitCode(0);

    expect(Hash::check('n3w-Secret!', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->artisan('fhmp:reset-password', ['email' => 'nobody@x.test'])->assertExitCode(1);
});
