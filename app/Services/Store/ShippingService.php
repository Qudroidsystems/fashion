<?php

namespace App\Services\Store;

use App\Models\ShippingZone;
use App\Models\Tenant;

/** Delivery fee by state (shipping zones) and pickup branches. */
class ShippingService
{
    public function __construct(protected StoreSettings $settings)
    {
    }

    /**
     * Delivery quote for a state and subtotal: [zone_id, label, fee, eta], or null when the house
     * does not deliver there. With no zones set up, the house's default fee applies (or the fee is
     * agreed after the order).
     */
    public function delivery(Tenant $tenant, ?string $state, float $subtotal): ?array
    {
        if (! StoreSettings::get($tenant, 'delivery.enabled')) {
            return null;
        }
        $zones = ShippingZone::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        if ($zones->isNotEmpty()) {
            $zone = $zones->first(fn ($z) => ! empty($z->states) && $z->covers($state)) ?? $zones->first(fn ($z) => empty($z->states));
            if (! $zone) {
                return null;
            }

            return ['zone_id' => $zone->id, 'label' => $zone->name, 'fee' => $zone->feeFor($subtotal), 'eta' => $zone->eta,
                'free_over' => $zone->free_over];
        }
        $fee = StoreSettings::get($tenant, 'delivery.default_fee');

        return ['zone_id' => null, 'label' => 'Delivery', 'fee' => $fee !== null && $fee !== '' ? round((float) $fee, 2) : 0.0,
            'eta' => StoreSettings::get($tenant, 'delivery.default_eta'), 'free_over' => null, 'to_agree' => $fee === null || $fee === ''];
    }

    /** The lowest "free delivery from" threshold, for the cart's progress bar. */
    public function freeThreshold(): ?float
    {
        $v = ShippingZone::where('is_active', true)->whereNotNull('free_over')->where('free_over', '>', 0)->min('free_over');

        return $v ? (float) $v : null;
    }
}
