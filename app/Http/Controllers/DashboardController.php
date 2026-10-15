<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\View\View;

/**
 * Fashion house home. For now: account health, set-up checklist and the
 * module roadmap from the SRS. Business KPIs (sales, collections, order
 * pipeline, low stock, workload — FR-REP-001) are added as modules land.
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
            'modules'   => $this->modules(),
        ]);
    }

    /** SRS roadmap modules, in build order (§16). */
    protected function modules(): array
    {
        return [
            ['icon' => 'ri-user-heart-line',      'name' => 'Customers (CRM)',        'phase' => 2, 'desc' => 'Customer profiles, preferences, consent, follow-ups and timeline.', 'route' => 'customers.index'],
            ['icon' => 'ri-ruler-2-line',         'name' => 'Measurements',           'phase' => 2, 'desc' => 'Versioned templates, staff capture, approval, comparison and secure request links.', 'route' => 'measurements.index'],
            ['icon' => 'ri-t-shirt-air-line',     'name' => 'Styles & Catalogue',     'phase' => 3, 'desc' => 'Style catalogue, versions and ready-made products.'],
            ['icon' => 'ri-archive-line',         'name' => 'Inventory & Purchasing', 'phase' => 3, 'desc' => 'Fabric and accessories, suppliers, purchase orders and the stock ledger.'],
            ['icon' => 'ri-file-list-3-line',     'name' => 'Orders & Quotes',        'phase' => 4, 'desc' => 'Quotations, garment lines, invoices, deposits and balances.'],
            ['icon' => 'ri-shopping-cart-2-line', 'name' => 'POS & Payments',         'phase' => 4, 'desc' => 'Ready-made sales, cashier shifts, receipts and refunds.'],
            ['icon' => 'ri-scissors-cut-line',    'name' => 'Production',             'phase' => 5, 'desc' => 'Task board, fittings, quality checks, alterations and delivery.'],
            ['icon' => 'ri-heart-2-line',         'name' => 'Weddings & Bulk Projects','phase' => 5, 'desc' => 'Participants, per-garment tracking, milestones and client progress pages.'],
            ['icon' => 'ri-notification-badge-line','name' => 'Notifications & Apps', 'phase' => 6, 'desc' => 'Reminders, SMS / e-mail / WhatsApp, staff and customer mobile apps.'],
            ['icon' => 'ri-bar-chart-box-line',   'name' => 'Reports',                'phase' => 7, 'desc' => 'Sales, receivables, stock valuation, throughput and project profitability.'],
        ];
    }
}
