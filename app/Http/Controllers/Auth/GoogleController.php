<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sign in / sign up with Google (OAuth 2.0 authorization-code flow, no extra package).
 *
 *  - A Google account already linked, or a user with the same (Google-verified) e-mail, signs in.
 *  - Anyone else gets an account when public sign-up is on, then completes it (their fashion
 *    house) on the onboarding page. When sign-up is off, only existing users can use Google.
 */
class GoogleController extends Controller
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const USER_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public static function enabled(): bool
    {
        return (bool) config('services.google.client_id') && (bool) config('services.google.client_secret');
    }

    protected function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('auth.google.callback');
    }

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(static::enabled(), 404);
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(self::AUTH_URL . '?' . http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => $this->redirectUri(), 'response_type' => 'code',
            'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(static::enabled(), 404);
        $expected = $request->session()->pull('google_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->fail('That Google sign-in link has expired. Please try again.');
        }
        if ($request->filled('error')) {
            return $this->fail('Google sign-in was cancelled.');
        }

        $token = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $request->query('code'), 'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'), 'redirect_uri' => $this->redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (! $token->successful() || ! $token->json('access_token')) {
            return $this->fail('Google did not confirm the sign-in. Please try again.');
        }
        $profile = Http::withToken($token->json('access_token'))->acceptJson()->timeout(15)->get(self::USER_URL)->json() ?? [];
        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));
        if (empty($profile['sub']) || ! $email || ! ($profile['email_verified'] ?? false)) {
            return $this->fail('Your Google account needs a verified e-mail address.');
        }

        $user = User::where('google_id', $profile['sub'])->first() ?? User::where('email', $email)->first();
        $created = false;
        if ($user) {
            if ($user->is_disabled) {
                return $this->fail('This account has been disabled. Please contact your administrator.');
            }
            $user->forceFill(['google_id' => $profile['sub'], 'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
        } elseif (RegisterController::enabled()) {
            $user = new User(['name' => Str::limit($profile['name'] ?? Str::before($email, '@'), 255, ''), 'email' => $email,
                'password' => Str::password(32)]);
            $user->forceFill(['google_id' => $profile['sub'], 'email_verified_at' => now()])->save();
            $created = true;
            AuditLogger::log('auth.signup_google', $user, description: 'Signed up with Google', userId: $user->id);
        } else {
            return $this->fail('There is no account for ' . $email . '. Ask your fashion house to invite you.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $hasHouse = TenantMembership::withoutTenantScope()->where('user_id', $user->id)->where('status', 'active')->exists();

        // Back to the page they were trying to open, but never to the public landing page.
        $intended = $request->session()->pull('url.intended');
        $target = $intended && rtrim($intended, '/') !== rtrim(url('/'), '/') ? $intended : route('dashboard');

        return $hasHouse || $user->is_platform_admin
            ? redirect()->to($target)
            : redirect()->route('onboarding.show')->with('success', $created ? 'Welcome! One more step: tell us about your fashion house.' : null);
    }

    protected function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
