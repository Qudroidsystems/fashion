<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Platform operator screen (SRS FR-ADM-001): list fashion houses, onboard a
 * new one, change subscription status. It deliberately shows no tenant
 * business data — support access to that must be time-bound and audited
 * (FR-ADM-002) and is a later feature.
 */
class TenantController extends Controller
{
    public function __construct(protected TenantProvisioner $provisioner)
    {
        $this->middleware('platform.admin');
    }

    public function index(Request $request): View
    {
        $tenants = Tenant::with('subscription.plan')->withCount([
                'memberships as staff_count' => fn ($q) => $q->where('status', 'active'),
                'branches',
            ])
            ->when($request->q, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', "%$v%")->orWhere('slug', 'like', "%$v%")))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()->paginate(25)->withQueryString();

        return view('platform.tenants', [
            'pagetitle' => 'Fashion Houses',
            'tenants'   => $tenants,
            'statuses'  => Tenant::STATUSES,
            'counts'    => Tenant::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'plans'     => \App\Models\Plan::ordered()->get(),
            'billing'   => app(\App\Services\Billing\Entitlements::class)->enabled(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'slug'        => ['nullable', 'alpha_dash', 'max:80', Rule::unique('tenants', 'slug')],
            'status'      => ['required', Rule::in(['trial', 'active'])],
            'owner_name'  => 'required|string|max:255',
            'owner_email' => 'required|email|max:255',
            'currency_code' => 'nullable|string|size:3',
        ]);

        $password = null;

        $tenant = DB::transaction(function () use ($data, &$password) {
            $owner = User::where('email', $data['owner_email'])->first();
            if (! $owner) {
                $password = Str::password(12, symbols: false);
                $owner = User::create([
                    'name'                 => $data['owner_name'],
                    'email'                => $data['owner_email'],
                    'password'             => $password,
                    'must_change_password' => true,
                ]);
            }

            return $this->provisioner->create([
                'name'          => $data['name'],
                'slug'          => $data['slug'] ?: null,
                'status'        => $data['status'],
                'currency_code' => strtoupper($data['currency_code'] ?? config('tenancy.defaults.currency')),
            ], $owner);
        });

        $msg = "{$tenant->name} created.";
        if ($password) {
            $msg .= " Owner sign-in: {$data['owner_email']} / temporary password: {$password} (shown once — they must change it at first sign-in).";
        } else {
            $msg .= ' The owner already had an account and can switch to this house after signing in.';
        }

        return redirect()->route('platform.tenants.index')->with('success', $msg);
    }

    public function updateStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'status'        => ['required', Rule::in(Tenant::STATUSES)],
            'trial_ends_at' => 'nullable|date',
            'grace_ends_at' => 'nullable|date',
        ]);

        $before = $tenant->only(['status', 'trial_ends_at', 'grace_ends_at']);
        $tenant->update($data);

        AuditLogger::log('tenant.status_changed', $tenant, $before, $tenant->only(['status', 'trial_ends_at', 'grace_ends_at']), tenantId: $tenant->id);

        return back()->with('success', "{$tenant->name} is now " . str_replace('_', ' ', $tenant->status) . '.');
    }
}
