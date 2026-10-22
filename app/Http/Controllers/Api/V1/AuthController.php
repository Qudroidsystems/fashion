<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\TenantSettingsController;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use DateTimeZone;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/auth/login · POST /api/v1/auth/google · GET /api/v1/me · POST /api/v1/auth/logout · POST /api/v1/auth/password
 * GET/POST /api/v1/auth/onboarding — a new Google sign-up names their fashion house (trial starts), as on the website.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login'       => 'required|string|max:255',   // e-mail, username or phone
            'password'    => 'required|string',
            'device_name' => 'required|string|max:100',
        ]);

        $user = User::where('email', $data['login'])
            ->orWhere('username', $data['login'])
            ->orWhere('phone_number', $data['login'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::log('auth.failed', $user, description: 'Failed API sign-in for ' . mb_substr($data['login'], 0, 120), userId: $user?->id);

            throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
        }

        if ($user->is_disabled) {
            throw ValidationException::withMessages(['login' => 'This account has been disabled. Please contact your administrator.']);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(30));
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        AuditLogger::log('auth.login', $user, description: 'Signed in on ' . $data['device_name'], userId: $user->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user'       => $this->userPayload($user),
                'tenants'    => $this->tenantsPayload($user),
                'must_change_password' => $user->must_change_password,
            ],
        ]);
    }

    /**
     * Sign in or sign up with Google from the app. The app sends the Google ID token it got from
     * Google Sign-In; the token is checked with Google (audience = one of our OAuth clients,
     * e-mail verified, not expired). Same matching rules as the website: linked Google account,
     * else the same verified e-mail; new people only when public sign-up is on.
     */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate(['id_token' => 'required|string|max:4096', 'device_name' => 'required|string|max:100']);
        $audiences = array_values(array_filter(array_merge([config('services.google.client_id')], (array) config('services.google.mobile_client_ids', []))));
        abort_if(empty($audiences), 404, 'Google sign-in is not set up on this platform.');

        $res = Http::acceptJson()->timeout(15)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $data['id_token']]);
        $claims = $res->successful() ? (array) $res->json() : [];
        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
        $valid = $claims
            && in_array($claims['aud'] ?? null, $audiences, true)
            && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && (int) ($claims['exp'] ?? 0) > time()
            && ! empty($claims['sub']) && $email
            && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $valid) {
            throw ValidationException::withMessages(['id_token' => 'Google could not confirm this sign-in. Please try again.']);
        }

        $user = User::where('google_id', $claims['sub'])->first() ?? User::where('email', $email)->first();
        $created = false;
        if ($user) {
            if ($user->is_disabled) {
                throw ValidationException::withMessages(['id_token' => 'This account has been disabled. Please contact your administrator.']);
            }
            $user->forceFill(['google_id' => $claims['sub'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        } elseif (RegisterController::enabled()) {
            $user = new User(['name' => Str::limit($claims['name'] ?? Str::before($email, '@'), 255, ''), 'email' => $email, 'password' => Str::password(32)]);
            $user->forceFill(['google_id' => $claims['sub'], 'email_verified_at' => now()])->save();
            $created = true;
            AuditLogger::log('auth.signup_google', $user, description: 'Signed up with Google from the app', userId: $user->id);
        } else {
            throw ValidationException::withMessages(['id_token' => "There is no account for {$email}. Ask your fashion house to invite you."]);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(30));
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        AuditLogger::log('auth.login', $user, description: 'Signed in with Google on ' . $data['device_name'], userId: $user->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user'       => $this->userPayload($user),
                'tenants'    => $this->tenantsPayload($user),
                'created'    => $created,
                'needs_onboarding' => ! $this->hasHouse($user) && ! $user->is_platform_admin,
                'must_change_password' => false,
            ],
        ]);
    }

    /** What the "Complete your account" form needs. */
    public function onboardingOptions(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'can_create'  => RegisterController::enabled(),
            'needs_house' => ! $this->hasHouse($request->user()),
            'name'        => $request->user()->name,
            'currencies'  => TenantSettingsController::CURRENCIES,
            'default_currency' => 'NGN',
            'default_timezone' => config('app.timezone') === 'UTC' ? 'Africa/Lagos' : config('app.timezone'),
        ]]);
    }

    /** Create the new person's fashion house (trial plan), exactly like the website's /welcome. */
    public function onboarding(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        abort_unless(RegisterController::enabled(), 404, 'New fashion houses can\'t be created here. Ask your house to invite you.');
        $user = $request->user();
        abort_if($this->hasHouse($user), 409, 'You already belong to a fashion house.');
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'business_name' => 'required|string|max:120',
            'phone_number'  => 'required|string|max:30',
            'city'          => 'nullable|string|max:120',
            'currency_code' => ['required', Rule::in(array_keys(TenantSettingsController::CURRENCIES))],
            'timezone'      => ['required', Rule::in(DateTimeZone::listIdentifiers())],
        ], [], ['business_name' => 'fashion house name']);

        $user->update(['name' => $data['name'], 'phone_number' => $data['phone_number']]);
        $tenant = $provisioner->create([
            'name' => $data['business_name'], 'phone' => $data['phone_number'], 'address' => $data['city'] ?? null,
            'currency_code' => $data['currency_code'], 'timezone' => $data['timezone'], 'email' => $user->email,
        ], $user);

        return response()->json(['success' => true, 'data' => [
            'tenant'  => ['public_id' => $tenant->public_id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'tenants' => $this->tenantsPayload($user->fresh()),
        ]], 201);
    }

    protected function hasHouse(User $user): bool
    {
        return TenantMembership::withoutTenantScope()->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'user'           => $this->userPayload($user),
                'tenants'        => $this->tenantsPayload($user),
                'current_tenant' => CurrentTenant::get()?->only(['public_id', 'name', 'slug']),
                'roles'          => CurrentTenant::check() ? $user->getRoleNames() : [],
                'permissions'    => CurrentTenant::check() ? $user->getAllPermissions()->pluck('name') : [],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['success' => true, 'message' => 'Signed out.']);
    }

    public function password(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|current_password:sanctum',
            'password'         => 'required|string|min:8|confirmed|different:current_password',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->password), 'must_change_password' => false])->save();

        // Revoke every other device's token.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        AuditLogger::log('profile.password_changed', $user, description: 'Password changed from the app');

        return response()->json(['success' => true, 'message' => 'Password updated.']);
    }

    protected function userPayload(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'phone_number' => $user->phone_number,
            'avatar_url'   => $user->avatar_url,
        ];
    }

    protected function tenantsPayload(User $user): array
    {
        return $user->memberships()->active()->whereHas('tenant')->with('tenant', 'branch')->get()
            ->map(fn ($m) => [
                'public_id' => $m->tenant->public_id,
                'name'      => $m->tenant->name,
                'slug'      => $m->tenant->slug,
                'is_owner'  => $m->is_owner,
                'branch'    => $m->branch?->name,
                'usable'    => $m->tenant->isUsable(),
            ])->values()->all();
    }
}
