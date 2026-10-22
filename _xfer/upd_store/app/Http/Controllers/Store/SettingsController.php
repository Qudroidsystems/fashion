<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Entitlements;
use App\Services\Payments\PaystackGateway;
use App\Services\Sites\SiteService;
use App\Services\Store\StoreSettings;
use App\Services\Store\WhatsApp;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/** Online store › Settings: switch the shop on, payment methods and keys, bank details, WhatsApp, delivery, policies. */
class SettingsController extends Controller
{
    public function __construct(protected StoreSettings $settings, protected SiteService $sites, protected Entitlements $entitlements)
    {
        $this->middleware('permission:Manage online store');
    }

    public function edit(): View
    {
        $tenant = CurrentTenant::require();

        return view('store-admin.settings', [
            'pagetitle'  => 'Shop settings',
            'tenant'     => $tenant,
            's'          => StoreSettings::get($tenant),
            'siteLive'   => $this->sites->isLive($tenant),
            'open'       => $this->settings->isOpen($tenant),
            'url'        => $this->settings->url($tenant),
            'branches'   => Branch::active()->orderByDesc('is_main')->get(),
            'paystackOn' => PaystackGateway::for($tenant)->enabled(),
            'available'  => $this->settings->methods($tenant),
            'waNumber'   => WhatsApp::houseNumber($tenant),
            'stripeHook' => route('webhooks.stripe', $tenant->public_id),
            'paystackHook' => route('webhooks.paystack', $tenant->public_id),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = CurrentTenant::require();
        abort_unless($this->entitlements->has($tenant, 'online_store'), 403, $this->entitlements->upgradeMessage($tenant, 'online_store'));
        $data = $request->validate([
            'enabled' => 'nullable|boolean', 'guest_checkout' => 'nullable|boolean', 'hide_out_of_stock' => 'nullable|boolean', 'allow_backorder' => 'nullable|boolean',
            'fulfilment_branch_id' => 'nullable|integer', 'low_stock_badge' => 'nullable|integer|min:0|max:100',
            'card_hold_hours' => 'nullable|integer|min:1|max:72', 'manual_hold_days' => 'nullable|integer|min:1|max:30',
            'announcement' => 'nullable|string|max:160', 'notify_emails' => 'nullable|string|max:500',
            'methods' => 'nullable|array', 'methods.*' => 'nullable|boolean',
            'stripe_publishable_key' => ['nullable', 'string', 'max:200', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
            'stripe_secret_key' => ['nullable', 'string', 'max:200', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:200', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'bank_name' => 'nullable|string|max:80', 'account_name' => 'nullable|string|max:120', 'account_number' => 'nullable|string|max:40',
            'bank_instructions' => 'nullable|string|max:500',
            'whatsapp_number' => 'nullable|string|max:30', 'whatsapp_button_label' => 'nullable|string|max:40',
            'cloud_enabled' => 'nullable|boolean', 'cloud_token' => 'nullable|string|max:500', 'cloud_phone_id' => 'nullable|string|max:40',
            'notify_numbers' => 'nullable|string|max:300', 'template' => 'nullable|string|max:80', 'template_lang' => 'nullable|string|max:10',
            'delivery_enabled' => 'nullable|boolean', 'default_fee' => 'nullable|numeric|min:0', 'default_eta' => 'nullable|string|max:60',
            'pickup_enabled' => 'nullable|boolean', 'pickup_branch_ids' => 'nullable|array', 'pickup_branch_ids.*' => 'integer', 'pickup_note' => 'nullable|string|max:160',
            'policy_shipping' => 'nullable|string|max:10000', 'policy_returns' => 'nullable|string|max:10000',
            'policy_terms' => 'nullable|string|max:20000', 'policy_privacy' => 'nullable|string|max:20000', 'size_guide' => 'nullable|string|max:10000',
        ], [
            'stripe_publishable_key.regex' => 'That does not look like a Stripe publishable key (pk_test_… or pk_live_…).',
            'stripe_secret_key.regex' => 'That does not look like a Stripe secret key (sk_test_… / sk_live_… or a restricted rk_ key).',
            'stripe_webhook_secret.regex' => 'The signing secret starts with whsec_.',
        ]);

        $old = StoreSettings::get($tenant);
        $s = $old;
        foreach (['enabled', 'guest_checkout', 'hide_out_of_stock', 'allow_backorder'] as $k) {
            $s[$k] = $request->boolean($k);
        }
        $s['fulfilment_branch_id'] = Branch::whereKey((int) ($data['fulfilment_branch_id'] ?? 0))->value('id');
        $s['low_stock_badge'] = (int) ($data['low_stock_badge'] ?? 3);
        $s['card_hold_hours'] = $data['card_hold_hours'] ?? null;
        $s['manual_hold_days'] = $data['manual_hold_days'] ?? null;
        $s['announcement'] = $data['announcement'] ?? null;
        $s['notify_emails'] = $data['notify_emails'] ?? null;
        foreach (array_keys(StoreSettings::DEFAULTS['methods']) as $m) {
            $s['methods'][$m] = (bool) ($data['methods'][$m] ?? false);
        }
        $s['stripe']['publishable_key'] = $data['stripe_publishable_key'] ?? $s['stripe']['publishable_key'];
        foreach (['stripe_secret_key' => 'secret_key', 'stripe_webhook_secret' => 'webhook_secret'] as $in => $k) {
            if (! empty($data[$in])) {
                $s['stripe'][$k] = Crypt::encryptString($data[$in]);
            }
        }
        if ($request->boolean('stripe_forget')) {
            $s['stripe'] = ['publishable_key' => null, 'secret_key' => null, 'webhook_secret' => null];
            $s['methods']['stripe'] = false;
        }
        $s['bank'] = ['bank_name' => $data['bank_name'] ?? null, 'account_name' => $data['account_name'] ?? null,
            'account_number' => $data['account_number'] ?? null, 'instructions' => $data['bank_instructions'] ?? null];
        $s['whatsapp']['number'] = $data['whatsapp_number'] ?? null;
        $s['whatsapp']['button_label'] = ($data['whatsapp_button_label'] ?? null) ?: 'Order on WhatsApp';
        $s['whatsapp']['cloud_enabled'] = $request->boolean('cloud_enabled');
        $s['whatsapp']['cloud_phone_id'] = $data['cloud_phone_id'] ?? null;
        $s['whatsapp']['notify_numbers'] = $data['notify_numbers'] ?? null;
        $s['whatsapp']['template'] = $data['template'] ?? null;
        $s['whatsapp']['template_lang'] = ($data['template_lang'] ?? null) ?: 'en';
        if (! empty($data['cloud_token'])) {
            $s['whatsapp']['cloud_token'] = Crypt::encryptString($data['cloud_token']);
        }
        $s['delivery'] = ['enabled' => $request->boolean('delivery_enabled'), 'default_fee' => $data['default_fee'] ?? null, 'default_eta' => $data['default_eta'] ?? null];
        $s['pickup'] = ['enabled' => $request->boolean('pickup_enabled'), 'branch_ids' => array_values(array_map('intval', $data['pickup_branch_ids'] ?? [])),
            'note' => $data['pickup_note'] ?? null];
        $s['policies'] = ['shipping' => $data['policy_shipping'] ?? null, 'returns' => $data['policy_returns'] ?? null,
            'terms' => $data['policy_terms'] ?? null, 'privacy' => $data['policy_privacy'] ?? null];
        $s['size_guide'] = $data['size_guide'] ?? null;

        if (! $s['delivery']['enabled'] && ! $s['pickup']['enabled']) {
            return back()->withInput()->withErrors(['delivery_enabled' => 'Offer delivery, pickup, or both.']);
        }
        $tenant->forceFill(['settings' => array_merge($tenant->settings ?? [], ['store' => $s])])->save();

        $mask = fn ($a) => collect($a)->except(['stripe', 'whatsapp', 'policies'])->all();
        AuditLogger::log('store.settings_updated', $tenant, $mask($old), $mask($s));

        $warning = null;
        if ($s['enabled'] && ! $this->sites->isLive($tenant)) {
            $warning = 'Saved. The shop opens once your website is switched on (Settings › Website).';
        } elseif ($s['enabled'] && ! $this->settings->methods($tenant->fresh())) {
            $warning = 'Saved, but no way to pay is ready yet: add your Paystack or Stripe keys, bank details or a WhatsApp number.';
        }

        return back()->with($warning ? 'warning' : 'success', $warning ?? ($s['enabled'] ? 'Shop settings saved — your shop is open.' : 'Shop settings saved. Switch the shop on when you are ready.'));
    }
}
