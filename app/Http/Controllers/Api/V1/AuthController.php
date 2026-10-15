<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** POST /api/v1/auth/login · GET /api/v1/me · POST /api/v1/auth/logout · POST /api/v1/auth/password */
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
