<?php

namespace Database\Seeders;

use App\Models\CatalogueCategory;
use App\Models\FashionStyle;
use App\Models\MeasurementTemplate;
use App\Models\Tenant;
use App\Services\Catalogue\ProductService;
use App\Services\Catalogue\StyleService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Optional sample styles and ready-made products (no pictures — add your own):
 *   php artisan db:seed --class=DemoCatalogueSeeder
 */
class DemoCatalogueSeeder extends Seeder
{
    public function run(StyleService $styles, ProductService $products): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function () use ($styles, $products, $tenant) {
            if (FashionStyle::exists()) {
                $this->command?->warn('Styles already exist — skipped.');

                return;
            }
            Auth::onceUsingId($tenant->memberships()->where('is_owner', true)->value('user_id'));
            $cat = CatalogueCategory::pluck('id', 'name');
            $tpl = MeasurementTemplate::pluck('id', 'code');

            $designs = [
                ['Mermaid lace gown', 'Gowns & dresses', 'female', 250000, 140000, 21, 5, 'Fitted to the knee with a flared lace hem.', 'Corset boning, invisible back zip, scalloped lace hem.', [['Hand beading', 60000], ['Detachable train', 45000]], ['womens_gown'], true],
                ['Classic agbada (3-piece)', 'Native (agbada / senator)', 'male', 180000, 95000, 14, 10, 'Flowing agbada with matching buba and sokoto.', 'Hand embroidery on the chest panel; double-stitched hems.', [['Hand embroidery', 35000], ['Matching fila', 8000]], ['native_full'], true],
                ['Senator kaftan', 'Native (agbada / senator)', 'male', 65000, 30000, 7, 4, 'Clean-cut senator top with straight trousers.', 'Mandarin collar, concealed placket, side slits.', [['Embroidered collar', 10000]], ['top_shirt', 'trouser'], true],
                ['Ankara peplum top', 'Shirts & tops', 'female', 25000, 11000, 5, 3, 'Structured peplum with puff sleeves.', 'Interfaced waist seam; puff sleeves gathered at the cap.', [], ['top_shirt'], false],
                ['High-waist palazzo', 'Trousers', 'female', 22000, 9000, 5, 3, 'Wide-leg palazzo with a high waistband.', 'Side zip, 6 cm waistband.', [], ['trouser'], true],
            ];

            foreach ($designs as [$name, $category, $aud, $price, $cost, $lead, $fabric, $desc, $tech, $opts, $tpls, $publish]) {
                $style = $styles->create(
                    ['name' => $name, 'category_id' => $cat[$category] ?? null, 'audience' => $aud, 'show_in_catalogue' => true],
                    ['description' => $desc, 'technical_notes' => $tech, 'base_price' => $price, 'estimated_cost' => $cost, 'lead_time_days' => $lead,
                     'fabric_quantity' => $fabric, 'fabric_unit' => 'yd', 'price_options' => array_map(fn ($o) => ['label' => $o[0], 'amount' => $o[1]], $opts)],
                    array_map(fn ($c) => $tpl[$c], $tpls),
                );
                if ($publish) {
                    $styles->publish($style->draft()->first(), 'First release');
                }
            }

            $products->save(null, ['name' => 'Ankara shirt — short sleeve', 'category_id' => $cat['Shirts & tops'] ?? null, 'show_in_catalogue' => true, 'track_stock' => true],
                collect(['S', 'M', 'L', 'XL'])->map(fn ($s) => ['size' => $s, 'colour' => 'Blue print', 'price' => 15000, 'cost_price' => 7500, 'reorder_level' => 3, 'generate_barcode' => true])->all());
            $products->save(null, ['name' => 'Embroidered fila cap', 'category_id' => $cat['Accessories'] ?? null, 'show_in_catalogue' => true, 'track_stock' => true],
                [['size' => '22"', 'colour' => 'Navy', 'price' => 8000, 'cost_price' => 3000, 'generate_barcode' => true],
                 ['size' => '23"', 'colour' => 'Wine', 'price' => 8000, 'cost_price' => 3000, 'generate_barcode' => true]]);
        });

        $this->command?->info('Demo styles and products added to ' . $tenant->name);
    }
}
