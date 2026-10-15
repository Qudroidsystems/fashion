<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

/**
 * Roles of the current fashion house. The Role model is tenant-scoped, so
 * every query here only ever sees this house's roles.
 */
class RoleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View role|Create role|Update role|Delete role|Update user-role', ['only' => ['index', 'show', 'getRoleUsers']]);
        $this->middleware('permission:Create role', ['only' => ['store']]);
        $this->middleware('permission:Update role', ['only' => ['update']]);
        $this->middleware('permission:Delete role', ['only' => ['destroy']]);
        $this->middleware('permission:Update user-role', ['only' => ['adduser', 'updateuserrole', 'removeuserrole', 'bulkRemoveUsers']]);
    }

    public function index(): View
    {
        $roles = Role::ofCurrentTenant()->with('permissions:id,name')->orderByDesc('is_system')->orderBy('name')->get();

        return view('roles.index', [
            'pagetitle'      => 'Role Management',
            'roles'          => $roles,
            'roleUserCounts' => $this->userCounts($roles->pluck('id')->all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validate($request, [
            'name'         => ['required', 'string', 'max:60', $this->uniqueName()],
            'description'  => 'nullable|string|max:255',
            'badge'        => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'permission'   => 'required|array',
            'permission.*' => 'exists:permissions,id',
        ]);

        $role = Role::create([
            'name'        => $data['name'],
            'guard_name'  => 'web',
            'description' => $data['description'] ?? null,
            'badge'       => $data['badge'] ?? null,
        ]);

        $permissions = Permission::whereIn('id', $data['permission'])->pluck('name')->all();
        $role->syncPermissions($permissions);

        AuditLogger::log('role.created', $role, after: ['name' => $role->name, 'permissions' => $permissions]);

        return redirect()->route('roles.index')->with('success', 'Role created successfully');
    }

    public function show(Role $role): View
    {
        $usersWithRole = $this->roleUsersQuery($role)
            ->select(['users.id', 'users.name as username', 'users.email', 'users.created_at'])
            ->orderBy('users.name')
            ->paginate(500);

        $candidates = User::inCurrentTenant()
            ->whereNotIn('users.id', $usersWithRole->pluck('id'))
            ->orderBy('name')->get(['id', 'name', 'email']);

        Session::put('role_url', request()->fullUrl());

        return view('roles.show', [
            'pagetitle'       => 'Role Management',
            'role'            => $role,
            'rolePermissions' => $role->permissions()->orderBy('name')->get(),
            'usersWithRole'   => $usersWithRole,
            'userRoleCount'   => $usersWithRole->total(),
            'candidates'      => $candidates,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->isOwnerRole(), 403, 'The Owner role always has every permission.');

        $data = $this->validate($request, [
            'name'         => ['required', 'string', 'max:60', $this->uniqueName($role->id)],
            'description'  => 'nullable|string|max:255',
            'badge'        => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'permission'   => 'required|array',
            'permission.*' => 'exists:permissions,id',
        ]);

        $before = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];

        $role->update([
            'name'        => $role->is_system ? $role->name : $data['name'],   // default roles keep their name
            'description' => $data['description'] ?? null,
            'badge'       => $data['badge'] ?? $role->badge,
        ]);

        $permissions = Permission::whereIn('id', $data['permission'])->pluck('name')->sort()->values()->all();
        $role->syncPermissions($permissions);

        [$b, $a] = AuditLogger::diff($before, ['name' => $role->name, 'permissions' => $permissions]);
        if ($a) {
            AuditLogger::log('role.permissions_changed', $role, $b, $a);
        }

        if ($url = session('role_url')) {
            return redirect($url)->with('success', 'Role updated successfully');
        }

        return redirect()->route('roles.index')->with('success', 'Role updated successfully');
    }

    public function adduser(Role $role): View
    {
        $users = User::inCurrentTenant()
            ->whereNotIn('users.id', $this->roleUsersQuery($role)->pluck('users.id'))
            ->orderBy('name')->get();

        return view('roles.adduser', [
            'pagetitle' => 'Role Management',
            'role'      => $role,
            'users'     => $users,
        ]);
    }

    /** Assign a role to several staff members of this house. */
    public function updateuserrole(Request $request): RedirectResponse
    {
        $tenantId = CurrentTenant::id();

        $data = $this->validate($request, [
            'users'   => 'required|array',
            'users.*' => 'integer',
            'roleid'  => ['required', Rule::exists('roles', 'id')->where('tenant_id', $tenantId)],
        ]);

        $role = Role::ofCurrentTenant()->findOrFail($data['roleid']);

        if ($role->isOwnerRole() && ! $request->user()->hasRole(PermissionCatalog::OWNER_ROLE)) {
            return back()->with('error', 'Only an Owner can give someone the Owner role.');
        }

        // Only people who belong to this house can be given its roles.
        $users = User::inCurrentTenant()->whereIn('users.id', $data['users'])->get();

        foreach ($users as $user) {
            $user->assignRole($role);
            AuditLogger::log('user.roles_changed', $user, after: ['added_role' => $role->name]);
        }

        return redirect()->route('roles.show', $role->id)
            ->with('success', $users->count() . ' user(s) added to ' . $role->name);
    }

    public function removeuserrole(Request $request, $userid, $roleid): JsonResponse
    {
        $role = Role::ofCurrentTenant()->findOrFail($roleid);
        $user = User::inCurrentTenant()->findOrFail($userid);

        if ($error = $this->ownerRemovalError($role, collect([$user]))) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $user->removeRole($role);
        AuditLogger::log('user.roles_changed', $user, before: ['removed_role' => $role->name]);

        return response()->json(['success' => true, 'message' => 'User removed from ' . $role->name]);
    }

    public function bulkRemoveUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_id'          => ['required', Rule::exists('roles', 'id')->where('tenant_id', CurrentTenant::id())],
            'selected_users'   => 'required|array',
            'selected_users.*' => 'integer',
        ]);

        $role = Role::ofCurrentTenant()->findOrFail($data['role_id']);
        $users = User::inCurrentTenant()->whereIn('users.id', $data['selected_users'])->get()
            ->filter(fn ($u) => $u->hasRole($role));

        if ($error = $this->ownerRemovalError($role, $users)) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        foreach ($users as $user) {
            $user->removeRole($role);
            AuditLogger::log('user.roles_changed', $user, before: ['removed_role' => $role->name]);
        }

        if ($users->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No users were removed. They may not have had this role.']);
        }

        return response()->json([
            'success'       => true,
            'message'       => "Removed {$users->count()} user(s) from the {$role->name} role.",
            'removed_count' => $users->count(),
            'removed_users' => $users->pluck('name')->values(),
        ]);
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return redirect()->route('roles.index')->with('error', 'Default roles cannot be deleted. Remove their permissions instead.');
        }

        AuditLogger::log('role.deleted', $role, before: ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()]);
        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role deleted successfully');
    }

    public function getRoleUsers(Role $role, Request $request)
    {
        $usersWithRole = $this->roleUsersQuery($role)->select('users.*')->orderBy('users.name')
            ->paginate((int) $request->get('per_page', 10));

        if ($request->ajax()) {
            return response()->json([
                'success'      => true,
                'html'         => view('roles.partials.users_table_rows', ['users' => $usersWithRole, 'role' => $role])->render(),
                'pagination'   => view('roles.partials.pagination', ['users' => $usersWithRole])->render(),
                'total'        => $usersWithRole->total(),
                'current_page' => $usersWithRole->currentPage(),
                'last_page'    => $usersWithRole->lastPage(),
                'per_page'     => $usersWithRole->perPage(),
            ]);
        }

        return redirect()->route('roles.show', $role);
    }

    /* ------------------------------------------------------------------ */

    /** Users holding this role in the current tenant. */
    protected function roleUsersQuery(Role $role)
    {
        return User::query()
            ->join('model_has_roles as mhr', function ($join) use ($role) {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', (new User)->getMorphClass())
                    ->where('mhr.role_id', $role->id)
                    ->where('mhr.tenant_id', CurrentTenant::id());
            });
    }

    protected function userCounts(array $roleIds): array
    {
        return DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->where('tenant_id', CurrentTenant::id())
            ->selectRaw('role_id, count(*) as c')->groupBy('role_id')
            ->pluck('c', 'role_id')->all();
    }

    protected function uniqueName(?int $ignoreId = null)
    {
        return Rule::unique('roles', 'name')
            ->where('tenant_id', CurrentTenant::id())
            ->where('guard_name', 'web')
            ->ignore($ignoreId);
    }

    protected function ownerRemovalError(Role $role, $users): ?string
    {
        if (! $role->isOwnerRole()) {
            return null;
        }
        if ($users->contains('id', auth()->id())) {
            return 'You cannot remove the Owner role from yourself.';
        }
        $owners = $this->roleUsersQuery($role)->count();

        return $owners - $users->count() < 1 ? 'A fashion house must keep at least one Owner.' : null;
    }
}
