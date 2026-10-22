<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CatalogueCategory;
use App\Models\Coupon;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\StoreBanner;
use App\Models\Tenant;
use App\Services\Catalogue\ProductService;
use App\Services\Inventory\StockLedger;
use App\Services\Store\StoreSettings;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Optional sample online shop for the default house: switches the website and shop on,
 * adds ready-made products with sizes, colours, stock and pictures (taken from the shop
 * template's sample images when they are there), delivery zones, a coupon and a banner.
 *   php artisan db:seed --class=DemoStoreSeeder
 */
class DemoStoreSeeder extends Seeder
{
    public function run(ProductService $products, StockLedger $ledger): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function (Tenant $tenant) use ($products, $ledger) {
            Auth::onceUsingId($tenant->memberships()->where('is_owner', true)->value('user_id'));
            $settings = $tenant->settings ?? [];
            $settings['website'] = array_merge(['enabled' => true, 'show_prices' => true, 'tagline' => 'Ready-to-wear and made-to-measure, from our studio to your door.',
                'whatsapp' => $tenant->phone ?: '08030000000'], (array) ($settings['website'] ?? []), ['enabled' => true]);
            $settings['store'] = array_replace_recursive(StoreSettings::DEFAULTS, (array) ($settings['store'] ?? []), [
                'enabled' => true, 'announcement' => 'Free delivery in Lagos on orders over ₦100,000',
                'methods' => ['paystack' => true, 'stripe' => false, 'bank_transfer' => true, 'whatsapp' => true, 'pay_on_delivery' => true],
                'bank' => ['bank_name' => 'GTBank', 'account_name' => $tenant->name, 'account_number' => '0123456789', 'instructions' => 'Use your order number as the narration.'],
                'policies' => ['shipping' => "Lagos: 1–2 working days.\nRest of Nigeria: 3–5 working days.", 'returns' => 'Unworn items can be exchanged within 7 days with the receipt.'],
            ]);
            $tenant->forceFill(['settings' => $settings])->save();

            if (! ShippingZone::exists()) {
                ShippingZone::create(['name' => 'Lagos', 'states' => ['Lagos'], 'fee' => 3000, 'free_over' => 100000, 'eta' => '1–2 working days', 'sort_order' => 1]);
                ShippingZone::create(['name' => 'Rest of Nigeria', 'states' => null, 'fee' => 6500, 'eta' => '3–5 working days', 'sort_order' => 2]);
            }
            Coupon::firstOrCreate(['code' => 'WELCOME10'], ['description' => '10% off your first order', 'type' => 'percent', 'value' => 10, 'usage_per_customer' => 1, 'is_active' => true]);

            $cat = fn (string $name) => CatalogueCategory::firstOrCreate(['name' => $name])->id;
            $branch = Branch::where('is_main', true)->value('id');
            $sample = public_path(config('store.assets', 'ecommerce_website') . '/images/products/womens');
            $pics = is_dir($sample) ? collect(glob($sample . '/women-*.jpg'))->sort()->values() : collect();
            $n = 0;

            $catalogue = [
                ['Ankara wrap dress', 'Dresses', 'Hand-cut ankara wrap dress with a tie waist. Fully lined.', ['S', 'M', 'L', 'XL'], ['Blue' => '#1d4ed8', 'Wine' => '#722f37'], 28500, 32000, 'New'],
                ['Adire maxi skirt', 'Skirts', 'Flowing adire maxi skirt, hand-dyed in Abeokuta.', ['S', 'M', 'L'], ['Indigo' => '#283a6b'], 19500, null, null],
                ['Lace peplum top', 'Shirts & tops', 'Cord lace peplum top, perfect for owambe.', ['S', 'M', 'L', 'XL'], ['Gold' => '#c9a227', 'Emerald' => '#047857'], 24000, null, 'Hot'],
                ['Kaftan — senator', 'Menswear', 'Classic senator kaftan in premium cashmere blend.', ['M', 'L', 'XL', 'XXL'], ['Black' => '#181818', 'Cream' => '#f5efdf'], 45000, 52000, null],
                ['Aso-oke gele', 'Accessories', 'Pre-tied aso-oke gele — ready to wear.', [], ['Peach' => '#f6b89a', 'Silver' => '#c0c0c0'], 12000, null, null],
                ['Ankara bomber jacket', 'Menswear', 'Unisex ankara bomber with ribbed cuffs.', ['M', 'L', 'XL'], ['Orange' => '#f77f00'], 35000, null, 'Limited'],
            ];
            foreach ($catalogue as [$name, $category, $desc, $sizes, $colours, $price, $was, $badge]) {
                if (Product::where('name', $name)->exists()) {
                    continue;
                }
                $rows = [];
                foreach ($colours as $colour => $hex) {
                    foreach ($sizes ?: [null] as $size) {
                        $rows[] = ['size' => $size, 'colour' => $colour, 'price' => $price, 'cost_price' => round($price * .45), 'is_active' => true];
                    }
                }
                $p = $products->save(null, ['name' => $name, 'category_id' => $cat($category), 'description' => $desc, 'show_in_catalogue' => true, 'track_stock' => true], $rows);
                $p->update(['badge' => $badge, 'is_featured' => $n < 4, 'details' => 'Made in Lagos. Dry clean or hand wash cold.']);
                foreach ($p->variants as $v) {
                    $v->update(['colour_hex' => $colours[$v->colour] ?? null, 'compare_at_price' => $was]);
                    if ($item = InventoryItem::where('product_variant_id', $v->id)->first()) {
                        $ledger->post($item, $branch, random_int(2, 8), 'opening', ['unit_cost' => round($price * .45), 'reason' => 'Demo stock']);
                    }
                }
                foreach ($pics->slice(($n * 2) % max(1, $pics->count()), 2) as $i => $file) {
                    $path = 'tenants/' . $tenant->public_id . '/products/' . $p->public_id . '/' . Str::lower(Str::random(8)) . '.jpg';
                    Storage::disk('public')->put($path, file_get_contents($file));
                    $p->images()->create(['path' => $path, 'alt' => $name, 'sort_order' => $i]);
                }
                if ($first = $p->images()->first()) {
                    $p->update(['image_path' => $first->path]);
                }
                $n++;
            }

            $slide = public_path(config('store.assets', 'ecommerce_website') . '/images/slider/slider-women1.jpg');
            if (! StoreBanner::exists() && is_file($slide)) {
                $path = 'tenants/' . $tenant->public_id . '/banners/demo-hero.jpg';
                Storage::disk('public')->put($path, file_get_contents($slide));
                StoreBanner::create(['placement' => 'hero', 'eyebrow' => 'NEW SEASON', 'title' => 'Made for every occasion', 'subtitle' => 'Ready-to-wear pieces, cut and sewn in our studio.',
                    'button_text' => 'Shop now', 'link' => 'shop', 'image_path' => $path, 'text_colour' => 'light']);
            }
        });

        $this->command?->info('Demo shop ready for ' . $tenant->name . ' — open ' . app(\App\Services\Sites\SiteService::class)->url($tenant->fresh()) . '/shop');
    }
}
