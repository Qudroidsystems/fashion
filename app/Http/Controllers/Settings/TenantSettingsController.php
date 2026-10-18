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
            'stock_approval_threshold' => 'nullable|numeric|min:0|max:1000000000',
            'po_approval_threshold'    => 'nullable|numeric|min:0|max:1000000000',
            'allow_negative_stock'     => 'nullable|boolean',
            'deposit_percent'          => 'nullable|numeric|min:0|max:100',
            'discount_limit_percent'   => 'nullable|numeric|min:0|max:100',
            'refund_approval_threshold'=> 'nullable|numeric|min:0|max:1000000000',
            'quotation_valid_days'     => 'nullable|integer|min:1|max:365',
            'production_working_days'  => 'nullable|array',
            'production_working_days.*'=> 'integer|between:1,7',
            'fitting_reminder_hours'   => ['nullable', 'string', 'max:60', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'production_task_limit'    => 'nullable|integer|min:0|max:200',
            'release_with_balance'     => 'nullable|boolean',
            'weekly_summary'           => 'nullable|boolean',
            'paystack_enabled'         => 'nullable|boolean',
            'paystack_public_key'      => ['nullable', 'string', 'max:120', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
            'paystack_secret_key'      => ['nullable', 'string', 'max:120', 'regex:/^sk_(test|live)_[A-Za-z0-9]+$/'],
            'numbering'                => 'nullable|array',
            'numbering.*'              => ['nullable', 'string', 'max:40', 'regex:/\{SEQ(:\d)?\}/'],
        ], [
            'numbering.*.regex' => 'Each number format must contain {SEQ} (for example ORD-{YYYY}-{SEQ:5}).',
            'fitting_reminder_hours.regex' => 'Enter hours as numbers separated by commas, for example 24, 2.',
            'paystack_public_key.regex' => 'That does not look like a Paystack public key (pk_test_… or pk_live_…).',
            'paystack_secret_key.regex' => 'That does not look like a Paystack secret key (sk_test_… or sk_live_…).',
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
        $settings['stock_approval_threshold'] = (float) ($data['stock_approval_threshold'] ?? $settings['stock_approval_threshold'] ?? 20000);
        $settings['po_approval_threshold'] = (float) ($data['po_approval_threshold'] ?? $settings['po_approval_threshold'] ?? 100000);
        $settings['allow_negative_stock'] = (bool) ($data['allow_negative_stock'] ?? false);
        // FR-ADM-003: the secret key is encrypted and never sent back to the browser.
        $ps = $settings['paystack'] ?? [];
        $ps['enabled'] = (bool) ($data['paystack_enabled'] ?? false);
        $ps['public_key'] = $data['paystack_public_key'] ?? ($ps['public_key'] ?? null);
        if (! empty($data['paystack_secret_key'])) {
            $ps['secret_key'] = \Illuminate\Support\Facades\Crypt::encryptString($data['paystack_secret_key']);
        }
        if ($ps['enabled'] && (empty($ps['public_key']) || empty($ps['secret_key']))) {
            return back()->withInput()->withErrors(['paystack_secret_key' => 'Add both Paystack keys to turn on online payments.']);
        }
        $settings['paystack'] = $ps;
        foreach (['deposit_percent' => 50, 'discount_limit_percent' => 10, 'refund_approval_threshold' => 0, 'quotation_valid_days' => 14] as $k => $default) {
            $settings[$k] = isset($data[$k]) ? (float) $data[$k] : ($settings[$k] ?? $default);
        }
        // Production (SRS §5.9)
        $days = array_values(array_unique(array_map('intval', $data['production_working_days'] ?? [])));
        sort($days);
        $settings['production_working_days'] = $days ?: [1, 2, 3, 4, 5, 6];
        if (isset($data['fitting_reminder_hours'])) {
            $hours = array_values(array_unique(array_filter(array_map('intval', explode(',', $data['fitting_reminder_hours'])), fn ($h) => $h > 0 && $h <= 336)));
            rsort($hours);
            $settings['fitting_reminder_hours'] = $hours;
        }
        $settings['production_task_limit'] = isset($data['production_task_limit']) ? (int) $data['production_task_limit'] : ($settings['production_task_limit'] ?? 12);
        $settings['release_with_balance'] = (bool) ($data['release_with_balance'] ?? false);
        if (array_key_exists('weekly_summary', $data)) {
            $settings['reports'] = array_merge($settings['reports'] ?? [], ['weekly_summary' => (bool) $data['weekly_summary']]);
        }
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
        // Never put secrets in the audit trail — only whether the key changed.
        $mask = function (array $snap) {
            if (isset($snap['settings']['paystack']['secret_key'])) {
                $snap['settings']['paystack']['secret_key'] = 'set:' . substr(hash('sha256', $snap['settings']['paystack']['secret_key']), 0, 8);
            }

            return $snap;
        };
        [$b, $a] = AuditLogger::diff($mask($before), $mask($after));
        if ($a) {
            AuditLogger::log('tenant.settings_updated', $tenant, $b, $a);
        }

        return redirect()->route('settings.tenant.edit')->with('success', 'Settings saved.');
    }
}
