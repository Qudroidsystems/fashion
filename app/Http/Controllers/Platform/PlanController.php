<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use App\Support\Billing\Features;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Platform super admin: the plans houses can choose, and setting a house's plan by hand. */
class PlanController extends Controller
{
    public function __construct(protected SubscriptionService $subs)
    {
        $this->middleware('platform.admin');
    }

    public function index(): View
    {
        return view('platform.plans', [
            'pagetitle' => 'Plans',
            'plans'     => Plan::ordered()->get(),
            'counts'    => Subscription::selectRaw('plan_id, count(*) c')->groupBy('plan_id')->pluck('c', 'plan_id'),
            'features'  => Features::ALL,
            'limits'    => Features::LIMITS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = Plan::create($this->validated($request) + ['sort_order' => (int) Plan::max('sort_order') + 1]);
        AuditLogger::log('platform.plan_created', $plan, after: $plan->only(['code', 'name', 'price_monthly']), tenantId: null);

        return back()->with('success', "{$plan->name} plan added.");
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $before = $plan->only(['name', 'price_monthly', 'price_yearly', 'limits', 'features', 'is_active', 'is_public']);
        $data = $this->validated($request, $plan);
        if ($plan->is_free && empty($data['is_active'])) {
            return back()->withErrors(['plan' => 'The free plan must stay active: it is where unpaid houses go.']);
        }
        $plan->update($data);
        if ($plan->is_free) {
            Plan::whereKeyNot($plan->id)->update(['is_free' => false]);
        }
        app(\App\Services\Billing\Entitlements::class)->forget();
        AuditLogger::log('platform.plan_updated', $plan, $before, $plan->only(array_keys($before)), tenantId: null);

        return back()->with('success', "{$plan->name} saved. Houses on it see the change straight away; prices apply from their next payment.");
    }

    /** Set a house's plan by hand (e.g. paid by bank transfer, a discount, or a pilot). */
    public function setTenantPlan(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan_id'  => 'required|exists:plans,id',
            'interval' => 'required|in:monthly,yearly',
            'status'   => 'required|in:active,trialing',
            'until'    => 'nullable|date|after:today',
            'paid'     => 'nullable|numeric|min:0',
            'note'     => 'nullable|string|max:255',
        ]);
        $plan = Plan::findOrFail($data['plan_id']);
        if ($data['status'] === 'trialing' && empty($data['until'])) {
            return back()->withErrors(['until' => 'A trial needs an end date.']);
        }
        $this->subs->adminSet($tenant, $plan, $data['interval'], ! empty($data['until']) ? Carbon::parse($data['until'])->endOfDay() : null,
            $data['status'], (float) ($data['paid'] ?? 0), $request->user(), $data['note'] ?? null);

        return back()->with('success', "{$tenant->name} is now on {$plan->name}" . (! empty($data['until']) ? ' until ' . Carbon::parse($data['until'])->format('j M Y') : '') . '.');
    }

    protected function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code'          => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name'          => 'required|string|max:80',
            'tagline'       => 'nullable|string|max:160',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly'  => 'required|numeric|min:0',
            'limits'        => 'nullable|array',
            'limits.*'      => 'nullable|integer|min:1',
            'features'      => 'nullable|array',
            'features.*'    => ['string', Rule::in(array_keys(Features::ALL))],
            'is_active'     => 'nullable|boolean',
            'is_public'     => 'nullable|boolean',
            'highlight'     => 'nullable|boolean',
            'is_free'       => 'nullable|boolean',
            'sort_order'    => 'nullable|integer|min:0|max:100',
        ]);
        $limits = [];
        foreach (array_keys(Features::LIMITS) as $k) {
            $v = $data['limits'][$k] ?? null;
            $limits[$k] = $v === null || $v === '' ? null : (int) $v;   // empty = unlimited
        }

        return [
            'code' => $data['code'], 'name' => $data['name'], 'tagline' => $data['tagline'] ?? null,
            'price_monthly' => $data['price_monthly'], 'price_yearly' => $data['price_yearly'],
            'limits' => $limits, 'features' => array_values($data['features'] ?? []),
            'is_active' => $request->boolean('is_active'), 'is_public' => $request->boolean('is_public'),
            'highlight' => $request->boolean('highlight'), 'is_free' => $request->boolean('is_free'),
            'sort_order' => $data['sort_order'] ?? $plan?->sort_order ?? 0,
        ];
    }
}
