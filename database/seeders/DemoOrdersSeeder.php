<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\InventoryItem;
use App\Models\MeasurementSession;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\BillingService;
use App\Services\Orders\OrderService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Optional sample quotes, orders, invoices and payments. Needs the demo customers
 * and catalogue (and, for materials, the demo stock):
 *   php artisan db:seed --class=DemoCustomersSeeder
 *   php artisan db:seed --class=DemoCatalogueSeeder
 *   php artisan db:seed --class=DemoInventorySeeder   (optional)
 *   php artisan db:seed --class=DemoOrdersSeeder
 */
class DemoOrdersSeeder extends Seeder
{
    public function run(OrderService $orders, BillingService $billing): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function () use ($orders, $billing, $tenant) {
            if (Order::exists()) {
                $this->command?->warn('Orders already exist — skipped.');

                return;
            }
            $styles = FashionStyle::whereNotNull('current_version_id')->pluck('id', 'name');
            $customers = Customer::active()->orderBy('id')->get();
            if ($styles->isEmpty() || $customers->count() < 4) {
                $this->command?->error('Run DemoCustomersSeeder and DemoCatalogueSeeder first.');

                return;
            }
            Auth::login(User::find($tenant->memberships()->where('is_owner', true)->value('user_id')));
            $owner = Auth::user();
            $s = fn (string $name) => $styles[$name] ?? $styles->first();
            [$c1, $c2, $c3, $c4] = $customers->take(4)->all();
            $c5 = $customers->get(4) ?? $c1;
            $cash = fn ($c, $amt, $order, $method = 'cash', $ref = null) => $billing->recordPayment(['customer_id' => $c->id, 'amount' => $amt, 'method' => $method, 'reference' => $ref, 'order_id' => $order?->id]);

            // 1. A sent quotation for a wedding party.
            $q = $orders->saveQuotation(null, ['customer_id' => $c2->id, 'title' => 'Wedding — bride’s family aso-ebi', 'event_date' => now()->addDays(45)->toDateString()], [
                ['kind' => 'bespoke', 'style_id' => $s('Mermaid lace gown'), 'quantity' => 1, 'options' => ['Hand beading']],
                isset($styles['Ankara peplum top'])
                    ? ['kind' => 'bespoke', 'style_id' => $styles['Ankara peplum top'], 'quantity' => 6, 'description' => 'Bridesmaids’ peplum tops']
                    : ['kind' => 'service', 'description' => 'Bridesmaids’ Ankara peplum tops', 'quantity' => 6, 'unit_price' => 25000],
                ['kind' => 'service', 'description' => 'Gele tying on the day', 'quantity' => 1, 'unit_price' => 15000],
            ]);
            $orders->sendQuotation($q);

            // 2. Bride's gown: accepted quote → deposit invoice → part payment → in production with materials.
            $q2 = $orders->saveQuotation(null, ['customer_id' => $c1->id, 'title' => 'Wedding reception gown', 'event_date' => now()->addDays(28)->toDateString()], [
                ['kind' => 'bespoke', 'style_id' => $s('Mermaid lace gown'), 'quantity' => 1, 'options' => ['Hand beading'], 'instructions' => 'Sweetheart neckline, detachable sleeves'],
            ]);
            $o2 = $orders->acceptQuotation($q2, ['promised_date' => now()->addDays(24)->toDateString()]);
            $inv = $billing->issue($billing->invoiceOrder($o2, 'deposit'));
            $billing->recordPayment(['customer_id' => $c1->id, 'amount' => $inv->total, 'method' => 'bank_transfer', 'reference' => 'GTB/TRF/88123', 'invoice_id' => $inv->id]);
            if ($session = MeasurementSession::where('customer_id', $c1->id)->where('status', 'approved')->first()) {
                $o2->lines()->first()->update(['measurement_session_id' => $session->id]);
            }
            $lace = InventoryItem::where('name', 'French lace')->first();
            if ($lace) {
                $branch = Branch::where('is_main', true)->first();
                try {
                    $orders->addMaterial($o2, $lace, 3, $branch->id, null, 'Bodice and skirt', true);
                    if ($lining = InventoryItem::where('name', 'Satin lining')->first()) {
                        $orders->addMaterial($o2, $lining, 4, $branch->id, null, null, true);
                    }
                } catch (\Throwable) {
                }
            }
            $orders->transition($o2, 'in_production', 'Pattern drafted, cutting started');

            // 3. Agbada set at fitting stage, balance owing.
            $o3 = $orders->saveOrder(null, ['customer_id' => $c3->id, 'title' => '60th birthday agbada', 'promised_date' => now()->addDays(6)->toDateString(), 'priority' => 'rush'], [
                ['kind' => 'bespoke', 'style_id' => $s('Classic agbada (3-piece)'), 'quantity' => 1, 'options' => ['Hand embroidery', 'Matching fila']],
            ]);
            $orders->transition($o3, 'confirmed');
            $cash($c3, 120000, $o3);
            $orders->transition($o3, 'in_production');
            $orders->transition($o3, 'fitting', 'First fitting Saturday 11am', true);

            // 4. Late: promised last week, still in production.
            $o4 = $orders->saveOrder(null, ['customer_id' => $c4->id, 'title' => 'Office senators ×2', 'promised_date' => now()->subDays(4)->toDateString()], [
                ['kind' => 'bespoke', 'style_id' => $s('Senator kaftan'), 'quantity' => 2],
            ]);
            $orders->transition($o4, 'confirmed');
            $cash($c4, 65000, $o4, 'card', 'POS-41552');
            $orders->transition($o4, 'in_production');

            // 5. Ready-made + alteration, delivered and fully paid.
            $variant = ProductVariant::orderBy('id')->first();
            $lines = [['kind' => 'service', 'description' => 'Hem and take in sides', 'quantity' => 1, 'unit_price' => 7000]];
            if ($variant) {
                array_unshift($lines, ['kind' => 'ready_made', 'product_variant_id' => $variant->id, 'quantity' => 2]);
            }
            $o5 = $orders->saveOrder(null, ['customer_id' => $c5->id, 'title' => 'Shirts for the trip', 'promised_date' => now()->subDays(1)->toDateString()], $lines);
            $orders->transition($o5, 'confirmed');
            $cash($c5, $o5->total, $o5, 'mobile_money', 'OPAY-77120');
            foreach (['in_production', 'ready', 'delivered'] as $st) {
                $orders->transition($o5, $st);
            }

            // 6. Cancelled after a deposit: the deposit is the customer's credit.
            $o6 = $orders->saveOrder(null, ['customer_id' => $c4->id, 'title' => 'Palazzo set', 'promised_date' => now()->addDays(10)->toDateString()], [
                ['kind' => 'bespoke', 'style_id' => $s('High-waist palazzo'), 'quantity' => 1],
            ]);
            $orders->transition($o6, 'confirmed');
            $p6 = $cash($c4, 11000, $o6);
            $orders->cancel($o6, 'Customer chose a different style');
            $billing->requestRefund($p6, ['amount' => 11000, 'reason' => 'Order cancelled', 'order_id' => $o6->id]);

            Auth::logout();
            $this->command?->info('Demo orders added to ' . $tenant->name);
        });
    }
}
