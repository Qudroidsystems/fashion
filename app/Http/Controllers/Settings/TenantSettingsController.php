<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Fashion house profile, branding and business settings (SRS FR-TEN-006). */
class TenantSettingsController extends Controller
{
    public const CURRENCIES = ['NGN' => 'Nigerian Naira (₦)', 'GHS' => 'Ghanaian Cedi (₵)', 'KES' => 'Kenyan Shilling', 'ZAR' => 'South African Rand', 'USD' => 'US Dollar ($)', 'GBP' => 'British Pound (£)', 'EUR' => 'Euro (€)'];

    public const MEASUREMENT_UNITS = ['in' => 'Inches', 'cm' => 'Centimetres'];

    public function __construct()
    {
        $this->middleware('permission:Manage tenant settings');
    }

    public function edit(): View
    {
        return view('settings.tenant', [
            'pagetitle'  => 'Fashion House Settings',
            'tenant'     => CurrentTenant::require(),
            'timezones'  => DateTimeZone::listIdentifiers(DateTimeZone::AFRICA | DateTimeZone::EUROPE | DateTimeZone::AMERICA | DateTimeZone::ASIA | DateTimeZone::UTC),
            'currencies' => self::CURRENCIES,
            'units'      => self::MEASUREMENT_UNITS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::require();

        $data = $request->validate([
            'name'                     => 'required|string|max:120',
            'email'                    => 'nullable|email|max:255',
            'phone'                    => 'nullable|string|max:30',
            'address'                  => 'nullable|string|max:255',
            'timezone'                 => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'currency_code'            => ['required', Rule::in(array_keys(self::CURRENCIES))],
            'brand_color'              => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo'                     => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_logo'              => 'nullable|boolean',
            'measurement_display_unit' => ['required', Rule::in(array_keys(self::MEASUREMENT_UNITS))],
            'numbering'                => 'nullable|array',
            'numbering.*'              => ['nullable', 'string', 'max:40', 'regex:/\{SEQ(:\d)?\}/'],
        ], [
            'numbering.*.regex' => 'Each number format must contain {SEQ} (for example ORD-{YYYY}-{SEQ:5}).',
        ]);

        $before = $tenant->only(['name', 'email', 'phone', 'address', 'timezone', 'currency_code', 'brand_color', 'logo_path']) + ['settings' => $tenant->settings];

        if ($request->boolean('remove_logo') && $tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $tenant->logo_path = null;
        }

        if ($request->hasFile('logo')) {
            if ($tenant->logo_path) {
                Storage::disk('public')->delete($tenant->logo_path);
            }
            $tenant->logo_path = $request->file('logo')->storeAs(
                'tenants/' . $tenant->public_id,
                'logo-' . Str::random(6) . '.' . $request->file('logo')->extension(),
                'public'
            );
        }

        $settings = $tenant->settings ?? [];
        $settings['measurement_display_unit'] = $data['measurement_display_unit'];
        $settings['numbering'] = array_filter(array_merge($settings['numbering'] ?? [], $data['numbering'] ?? []));

        $tenant->fill([
            'name'          => $data['name'],
            'email'         => $data['email'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'address'       => $data['address'] ?? null,
            'timezone'      => $data['timezone'],
            'currency_code' => $data['currency_code'],
            'brand_color'   => $data['brand_color'] ?? null,
            'settings'      => $settings,
        ])->save();

        $after = $tenant->only(['name', 'email', 'phone', 'address', 'timezone', 'currency_code', 'brand_color', 'logo_path']) + ['settings' => $tenant->settings];
        [$b, $a] = AuditLogger::diff($before, $after);
        if ($a) {
            AuditLogger::log('tenant.settings_updated', $tenant, $b, $a);
        }

        return redirect()->route('settings.tenant.edit')->with('success', 'Settings saved.');
    }
}
