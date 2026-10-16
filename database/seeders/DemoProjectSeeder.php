<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Orders\BillingService;
use App\Services\Projects\ProjectService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * An optional sample wedding with bridesmaids, groomsmen and a self-paying
 * aso-ebi guest. Needs the demo customers and catalogue:
 *   php artisan db:seed --class=DemoCustomersSeeder
 *   php artisan db:seed --class=DemoCatalogueSeeder
 *   php artisan db:seed --class=DemoProjectSeeder
 */
class DemoProjectSeeder extends Seeder
{
    public function run(ProjectService $projects, BillingService $billing): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function () use ($projects, $billing, $tenant) {
            if (Project::exists()) {
                $this->command?->warn('Projects already exist — skipped.');

                return;
            }
            $owner = User::find($tenant->memberships()->where('is_owner', true)->value('user_id'));
            Auth::login($owner);
            $bride = Customer::where('phone', '09012223333')->first();   // Funmi Adeyemi (demo customers)
            $gown = FashionStyle::where('name', 'Mermaid lace gown')->first();
            $agbada = FashionStyle::where('name', 'Classic agbada (3-piece)')->first();
            $senator = FashionStyle::where('name', 'Senator kaftan')->first();
            if (! $bride || ! $gown || ! $agbada || ! $senator) {
                $this->command?->warn('Run DemoCustomersSeeder and DemoCatalogueSeeder first.');

                return;
            }

            $p = $projects->create([
                'name' => 'Funmi & Dayo’s wedding', 'type' => 'wedding', 'customer_id' => $bride->id, 'venue' => 'Eko Hotel, Victoria Island',
                'event_date' => now()->addDays(45)->toDateString(), 'delivery_date' => now()->addDays(40)->toDateString(),
                'contract_amount' => 1500000, 'deposit_amount' => 500000,
                'description' => 'Bride’s gown, five bridesmaids in champagne lace, groom and groomsmen in agbada.',
                'client_message' => 'We are honoured to dress your wedding party. Follow along here as everything comes together!',
            ], $owner);

            $projects->addParticipant($p, ['name' => 'Funmi Adeyemi', 'phone' => '09012223333', 'role' => 'Bride', 'group_name' => 'Couple', 'customer_id' => $bride->id]);
            $projects->addParticipant($p, ['name' => 'Dayo Adeyemi', 'phone' => '08055501000', 'role' => 'Groom', 'group_name' => 'Couple']);
            $projects->addParticipantsFromText($p, "Bisi Alabi, 08055501001, Chief bridesmaid\nTemi Cole, 08055501002\nKemi Ade, 08055501003\nNkechi Obi, 08055501004", 'Bridesmaids', 'client');
            $projects->addParticipantsFromText($p, "Tobi Lawal, 08055502001, Best man\nSegun Ojo, 08055502002", 'Groomsmen', 'client');
            $projects->addParticipant($p, ['name' => 'Uncle Femi', 'phone' => '08055503001', 'role' => 'Guest', 'group_name' => 'Aso-ebi', 'pays' => 'self']);

            $people = $p->participants()->get()->keyBy('name');
            $projects->addGarments($p, collect([$people['Funmi Adeyemi']]), ['kind' => 'bespoke', 'style_id' => $gown->id, 'options' => ['Hand beading', 'Detachable train']], $owner);
            $projects->addGarments($p, $people->where('group_name', 'Bridesmaids')->values(), ['kind' => 'bespoke', 'style_id' => $gown->id, 'unit_price' => 250000], $owner);
            $projects->addGarments($p, collect([$people['Dayo Adeyemi']]), ['kind' => 'bespoke', 'style_id' => $agbada->id, 'options' => ['Hand embroidery', 'Matching fila']], $owner);
            $projects->addGarments($p, $people->where('group_name', 'Groomsmen')->values(), ['kind' => 'bespoke', 'style_id' => $senator->id], $owner);
            $projects->addGarments($p, collect([$people['Uncle Femi']]), ['kind' => 'bespoke', 'style_id' => $senator->id], $owner);
            $projects->confirm($p->fresh(), $owner);

            $billing->recordPayment(['customer_id' => $bride->id, 'amount' => 500000, 'method' => 'bank_transfer', 'reference' => 'GTB-' . random_int(100000, 999999), 'order_id' => $p->mainOrder()->id]);
            $guestOrder = $p->orders()->where('customer_id', '!=', $bride->id)->first();
            $billing->recordPayment(['customer_id' => $guestOrder->customer_id, 'amount' => 30000, 'method' => 'cash', 'order_id' => $guestOrder->id]);

            $projects->addSchedule($p, ['label' => 'Deposit', 'due_date' => now()->toDateString(), 'amount' => 500000]);
            $projects->addSchedule($p, ['label' => 'Second payment', 'due_date' => now()->addDays(20)->toDateString(), 'amount' => 500000]);
            $projects->addSchedule($p, ['label' => 'Balance', 'due_date' => now()->addDays(38)->toDateString(), 'amount' => 500000]);
            $projects->addBudgetLine($p, ['category' => 'fabric', 'description' => 'Lace and aso-oke', 'amount' => 450000]);
            $projects->addBudgetLine($p, ['category' => 'embellishment', 'description' => 'Beading', 'amount' => 120000]);
            $projects->addExpense($p, ['category' => 'embellishment', 'description' => 'Swarovski stones', 'amount' => 85000, 'spent_on' => now()->toDateString(), 'paid_to' => 'Balogun market'], null, $owner);

            $ms = $p->milestones()->get();
            $projects->completeMilestone($ms[0], $owner);
            $projects->publishMilestone($ms[0], $owner);
            $projects->publishMilestone($ms[1], $owner);
            $projects->publishUpdate($projects->addUpdate($p, ['title' => 'Your lace is here', 'body' => 'The champagne French lace for the bride and bridesmaids arrived today. It is beautiful!', 'customer_visible' => true], [], $owner), $owner);
            $projects->addUpdate($p, ['title' => 'Supplier note', 'body' => 'Second batch of stones delayed a week — staff only.', 'customer_visible' => false], [], $owner);
            $projects->enableTracking($p);

            $this->command?->info("Demo wedding added to {$tenant->name}");
        });
    }
}
