<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\PurchasingService;
use App\Services\Inventory\StockDocumentService;
use App\Services\Inventory\StockLedger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Optional sample stock: suppliers, fabrics and trims, a received and a pending
 * purchase order, a transfer, a reservation, an issue with a remnant and a count.
 *   php artisan db:seed --class=DemoInventorySeeder
 */
class DemoInventorySeeder extends Seeder
{
    public function run(ItemService $items, PurchasingService $purchasing, StockDocumentService $docs, StockLedger $ledger): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function () use ($items, $purchasing, $docs, $ledger, $tenant) {
            if (InventoryItem::where('type', '!=', 'product')->exists()) {
                $this->command?->warn('Stock items already exist — skipped.');

                return;
            }
            $owner = User::find($tenant->memberships()->where('is_owner', true)->value('user_id'));
            Auth::login($owner);

            $shop = Branch::where('is_main', true)->first();
            $store = Branch::where('type', 'warehouse')->first()
                ?? Branch::create(['name' => 'Lekki warehouse', 'code' => 'WH1', 'type' => 'warehouse', 'is_active' => true]);

            $sup = collect([
                ['Balogun Lace House', 'Mama Titi', '08033334444', 7],
                ['Kano Textiles Ltd', 'Alhaji Musa', '08055556666', 30],
                ['Idumota Trims & Notions', 'Chidi', '08077778888', 0],
            ])->mapWithKeys(fn ($s) => [$s[0] => $purchasing->saveSupplier(null, ['name' => $s[0], 'contact_person' => $s[1], 'phone' => $s[2], 'payment_terms_days' => $s[3]])]);

            $def = [
                // name, type, category, unit, colour, width, reorder, lots, supplier, cost
                ['French lace', 'fabric', 'Lace', 'yd', 'Champagne', '52"', 15, true, 'Balogun Lace House', 9500],
                ['Cord lace', 'fabric', 'Lace', 'yd', 'Wine', '50"', 10, true, 'Balogun Lace House', 7000],
                ['Ankara print', 'fabric', 'Ankara', 'yd', 'Blue / gold', '46"', 30, false, 'Kano Textiles Ltd', 2500],
                ['Aso-oke', 'fabric', 'Aso-oke', 'pcs', 'Gold', null, 4, false, 'Kano Textiles Ltd', 18000],
                ['Satin lining', 'fabric', 'Lining', 'yd', 'Ivory', '58"', 20, false, 'Kano Textiles Ltd', 1200],
                ['Invisible zip 22"', 'accessory', 'Zips', 'pcs', 'Assorted', null, 30, false, 'Idumota Trims & Notions', 250],
                ['Gold shank buttons', 'accessory', 'Buttons', 'pcs', 'Gold', null, 100, false, 'Idumota Trims & Notions', 60],
                ['Polyester thread', 'consumable', 'Thread', 'cone', 'Black', null, 10, false, 'Idumota Trims & Notions', 900],
                ['Fusible interfacing', 'consumable', 'Interfacing', 'yd', 'White', '36"', 10, false, 'Idumota Trims & Notions', 700],
                ['Crystal stones', 'accessory', 'Embellishment', 'pack', 'Clear', null, 5, false, 'Idumota Trims & Notions', 3500],
            ];
            $it = [];
            foreach ($def as [$name, $type, $cat, $unit, $colour, $width, $reorder, $lots, $supplier, $cost]) {
                $it[$name] = $items->create([
                    'name' => $name, 'type' => $type, 'category' => $cat, 'unit' => $unit, 'colour' => $colour, 'width' => $width,
                    'reorder_level' => $reorder, 'reorder_quantity' => $reorder * 2, 'track_lots' => $lots,
                    'preferred_supplier_id' => $sup[$supplier]->id, 'opening_cost' => $cost,
                ]);
            }

            // Opening stock at the shop.
            $opening = $docs->saveAdjustment(null, ['kind' => 'opening', 'branch_id' => $shop->id, 'reason' => 'Stock at go-live'], [
                ['item_id' => $it['Ankara print']->id, 'quantity' => 64, 'unit_cost' => 2500],
                ['item_id' => $it['Satin lining']->id, 'quantity' => 45.5, 'unit_cost' => 1200],
                ['item_id' => $it['Invisible zip 22"']->id, 'quantity' => 80, 'unit_cost' => 250],
                ['item_id' => $it['Gold shank buttons']->id, 'quantity' => 340, 'unit_cost' => 60],
                ['item_id' => $it['Polyester thread']->id, 'quantity' => 7, 'unit_cost' => 900],
                ['item_id' => $it['Fusible interfacing']->id, 'quantity' => 18, 'unit_cost' => 700],
                ['item_id' => $it['Aso-oke']->id, 'quantity' => 6, 'unit_cost' => 18000],
            ]);
            $docs->submitAdjustment($opening, $owner);

            // Lace bought on a PO into the warehouse, received in two rolls.
            $po = $purchasing->savePurchaseOrder(null, ['supplier_id' => $sup['Balogun Lace House']->id, 'branch_id' => $store->id, 'expected_date' => now()->addDays(3)->toDateString()], [
                ['item_id' => $it['French lace']->id, 'quantity' => 40, 'unit_cost' => 9500],
                ['item_id' => $it['Cord lace']->id, 'quantity' => 25, 'unit_cost' => 7000],
            ]);
            $po = $purchasing->submit($po, $owner);   // the owner is the only approver here, so it is approved
            $lines = $po->lines()->get();
            $purchasing->receive(['supplier_reference' => 'DN-2231'], [
                ['purchase_order_line_id' => $lines[0]->id, 'quantity' => 25, 'lot_no' => 'FL-0921'],
                ['purchase_order_line_id' => $lines[1]->id, 'quantity' => 25, 'lot_no' => 'CL-0388'],
            ], $po->fresh());

            // A pending order for trims.
            $po2 = $purchasing->savePurchaseOrder(null, ['supplier_id' => $sup['Idumota Trims & Notions']->id, 'branch_id' => $shop->id], [
                ['item_id' => $it['Crystal stones']->id, 'quantity' => 12, 'unit_cost' => 3500],
                ['item_id' => $it['Polyester thread']->id, 'quantity' => 20, 'unit_cost' => 900],
            ]);

            // Move some lace to the shop, reserve it for a bride, issue it, return a remnant.
            $docs->transfer($store->id, $shop->id, [['item_id' => $it['French lace']->id, 'lot_id' => \App\Models\StockLot::where('lot_no', 'FL-0921')->value('id'), 'quantity' => 12]]);
            $bride = Customer::orderBy('id')->first();
            if ($bride) {
                $ledger->reserve($it['Ankara print'], $shop->id, 6, ['customer_id' => $bride->id, 'reference' => $bride->first_name . '’s aso-ebi set']);
                $lot = \App\Models\StockLot::where('lot_no', 'FL-0921')->first();
                $issue = $docs->issue(['branch_id' => $shop->id, 'customer_id' => $bride->id, 'job_reference' => $bride->first_name . '’s wedding gown — bodice & skirt'], [
                    ['item_id' => $it['French lace']->id, 'lot_id' => $lot->id, 'quantity' => 7.5],
                    ['item_id' => $it['Satin lining']->id, 'quantity' => 6],
                    ['item_id' => $it['Invisible zip 22"']->id, 'quantity' => 1],
                ]);
                $docs->returnFromProduction($issue, [$issue->lines->first()->id => 0.75], true, 'Two offcuts');

                $mat = $items->create(['name' => $bride->first_name . '’s aso-oke', 'type' => 'customer_material', 'unit' => 'pcs', 'customer_id' => $bride->id, 'colour' => 'Coral']);
                $cm = $docs->saveAdjustment(null, ['kind' => 'customer_material', 'branch_id' => $shop->id, 'customer_id' => $bride->id, 'reason' => 'Brought for her gele and ipele'],
                    [['item_id' => $mat->id, 'quantity' => 3]]);
                $docs->submitAdjustment($cm, $owner);
            }

            // A month-end count with small differences, and a big wastage waiting for approval.
            $count = $docs->saveAdjustment(null, ['kind' => 'count', 'branch_id' => $shop->id, 'reason' => 'Month-end count'], [
                ['item_id' => $it['Gold shank buttons']->id, 'counted_quantity' => 332],
                ['item_id' => $it['Polyester thread']->id, 'counted_quantity' => 7],
            ]);
            $docs->submitAdjustment($count, $owner);
            $docs->saveAdjustment(null, ['kind' => 'wastage', 'branch_id' => $shop->id, 'reason' => 'Roof leak in store room'],
                [['item_id' => $it['Ankara print']->id, 'quantity' => 12, 'note' => 'Water-stained']]);

            Auth::logout();
            $this->command?->info('Demo stock added to ' . $tenant->name);
        });
    }
}
