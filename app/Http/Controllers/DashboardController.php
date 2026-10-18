<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\View\View;

/**
 * Fashion house home (SRS FR-REP-001): money collected and owed, the order
 * pipeline with what is due or late, workroom load, low stock and follow-ups —
 * each shown only to people allowed to see it — plus the set-up checklist
 * until it is done.
 */
class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View dashboard');
    }

    public function index(): View
    {
        $tenant = CurrentTenant::require();

        $tz = $tenant->timezone ?: config('app.timezone');

        $stats = [
            'customers' => \App\Models\Customer::active()->count(),
            'new_customers' => \App\Models\Customer::active()->where('created_at', '>=', now($tz)->startOfMonth()->utc())->count(),
            'follow_ups_due' => \App\Models\FollowUpTask::open()->where('assigned_to', auth()->id())
                ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', now($tz)->endOfDay()->utc()))->count(),
            'overdue'  => \App\Models\FollowUpTask::overdue()->count(),
            'staff'    => TenantMembership::active()->count(),
            'branches' => Branch::active()->count(),
            'roles'    => Role::ofCurrentTenant()->count(),
            'invited'  => TenantMembership::where('status', 'invited')->count(),
        ];

        $checklist = [
            ['done' => (bool) ($tenant->logo_path && $tenant->phone), 'label' => 'Add your logo and contact details', 'route' => 'settings.tenant.edit', 'can' => 'Manage tenant settings'],
            ['done' => $stats['branches'] > 1 || Branch::whereNotNull('address')->exists(), 'label' => 'Set up your branches and locations', 'route' => 'settings.branches.index', 'can' => 'View branch'],
            ['done' => $stats['staff'] > 1, 'label' => 'Add your staff', 'route' => 'users.index', 'can' => 'View user'],
            ['done' => AuditLog::where('action', 'role.permissions_changed')->exists(), 'label' => 'Review what each role can do', 'route' => 'roles.index', 'can' => 'View role'],
        ];

        $birthdays = auth()->user()->can('View customer')
            ? \App\Models\Customer::active()->whereNotNull('birthday')
                ->where(fn ($q) => $q->whereMonth('birthday', now($tz)->month)->orWhereMonth('birthday', now($tz)->addDays(7)->month))
                ->get(['id', 'public_id', 'first_name', 'last_name', 'birthday', 'phone'])
                ->map(fn ($c) => tap($c, fn ($c) => $c->next_bday = $c->nextBirthday($tz)))
                ->filter(fn ($c) => $c->next_bday->lte(now($tz)->addDays(7)))
                ->sortBy('next_bday')->take(8)->values()
            : collect();

        $recent = auth()->user()->can('View audit log')
            ? AuditLog::with('user:id,name')->latest('created_at')->limit(8)->get()
            : collect();

        return view('dashboard.index', [
            'pagetitle' => 'Dashboard',
            'tenant'    => $tenant,
            'stats'     => $stats,
            'checklist' => $checklist,
            'recent'    => $recent,
            'birthdays' => $birthdays,
            'figures'   => (new \App\Services\Reports\DashboardFigures(auth()->user()))->all(),
            'reports'   => auth()->user()->can('View reports')
                ? collect(app(\App\Services\Reports\ReportRegistry::class)->visibleTo(auth()->user()))->take(6) : collect(),
        ]);
    }
}
