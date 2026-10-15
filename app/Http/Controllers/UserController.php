<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff accounts of the current fashion house.
 *
 * Users are global, so "adding" an e-mail that already has an account links
 * that person to this house instead of creating a duplicate; "deleting"
 * removes them from this house (the account itself is only deleted when it
 * belongs to no other house).
 */
class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View user|Create user|Update user|Delete user', ['only' => ['index', 'show', 'roles']]);
        $this->middleware('permission:Create user', ['only' => ['store']]);
        $this->middleware('permission:Update user', ['only' => ['update']]);
        $this->middleware('permission:Delete user', ['only' => ['destroy']]);
    }

    public function index(): View
    {
        $tenantId = CurrentTenant::id();

        $data = User::inCurrentTenant()
            ->with(['roles', 'memberships' => fn ($q) => $q->where('tenant_id', $tenantId)->with('branch')])
            ->latest()
            ->get();

        $roles = Role::ofCurrentTenant()->orderBy('name')->get(['id', 'name', 'badge']);
        $branches = Branch::active()->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']);

        $role_counts = [];
        foreach ($roles as $role) {
            $role_counts[$role->name] = $data->filter(fn ($u) => $u->roles->contains('id', $role->id))->count();
        }
        $role_counts['No Role'] = $data->filter(fn ($u) => $u->roles->isEmpty())->count();

        $stats = [
            'total'     => $data->count(),
            'active'    => $data->filter(fn ($u) => $u->memberships->first()?->status === 'active' && ! $u->is_disabled)->count(),
            'suspended' => $data->filter(fn ($u) => $u->memberships->first()?->status === 'suspended')->count(),
            'owners'    => $data->filter(fn ($u) => $u->memberships->first()?->is_owner)->count(),
        ];

        return view('users.index', [
            'pagetitle'   => 'Staff & Users',
            'data'        => $data,
            'roles'       => $roles,
            'branches'    => $branches,
            'role_counts' => $role_counts,
            'stats'       => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = CurrentTenant::id();

        $validated = $request->validate([
            'name'                 => 'required|string|max:255',
            'email'                => 'required|email|max:255',
            'phone_number'         => 'nullable|string|max:30',
            'job_title'            => 'nullable|string|max:100',
            'branch_id'            => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'roles'                => 'required|array|min:1',
            'roles.*'              => ['string', Rule::exists('roles', 'name')->where('tenant_id', $tenantId)],
            'password'             => 'nullable|min:8|confirmed',
            'must_change_password' => 'nullable|boolean',
        ]);

        if ($denied = $this->guardOwnerRole($validated['roles'])) {
            return $denied;
        }

        $existing = User::where('email', $validated['email'])->first();

        if ($existing && $existing->membershipFor($tenantId)) {
            return response()->json([
                'success' => false,
                'message' => 'This person is already on your staff list.',
                'errors'  => ['email' => ['This e-mail already belongs to a member of this fashion house.']],
            ], 422);
        }

        if (! $existing && empty($validated['password'])) {
            return response()->json([
                'success' => false,
                'message' => 'Enter a password for the new account.',
                'errors'  => ['password' => ['A password is required for a new account.']],
            ], 422);
        }

        $user = DB::transaction(function () use ($validated, $existing) {
            $user = $existing ?? User::create([
                'name'                 => $validated['name'],
                'email'                => $validated['email'],
                'phone_number'         => $validated['phone_number'] ?? null,
                'password'             => $validated['password'],
                'must_change_password' => (bool) ($validated['must_change_password'] ?? false),
            ]);

            TenantMembership::create([
                'user_id'    => $user->id,
                'branch_id'  => $validated['branch_id'] ?? null,
                'job_title'  => $validated['job_title'] ?? null,
                'status'     => 'active',
                'joined_at'  => now(),
                'invited_by' => auth()->id(),
            ]);

            $user->syncRoles($validated['roles']);

            AuditLogger::log($existing ? 'user.linked' : 'user.created', $user,
                after: ['name' => $user->name, 'email' => $user->email, 'roles' => $validated['roles']]);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => $existing
                ? 'An account with this e-mail already existed — it has been added to your staff.'
                : 'User created successfully',
            'user'    => $this->present($user->fresh()),
        ], 201);
    }

    public function show(User $user): View
    {
        $membership = $this->membershipOrFail($user);
        $user->load('roles');

        $permissions = $user->getAllPermissions()->pluck('name')
            ->groupBy(fn ($p) => PermissionCatalog::groupOf($p) ?? 'Other');

        $activity = \App\Models\AuditLog::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere(fn ($q) => $q->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id));
        })->with('user:id,name')->latest('created_at')->limit(15)->get();

        return view('users.overview', [
            'pagetitle'   => 'User Overview',
            'user'        => $user,
            'membership'  => $membership->load('branch'),
            'permissions' => $permissions,
            'activity'    => $activity,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $tenantId = CurrentTenant::id();
        $membership = $this->membershipOrFail($user);

        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => 'nullable|string|max:30',
            'job_title'    => 'nullable|string|max:100',
            'branch_id'    => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'status'       => ['nullable', Rule::in(['active', 'suspended'])],
            'roles'        => 'required|array|min:1',
            'roles.*'      => ['string', Rule::exists('roles', 'name')->where('tenant_id', $tenantId)],
            'password'     => 'nullable|min:8|confirmed',
        ]);

        $isSelf = $user->id === auth()->id();
        $currentRoles = $user->getRoleNames()->all();
        $owner = PermissionCatalog::OWNER_ROLE;

        if ($denied = $this->guardOwnerRole(array_diff($validated['roles'], $currentRoles))) {
            return $denied;
        }

        if (in_array($owner, $currentRoles, true) && ! in_array($owner, $validated['roles'], true)) {
            if ($isSelf) {
                return $this->deny('You cannot remove the Owner role from yourself.');
            }
            if ($this->ownerCount() <= 1) {
                return $this->deny('A fashion house must keep at least one Owner.');
            }
        }

        if ($isSelf && ($validated['status'] ?? 'active') !== 'active') {
            return $this->deny('You cannot suspend your own account.');
        }

        // Shared account details (name/e-mail/password) belong to the person, not to this house:
        // only change them when this house is the person's only house, or it is their own account.
        $canEditIdentity = $isSelf || $user->memberships()->count() === 1;

        $before = [
            'name' => $user->name, 'email' => $user->email, 'phone_number' => $user->phone_number,
            'roles' => $currentRoles, 'status' => $membership->status,
            'branch_id' => $membership->branch_id, 'job_title' => $membership->job_title,
        ];

        DB::transaction(function () use ($user, $membership, $validated, $canEditIdentity) {
            if ($canEditIdentity) {
                $user->fill([
                    'name'         => $validated['name'],
                    'email'        => $validated['email'],
                    'phone_number' => $validated['phone_number'] ?? null,
                ]);
                if (! empty($validated['password'])) {
                    $user->password = $validated['password'];
                }
                $user->save();
            }

            $membership->update([
                'branch_id' => $validated['branch_id'] ?? null,
                'job_title' => $validated['job_title'] ?? null,
                'status'    => $validated['status'] ?? $membership->status,
            ]);

            $user->syncRoles($validated['roles']);
        });

        $user->refresh();
        $after = [
            'name' => $user->name, 'email' => $user->email, 'phone_number' => $user->phone_number,
            'roles' => $user->getRoleNames()->all(), 'status' => $membership->status,
            'branch_id' => $membership->branch_id, 'job_title' => $membership->job_title,
        ];
        [$b, $a] = AuditLogger::diff($before, $after);
        if (! empty($validated['password']) && $canEditIdentity) {
            $a['password'] = '(changed)';
        }
        if ($a) {
            AuditLogger::log(isset($a['roles']) ? 'user.roles_changed' : 'user.updated', $user, $b, $a);
        }

        return response()->json([
            'success' => true,
            'message' => $canEditIdentity
                ? 'User updated successfully'
                : 'Staff details updated. Name, e-mail and password were not changed because this person also works with another fashion house.',
            'user'    => $this->present($user),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $membership = $this->membershipOrFail($user);

        if ($user->id === auth()->id()) {
            return $this->deny('You cannot remove your own account.');
        }
        if ($membership->is_owner || $user->hasRole(PermissionCatalog::OWNER_ROLE)) {
            if ($this->ownerCount() <= 1) {
                return $this->deny('A fashion house must keep at least one Owner.');
            }
        }

        $deletedAccount = DB::transaction(function () use ($user, $membership) {
            $user->syncRoles([]);   // roles in this house only (Spatie teams)
            $membership->delete();

            AuditLogger::log('user.removed', $user, before: ['name' => $user->name, 'email' => $user->email]);

            if ($user->memberships()->doesntExist() && ! $user->is_platform_admin) {
                $user->tokens()->delete();
                $user->delete();

                return true;
            }

            return false;
        });

        return response()->json([
            'success' => true,
            'message' => $deletedAccount ? 'User deleted successfully' : 'User removed from this fashion house.',
        ]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['roles' => Role::ofCurrentTenant()->orderBy('name')->pluck('name')->all()]);
    }

    /* ------------------------------------------------------------------ */

    protected function membershipOrFail(User $user): TenantMembership
    {
        $membership = $user->membershipFor(CurrentTenant::id());
        abort_unless($membership, 404);

        return $membership;
    }

    /** Only an Owner may give someone the Owner role (no privilege escalation). */
    protected function guardOwnerRole(array $roles): ?JsonResponse
    {
        if (in_array(PermissionCatalog::OWNER_ROLE, $roles, true)
            && ! auth()->user()->hasRole(PermissionCatalog::OWNER_ROLE)) {
            return $this->deny('Only an Owner can give someone the Owner role.');
        }

        return null;
    }

    protected function ownerCount(): int
    {
        return User::inCurrentTenant()->role(PermissionCatalog::OWNER_ROLE)->count();
    }

    protected function deny(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }

    protected function present(User $user): array
    {
        $m = $user->membershipFor(CurrentTenant::id())?->load('branch');

        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'phone_number' => $user->phone_number,
            'roles'        => $user->getRoleNames()->values()->all(),
            'branch_id'    => $m?->branch_id,
            'branch'       => $m?->branch?->name,
            'job_title'    => $m?->job_title,
            'status'       => $m?->status,
        ];
    }
}
