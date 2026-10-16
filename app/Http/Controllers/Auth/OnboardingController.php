<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\TenantSettingsController;
use App\Models\TenantMembership;
use App\Services\Tenancy\TenantProvisioner;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Complete your account": someone signed up (e.g. with Google) but has no
 * fashion house yet. They name their house and it is created on the trial plan.
 */
class OnboardingController extends Controller
{
    public function __construct(protected TenantProvisioner $provisioner)
    {
        $this->middleware('auth');
    }

    protected function hasHouse(Request $request): bool
    {
        return TenantMembership::withoutTenantScope()->where('user_id', $request->user()->id)->where('status', 'active')->exists();
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->hasHouse($request)) {
            return redirect()->route('dashboard');
        }

        return view('auth.onboarding', [
            'user' => $request->user(), 'canCreate' => RegisterController::enabled(),
            'currencies' => TenantSettingsController::CURRENCIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(RegisterController::enabled(), 404);
        if ($this->hasHouse($request)) {
            return redirect()->route('dashboard');
        }
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'business_name' => 'required|string|max:120',
            'phone_number'  => 'required|string|max:30',
            'city'          => 'nullable|string|max:120',
            'currency_code' => ['required', Rule::in(array_keys(TenantSettingsController::CURRENCIES))],
            'timezone'      => ['required', Rule::in(DateTimeZone::listIdentifiers())],
        ], [], ['business_name' => 'fashion house name']);

        $user = $request->user();
        $user->update(['name' => $data['name'], 'phone_number' => $data['phone_number']]);
        $tenant = $this->provisioner->create([
            'name' => $data['business_name'], 'phone' => $data['phone_number'], 'address' => $data['city'] ?? null,
            'currency_code' => $data['currency_code'], 'timezone' => $data['timezone'], 'email' => $user->email,
        ], $user);
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('dashboard')->with('success', "Welcome to {$tenant->name}! Your free trial has started.");
    }
}
