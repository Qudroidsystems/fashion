<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google sign-in for shoppers (all houses).
 *
 * House shops live on many addresses ({slug}.domain, /s/{slug}, own domains) but Google only
 * accepts fixed redirect URIs, so every shop sends the shopper through ONE callback on the
 * platform ({APP_URL}/shop-auth/google/callback):
 *
 *   shop  /account/google          → state kept in the cache (tenant, return address, browser nonce)
 *   Google                         → platform /shop-auth/google/callback (this controller)
 *   platform                       → one-time ticket in the cache → shop /account/google/finish?ticket=…
 *   shop                           → checks the ticket belongs to this house and this browser, signs in.
 */
class ShopGoogleController extends Controller
{
    public static function redirectUri(): string
    {
        return config('services.google.shop_redirect') ?: rtrim((string) config('app.url'), '/') . '/shop-auth/google/callback';
    }

    /** Called from the shop: where to send the shopper at Google. */
    public static function authUrl(int $tenantId, string $finishUrl, string $nonce): string
    {
        $state = Str::random(48);
        Cache::put('shop-google:' . $state, ['tenant' => $tenantId, 'finish' => $finishUrl, 'nonce' => $nonce], now()->addMinutes(15));

        return GoogleController::AUTH_URL . '?' . http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account',
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(GoogleController::enabled(), 404);
        $state = (string) $request->query('state');
        $ctx = preg_match('/^[A-Za-z0-9]{48}$/', $state) ? Cache::pull('shop-google:' . $state) : null;
        if (! $ctx) {
            return redirect()->to(url('/'))->with('error', 'That Google sign-in link has expired. Please try again from the shop.');
        }
        $back = fn (array $extra) => redirect()->away($ctx['finish'] . (str_contains($ctx['finish'], '?') ? '&' : '?') . http_build_query($extra));
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back(['error' => 'cancelled']);
        }

        try {
            $token = Http::asForm()->acceptJson()->timeout(15)->post(GoogleController::TOKEN_URL, [
                'code' => $request->query('code'), 'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'), 'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
            ]);
            $profile = $token->successful() && $token->json('access_token')
                ? (Http::withToken($token->json('access_token'))->acceptJson()->timeout(15)->get(GoogleController::USER_URL)->json() ?? []) : [];
        } catch (\Throwable) {
            $profile = [];
        }
        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));
        if (empty($profile['sub']) || ! $email || ! ($profile['email_verified'] ?? false)) {
            return $back(['error' => 'failed']);
        }

        $ticket = Str::random(48);
        Cache::put('shop-google-ticket:' . $ticket, [
            'tenant' => $ctx['tenant'], 'nonce' => $ctx['nonce'], 'sub' => (string) $profile['sub'], 'email' => $email,
            'first_name' => $profile['given_name'] ?? Str::before($profile['name'] ?? $email, ' '),
            'last_name' => $profile['family_name'] ?? null, 'picture' => $profile['picture'] ?? null,
        ], now()->addMinutes(5));

        return $back(['ticket' => $ticket]);
    }
}
