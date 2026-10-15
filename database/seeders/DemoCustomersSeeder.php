<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\FollowUpTask;
use App\Models\Tenant;
use App\Services\Crm\CustomerService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * Optional sample customers for trying the CRM:
 *   php artisan db:seed --class=DemoCustomersSeeder
 */
class DemoCustomersSeeder extends Seeder
{
    public function run(CustomerService $service): void
    {
        $tenant = Tenant::where('slug', config('tenancy.default_tenant'))->firstOrFail();

        CurrentTenant::run($tenant, function () use ($service, $tenant) {
            $ownerId = $tenant->memberships()->where('is_owner', true)->value('user_id');
            $people = [
                ['Mrs', 'Ngozi', 'Eze', 'female', '08031234567', 'ngozi.eze@example.com', '1988-' . now()->format('m') . '-' . now()->addDays(2)->format('d'), 'Lekki', ['bride', 'referral'], true, 'instagram'],
                ['Mr', 'Tunde', 'Bakare', 'male', '08029876543', 'tunde.b@example.com', '1979-03-21', 'Ikeja', ['corporate'], false, 'referral'],
                ['Miss', 'Aisha', 'Bello', 'female', '07061112222', null, null, 'Abuja', ['aso-ebi'], false, 'whatsapp'],
                ['Chief', 'Emeka', 'Okafor', 'male', '08100001111', 'emeka@example.com', '1965-11-02', 'Enugu', ['vip-native'], true, 'walk_in'],
                ['Mrs', 'Funmi', 'Adeyemi', 'female', '09012223333', 'funmi.a@example.com', '1992-07-30', 'Ibadan', ['bride'], false, 'tiktok'],
            ];

            foreach ($people as [$title, $first, $last, $gender, $phone, $email, $bday, $city, $tags, $vip, $source]) {
                $c = Customer::firstOrCreate(['phone' => $phone], [
                    'title' => $title, 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
                    'email' => $email, 'birthday' => $bday, 'city' => $city, 'is_vip' => $vip, 'source' => $source,
                    'assigned_to' => $ownerId, 'preferences' => ['fit' => 'Fitted', 'fabrics' => 'Ankara, lace'],
                ]);
                $service->syncTags($c, $tags);
                CustomerNote::firstOrCreate(['customer_id' => $c->id, 'body' => "Prefers fittings on Saturdays."], ['created_by' => $ownerId]);
                FollowUpTask::firstOrCreate(['customer_id' => $c->id, 'title' => 'Call to book first fitting'], [
                    'due_at' => now()->addDays(rand(-2, 5)), 'assigned_to' => $ownerId, 'status' => 'open', 'created_by' => $ownerId,
                ]);
            }
        });

        $this->command?->info('Demo customers added to ' . $tenant->name);
    }
}
