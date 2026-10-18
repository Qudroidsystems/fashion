<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Numbering\DocumentNumber;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * Optional sample running costs for the last three months, so the profit and
 * expense reports have something to show:
 *   php artisan db:seed --class=DemoExpensesSeeder
 */
class DemoExpensesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function () use ($tenant) {
            if (Expense::exists()) {
                $this->command?->warn('Expenses already exist — skipped.');

                return;
            }
            $owner = $tenant->memberships()->where('is_owner', true)->value('user_id') ?? User::value('id');
            $branch = Branch::orderBy('id')->value('id');
            $tz = $tenant->timezone ?: config('app.timezone');
            $monthly = [
                ['Rent', 'Shop rent', 150000, 'bank_transfer', 'Landlord', 1],
                ['Salaries & wages', 'Tailors\' wages', 280000, 'bank_transfer', null, 28],
                ['Power & fuel', 'Diesel for the generator', 45000, 'cash', 'Filling station', 6],
                ['Power & fuel', 'Electricity token', 18000, 'card', 'Ikeja Electric', 12],
                ['Internet & phone', 'Internet subscription', 15000, 'card', null, 3],
                ['Transport & logistics', 'Deliveries and fabric runs', 22000, 'cash', null, 17],
                ['Marketing & adverts', 'Instagram adverts', 30000, 'card', null, 20],
                ['Workshop supplies', 'Machine needles, chalk and oil', 9500, 'cash', null, 9],
            ];
            $today = now($tz)->startOfDay();
            for ($m = 2; $m >= 0; $m--) {
                $month = now($tz)->startOfMonth()->subMonthsNoOverflow($m);
                foreach ($monthly as [$cat, $desc, $amount, $method, $to, $day]) {
                    $date = $month->copy()->day(min($day, $month->daysInMonth));
                    if ($date->gt($today)) {
                        continue;
                    }
                    Expense::create([
                        'number' => DocumentNumber::next('expense'), 'branch_id' => $branch, 'category' => $cat, 'description' => $desc,
                        'amount' => $amount, 'spent_on' => $date->toDateString(), 'method' => $method, 'paid_to' => $to,
                        'status' => 'recorded', 'recorded_by' => $owner,
                    ]);
                }
            }
            $this->command?->info(Expense::count() . ' sample expenses added.');
        });
    }
}
