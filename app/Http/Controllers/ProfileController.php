<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The signed-in user's own profile, photo, e-mail and password. */
class ProfileController extends Controller
{
    public function settings(Request $request): View
    {
        /** @var User $user */
        $user = $request->user()->load('roles');

        $memberships = $user->memberships()->with(['tenant', 'branch'])->get()
            ->filter(fn ($m) => $m->tenant)
            ->each(function ($m) use ($user) {
                // Role names per house (Spatie teams stores tenant_id on model_has_roles).
                $m->role_names = DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_type', $user->getMorphClass())
                    ->where('model_has_roles.model_id', $user->id)
                    ->where('model_has_roles.tenant_id', $m->tenant_id)
                    ->pluck('roles.name')->implode(', ');
            });

        $tab = in_array($request->query('tab'), ['profile', 'security', 'houses'], true)
            ? $request->query('tab')
            : ($user->must_change_password ? 'security' : 'profile');

        return view('profile.settings', [
            'pagetitle'   => 'Profile Settings',
            'user'        => $user,
            'memberships' => $memberships,
            'tab'         => $tab,
        ]);
    }

    public function updateInfo(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'username'     => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'phone_number' => 'nullable|string|max:30',
            'gender'       => ['nullable', Rule::in(['male', 'female', 'other'])],
        ]);

        $before = $user->only(array_keys($data));
        $user->update($data);
        [$b, $a] = AuditLogger::diff($before, $user->only(array_keys($data)));
        if ($a) {
            AuditLogger::log('profile.updated', $user, $b, $a);
        }

        return redirect()->route('profile.settings')->with('success', 'Profile updated.');
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();
        $name = $user->id . '_' . Str::random(8) . '.' . $request->file('avatar')->extension();
        $request->file('avatar')->storeAs('avatars', $name, 'public');

        if ($user->avatar) {
            Storage::disk('public')->delete('avatars/' . basename($user->avatar));
        }

        $user->update(['avatar' => $name]);

        return response()->json([
            'success'    => true,
            'message'    => 'Profile photo updated.',
            'avatar_url' => $user->avatar_url,
        ]);
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'email'            => ['required', 'email', 'max:255', 'confirmed', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => 'required|current_password',
        ]);

        $old = $user->email;
        $user->forceFill(['email' => $request->email, 'email_verified_at' => null])->save();
        AuditLogger::log('profile.email_changed', $user, ['email' => $old], ['email' => $user->email]);

        return response()->json(['success' => true, 'message' => 'Email updated.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|current_password',
            'password'         => 'required|string|min:8|confirmed|different:current_password',
        ]);

        $wasTemporary = $user->must_change_password;
        $user->forceFill([
            'password'             => Hash::make($request->password),
            'must_change_password' => false,
        ])->save();

        AuditLogger::log('profile.password_changed', $user, description: 'Password changed');

        return response()->json([
            'success'  => true,
            'message'  => 'Password updated.',
            'redirect' => $wasTemporary ? route('dashboard') : null,
        ]);
    }

    /** Switch the fashion house you are working in (multi-tenant mode). */
    public function switchTenant(Request $request): RedirectResponse
    {
        abort_if(CurrentTenant::isSingleMode(), 404);

        $request->validate(['tenant' => 'required|string']);

        $membership = $request->user()->memberships()->active()
            ->whereHas('tenant', fn ($q) => $q->where('public_id', $request->tenant))
            ->with('tenant')->first();

        abort_unless($membership, 403, 'You are not an active member of that fashion house.');

        $request->session()->put('tenant_id', $membership->tenant_id);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Now working in ' . $membership->tenant->name . '.');
    }
}
