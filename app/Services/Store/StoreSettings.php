<?php

namespace App\Services\Store;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Billing\Entitlements;
use App\Services\Payments\PaystackGateway;
use App\Services\Sites\SiteService;
use Illuminate\Support\Facades\Crypt;

/**
 * A house's online-store settings (tenant settings key "store"), with defaults,
 * and what is actually usable right now (a payment method needs its keys/details).
 */
class StoreSettings
{
    public const DEFAULTS = [
        'enabled'              => false,
        'fulfilment_branch_id' => null,          // stock is sold from this branch (default: main branch)
        'hide_out_of_stock'    => false,
        'allow_backorder'      => false,         // keep selling at zero stock (made to order)
        'guest_checkout'       => true,
        'low_stock_badge'      => 3,             // "Only 2 left" at or below this
        'card_hold_hours'      => null,          // null = config('store.card_hold_hours')
        'manual_hold_days'     => null,          // null = config('store.manual_hold_days')
        'announcement'         => null,          // thin bar above the header
        'notify_emails'        => null,          // extra addresses told about each new order (comma separated)
        'methods'              => ['paystack' => true, 'stripe' => false, 'bank_transfer' => true, 'whatsapp' => true, 'pay_on_delivery' => false],
        'stripe'               => ['publishable_key' => null, 'secret_key' => null, 'webhook_secret' => null],
        'bank'                 => ['bank_name' => null, 'account_name' => null, 'account_number' => null, 'instructions' => null],
        'whatsapp'             => [
            'number'         => null,            // the reps' WhatsApp (default: the website WhatsApp number)
            'button_label'   => 'Order on WhatsApp',
            'cloud_enabled'  => false,           // also push each new order to the reps through the WhatsApp Cloud API
            'cloud_token'    => null,
            'cloud_phone_id' => null,
            'notify_numbers' => null,            // comma separated
            'template'       => null,            // approved template name (needed outside the 24-hour window)
            'template_lang'  => 'en',
        ],
        'delivery'             => ['enabled' => true, 'default_fee' => null, 'default_eta' => null],
        'pickup'               => ['enabled' => true, 'branch_ids' => [], 'note' => 'Usually ready within 24 hours.'],
        'policies'             => ['shipping' => null, 'returns' => null, 'terms' => null, 'privacy' => null],
        'size_guide'           => null,
    ];

    public function __construct(protected Entitlements $entitlements, protected SiteService $sites)
    {
    }

    public static function get(Tenant $tenant, ?string $key = null, mixed $default = null): mixed
    {
        $all = array_replace_recursive(self::DEFAULTS, (array) $tenant->setting('store', []));

        return $key === null ? $all : data_get($all, $key, $default);
    }

    /** The shop is open: website live, plan includes it, switched on. */
    public function isOpen(Tenant $tenant): bool
    {
        return (bool) self::get($tenant, 'enabled') && $this->entitlements->has($tenant, 'online_store') && $this->sites->isLive($tenant);
    }

    public function fulfilmentBranchId(Tenant $tenant): ?int
    {
        $id = (int) self::get($tenant, 'fulfilment_branch_id');
        if ($id && Branch::whereKey($id)->where('is_active', true)->exists()) {
            return $id;
        }

        return Branch::where('is_main', true)->value('id') ?? Branch::orderBy('id')->value('id');
    }

    public function pickupBranches(Tenant $tenant)
    {
        if (! self::get($tenant, 'pickup.enabled')) {
            return collect();
        }
        $ids = array_filter(array_map('intval', (array) self::get($tenant, 'pickup.branch_ids', [])));

        return Branch::active()->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderByDesc('is_main')->orderBy('name')->get();
    }

    /** Payment methods the customer can choose now: method => label. */
    public function methods(Tenant $tenant): array
    {
        $on = (array) self::get($tenant, 'methods', []);
        $out = [];
        if (! empty($on['paystack']) && PaystackGateway::for($tenant)->enabled()) {
            $out['paystack'] = 'Pay now with card, bank or USSD (Paystack)';
        }
        if (! empty($on['stripe']) && StripeGateway::for($tenant)->enabled()) {
            $out['stripe'] = 'Pay now with card (Stripe)';
        }
        if (! empty($on['bank_transfer']) && self::get($tenant, 'bank.account_number')) {
            $out['bank_transfer'] = 'Bank transfer';
        }
        if (! empty($on['whatsapp']) && WhatsApp::houseNumber($tenant)) {
            $out['whatsapp'] = 'Order on WhatsApp — agree payment with us';
        }
        if (! empty($on['pay_on_delivery'])) {
            $out['pay_on_delivery'] = 'Pay on delivery / at pickup';
        }

        return $out;
    }

    public function cardHoldHours(Tenant $tenant): int
    {
        return max(1, (int) (self::get($tenant, 'card_hold_hours') ?: config('store.card_hold_hours', 2)));
    }

    public function manualHoldDays(Tenant $tenant): int
    {
        return max(1, (int) (self::get($tenant, 'manual_hold_days') ?: config('store.manual_hold_days', 3)));
    }

    public static function secret(Tenant $tenant, string $key): ?string
    {
        $enc = self::get($tenant, $key);
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Where the shop's pages live (site address + path). */
    public function url(Tenant $tenant, string $path = ''): string
    {
        return rtrim($this->sites->url($tenant), '/') . '/' . ltrim($path, '/');
    }
}
