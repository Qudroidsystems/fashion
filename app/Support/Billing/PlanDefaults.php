<?php

namespace App\Support\Billing;

use Illuminate\Support\Facades\DB;

/**
 * The four starting plans. Prices are placeholders: the platform super admin
 * changes names, prices, limits and features at /platform/plans.
 */
class PlanDefaults
{
    public const PLANS = [
        ['code' => 'free', 'name' => 'Free', 'tagline' => 'For a tailor starting out', 'price_monthly' => 0, 'price_yearly' => 0,
            'limits' => ['customers' => 20, 'staff' => 2, 'branches' => 1], 'features' => [], 'is_free' => true, 'sort_order' => 1],
        ['code' => 'basic', 'name' => 'Basic', 'tagline' => 'For a busy studio with a shop', 'price_monthly' => 5000, 'price_yearly' => 50000,
            'limits' => ['customers' => 150, 'staff' => 5, 'branches' => 1],
            'features' => ['measurement_links', 'fitting_reminders', 'inventory', 'pos', 'online_payments', 'mobile_app', 'website'], 'sort_order' => 2],
        ['code' => 'pro', 'name' => 'Pro', 'tagline' => 'For bridal and aso-ebi houses', 'price_monthly' => 15000, 'price_yearly' => 150000,
            'limits' => ['customers' => 1000, 'staff' => 15, 'branches' => 3],
            'features' => ['measurement_links', 'fitting_reminders', 'inventory', 'pos', 'online_payments', 'mobile_app', 'website',
                'projects', 'purchasing', 'custom_workflows', 'website_full', 'export', 'reports_full'], 'highlight' => true, 'sort_order' => 3],
        ['code' => 'business', 'name' => 'Business', 'tagline' => 'For brands with several branches', 'price_monthly' => 40000, 'price_yearly' => 400000,
            'limits' => ['customers' => null, 'staff' => null, 'branches' => null],
            'features' => ['measurement_links', 'fitting_reminders', 'inventory', 'pos', 'online_payments', 'mobile_app', 'website',
                'projects', 'purchasing', 'custom_workflows', 'website_full', 'export', 'reports_full', 'custom_domain', 'api_access', 'priority_support'], 'sort_order' => 4],
    ];

    /** Adds any missing default plan; never changes one that exists. */
    public static function install(): int
    {
        $added = 0;
        foreach (self::PLANS as $p) {
            if (DB::table('plans')->where('code', $p['code'])->exists()) {
                continue;
            }
            DB::table('plans')->insert([
                'code' => $p['code'], 'name' => $p['name'], 'tagline' => $p['tagline'], 'price_monthly' => $p['price_monthly'],
                'price_yearly' => $p['price_yearly'], 'limits' => json_encode($p['limits']), 'features' => json_encode($p['features']),
                'is_free' => $p['is_free'] ?? false, 'is_active' => true, 'is_public' => true, 'highlight' => $p['highlight'] ?? false,
                'sort_order' => $p['sort_order'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $added++;
        }

        return $added;
    }
}
