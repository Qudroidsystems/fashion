<?php

namespace App\Http\Controllers\Sites;

use App\Models\StoreAccount;
use App\Models\StoreAddress;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/** Shopper accounts: sign up, sign in, password reset, details, orders and addresses. */
class AccountController extends StorefrontController
{
    protected function guard(Request $request, Tenant $t): ?Response
    {
        return $this->auth->account($t) ? null : redirect()->to($this->base($request, $t) . '/account/login')->with('toast', 'Please sign in.');
    }

    protected function next(Request $request, Tenant $t): string
    {
        $next = (string) $request->input('next', $request->query('next'));

        return $this->base($request, $t) . '/' . (in_array($next, ['checkout', 'cart', 'wishlist', 'account/orders'], true) ? $next : 'account');
    }

    public function loginForm(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->auth->account($t)
            ? redirect()->to($this->next($request, $t)) : $this->page($request, $t, 'sites.account.login'), true);
    }

    public function login(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
            $key = 'store-login:' . $t->id . ':' . mb_strtolower($data['email']) . '|' . $request->ip();
            if (RateLimiter::tooManyAttempts($key, 6)) {
                return back()->withInput()->withErrors(['email' => 'Too many attempts. Try again in a few minutes.']);
            }
            $account = StoreAccount::where('email', mb_strtolower(trim($data['email'])))->where('status', 'active')->first();
            if (! $account || ! Hash::check($data['password'], $account->password)) {
                RateLimiter::hit($key, 300);

                return back()->withInput($request->only('email'))->withErrors(['email' => 'That e-mail and password do not match.']);
            }
            RateLimiter::clear($key);
            $this->signIn($request, $t, $account);

            return redirect()->to($this->next($request, $t))->with('toast', 'Welcome back, ' . $account->first_name . '!');
        }, true);
    }

    public function registerForm(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->page($request, $t, 'sites.account.register'), true);
    }

    public function register(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $data = $request->validate([
                'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80',
                'email' => ['required', 'email', 'max:160', Rule::unique('store_accounts', 'email')->where('tenant_id', $t->id)],
                'phone' => 'nullable|string|max:30', 'password' => ['required', 'confirmed', Password::min(8)],
                'marketing_opt_in' => 'nullable|boolean', 'website' => 'nullable|max:0',
            ], ['email.unique' => 'There is already an account with this e-mail. Sign in instead.']);
            $account = StoreAccount::create(collect($data)->except(['website', 'password_confirmation'])->all() + ['status' => 'active']);
            $this->signIn($request, $t, $account);

            return redirect()->to($this->next($request, $t))->with('toast', 'Your account is ready. Welcome, ' . $account->first_name . '!');
        }, true);
    }

    /* ------------------------------------------------------------ Google */

    /** Start "Continue with Google": off to Google via the platform's single callback address. */
    public function google(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            if (! \App\Http\Controllers\Auth\GoogleController::enabled()) {
                return redirect()->to($this->base($request, $t) . '/account/login')->withErrors(['email' => 'Google sign-in is not available on this shop.']);
            }
            $nonce = Str::random(40);
            $request->session()->put('store_google_' . $t->id, ['nonce' => $nonce, 'next' => (string) $request->query('next')]);

            return redirect()->away(\App\Http\Controllers\Sites\ShopGoogleController::authUrl($t->id, $this->abs($request, $t, 'account/google/finish'), $nonce));
        }, true);
    }

    /** Back from Google with a one-time ticket: sign in, link by e-mail, or create the account. */
    public function googleFinish(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $login = $this->base($request, $t) . '/account/login';
            $started = $request->session()->pull('store_google_' . $t->id);
            if ($request->query('error') === 'cancelled') {
                return redirect()->to($login)->with('toast', 'Google sign-in was cancelled.');
            }
            $ticket = (string) $request->query('ticket');
            $data = preg_match('/^[A-Za-z0-9]{48}$/', $ticket) ? \Illuminate\Support\Facades\Cache::pull('shop-google-ticket:' . $ticket) : null;
            if (! $data || ! $started || (int) $data['tenant'] !== $t->id || ! hash_equals((string) $started['nonce'], (string) $data['nonce'])) {
                return redirect()->to($login)->withErrors(['email' => 'Google could not confirm your sign-in. Please try again.']);
            }

            $account = StoreAccount::where('google_id', $data['sub'])->first() ?? StoreAccount::where('email', $data['email'])->first();
            $created = false;
            if ($account && $account->status !== 'active') {
                return redirect()->to($login)->withErrors(['email' => 'This account is blocked. Please contact the shop.']);
            }
            if ($account) {
                $account->forceFill(['google_id' => $data['sub'], 'email_verified_at' => $account->email_verified_at ?? now(),
                    'avatar_url' => $data['picture'] ?: $account->avatar_url])->save();
            } else {
                $account = StoreAccount::create([
                    'first_name' => Str::limit(trim((string) $data['first_name']) ?: Str::before($data['email'], '@'), 80, ''),
                    'last_name' => $data['last_name'] ? Str::limit($data['last_name'], 80, '') : null, 'email' => $data['email'],
                    'google_id' => $data['sub'], 'avatar_url' => $data['picture'], 'email_verified_at' => now(),
                    'password' => Str::password(32), 'status' => 'active',
                ]);
                $created = true;
            }
            $this->signIn($request, $t, $account);
            $request->merge(['next' => $started['next'] ?? '']);

            return redirect()->to($this->next($request, $t))->with('toast', $created ? 'Your account is ready. Welcome, ' . $account->first_name . '!' : 'Welcome back, ' . $account->first_name . '!');
        }, true);
    }

    protected function signIn(Request $request, Tenant $t, StoreAccount $account): void
    {
        $token = $this->carts->token($request, $t);
        $this->auth->login($t, $account);
        $this->carts->cart($request, $t, $account);   // merges the guest basket
        $this->carts->claimWishlist($token, $account);
    }

    public function logout(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $this->auth->logout($t);

            return redirect()->to($this->base($request, $t) . '/')->with('toast', 'You are signed out.');
        });
    }

    public function forgotForm(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->page($request, $t, 'sites.account.forgot'), true);
    }

    public function forgot(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            $data = $request->validate(['email' => 'required|email']);
            $account = StoreAccount::where('email', mb_strtolower(trim($data['email'])))->where('status', 'active')->first();
            if ($account) {
                $token = Str::random(64);
                DB::table('store_password_resets')->where('account_id', $account->id)->delete();
                DB::table('store_password_resets')->insert(['tenant_id' => $t->id, 'account_id' => $account->id, 'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addHour(), 'created_at' => now()]);
                $link = $this->abs($request, $t, 'account/reset/' . $token);
                rescue(fn () => Mail::raw("Hello {$account->first_name},\n\nUse this link within an hour to choose a new password for your {$t->name} account:\n{$link}\n\nIf you did not ask for this, you can ignore this e-mail.",
                    fn ($m) => $m->to($account->email)->subject($t->name . ': reset your password')), null, false);
            }

            return back()->with('toast', 'If there is an account for that e-mail, we have sent a link to reset the password.');
        }, true);
    }

    public function resetForm(Request $request, string $site, string $token): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->page($request, $t, 'sites.account.reset', ['token' => $token]), true);
    }

    public function reset(Request $request, string $site, string $token): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $token) {
            $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);
            $row = DB::table('store_password_resets')->where('tenant_id', $t->id)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
            if (! $row) {
                return redirect()->to($this->base($request, $t) . '/account/forgot')->withErrors(['email' => 'That link has expired. Ask for a new one.']);
            }
            $account = StoreAccount::findOrFail($row->account_id);
            $account->update(['password' => $data['password']]);
            DB::table('store_password_resets')->where('account_id', $account->id)->delete();
            $this->signIn($request, $t, $account);

            return redirect()->to($this->base($request, $t) . '/account')->with('toast', 'Your password was changed.');
        }, true);
    }

    public function dashboard(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->guard($request, $t)
            ?? $this->page($request, $t, 'sites.account.details', ['tab' => 'details']), true);
    }

    public function update(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            if ($r = $this->guard($request, $t)) {
                return $r;
            }
            $account = $this->auth->account($t);
            $data = $request->validate([
                'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80', 'phone' => 'nullable|string|max:30',
                'email' => ['required', 'email', 'max:160', Rule::unique('store_accounts', 'email')->where('tenant_id', $t->id)->ignore($account->id)],
                'marketing_opt_in' => 'nullable|boolean',
                'current_password' => 'nullable|required_with:password|string', 'password' => ['nullable', 'confirmed', Password::min(8)],
            ]);
            if (! empty($data['password']) && ! Hash::check($data['current_password'] ?? '', $account->password)) {
                return back()->withErrors(['current_password' => 'Your current password is not right.']);
            }
            $account->update(collect($data)->only(['first_name', 'last_name', 'phone', 'email'])->all()
                + ['marketing_opt_in' => $request->boolean('marketing_opt_in')] + (! empty($data['password']) ? ['password' => $data['password']] : []));

            return back()->with('toast', 'Your details were saved.');
        }, true);
    }

    public function orders(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->guard($request, $t)
            ?? $this->page($request, $t, 'sites.account.orders', ['tab' => 'orders',
                'orders' => $this->auth->account($t)->orders()->with('items')->paginate(15)]), true);
    }

    public function addresses(Request $request, string $site): Response
    {
        return $this->house($request, $site, fn (Tenant $t) => $this->guard($request, $t)
            ?? $this->page($request, $t, 'sites.account.addresses', ['tab' => 'addresses',
                'states' => ($t->currency_code ?: 'NGN') === 'NGN' ? collect(json_decode((string) @file_get_contents(public_path('states_lgas.json')), true) ?: [])->pluck('state')->all() : []]), true);
    }

    public function saveAddress(Request $request, string $site): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request) {
            if ($r = $this->guard($request, $t)) {
                return $r;
            }
            $account = $this->auth->account($t);
            $data = $request->validate([
                'id' => 'nullable|integer', 'label' => 'nullable|string|max:40', 'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30',
                'line1' => 'required|string|max:190', 'line2' => 'nullable|string|max:190', 'city' => 'required|string|max:80',
                'state' => 'nullable|string|max:80', 'country' => 'nullable|string|max:80', 'postal_code' => 'nullable|string|max:20', 'is_default' => 'nullable|boolean',
            ]);
            $address = ! empty($data['id']) ? $account->addresses()->whereKey($data['id'])->firstOrFail() : new StoreAddress(['account_id' => $account->id]);
            $address->fill(collect($data)->except(['id', 'is_default'])->all() + ['account_id' => $account->id])->save();
            if ($request->boolean('is_default') || $account->addresses()->count() === 1) {
                $account->addresses()->update(['is_default' => false]);
                $address->update(['is_default' => true]);
            }

            return back()->with('toast', 'Address saved.');
        }, true);
    }

    public function deleteAddress(Request $request, string $site, int $address): Response
    {
        return $this->house($request, $site, function (Tenant $t) use ($request, $address) {
            if ($r = $this->guard($request, $t)) {
                return $r;
            }
            $this->auth->account($t)->addresses()->whereKey($address)->delete();

            return back()->with('toast', 'Address removed.');
        }, true);
    }
}
