<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

/**
 * Read-only list of the platform's permissions and which of THIS house's
 * roles grant each one. Permissions themselves come from
 * App\Support\PermissionCatalog and are synced by PermissionSeeder; they
 * are not created or renamed from the UI (code checks them by name).
 */
class PermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View permission');
    }

    public function index(): View
    {
        $data = Permission::where('guard_name', 'web')->orderBy('title')->orderBy('name')->paginate(50);

        $rolesByPermission = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('roles.tenant_id', CurrentTenant::id())
            ->whereIn('role_has_permissions.permission_id', $data->pluck('id'))
            ->get(['role_has_permissions.permission_id', 'roles.id', 'roles.name', 'roles.badge'])
            ->groupBy('permission_id');

        return view('permissions.index', [
            'pagetitle'         => 'Permissions',
            'data'              => $data,
            'rolesByPermission' => $rolesByPermission,
        ]);
    }
}
