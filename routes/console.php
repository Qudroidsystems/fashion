<?php

use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Artisan;

/*
| Re-sync the permission catalogue and every tenant's default roles after
| adding permissions to App\Support\PermissionCatalog.
*/
Artisan::command('fhmp:sync-permissions', function (TenantProvisioner $provisioner) {
    TenantProvisioner::syncPermissionCatalog();

    \App\Models\Tenant::query()->each(function ($tenant) use ($provisioner) {
        $provisioner->syncDefaultRoles($tenant);
        $this->line("  synced roles for {$tenant->name}");
    });

    $this->info('Permission catalogue and default roles are up to date.');
})->purpose('Sync permissions and default roles for every fashion house');

/* Add the starter measurement parameters and templates to fashion houses that lack them. */
Artisan::command('fhmp:measurement-kit {tenant? : slug; all houses when omitted}', function (\App\Services\Measurements\MeasurementStarterKit $kit) {
    $q = \App\Models\Tenant::query();
    if ($slug = $this->argument('tenant')) {
        $q->where('slug', $slug);
    }
    $q->each(function ($tenant) use ($kit) {
        $kit->install($tenant);
        $this->line("  measurement kit ready for {$tenant->name}");
    });
})->purpose('Install the starter measurement parameters and templates');

Artisan::command('fhmp:production-kit {tenant? : slug; all houses when omitted}', function (\App\Services\Production\ProductionStarterKit $kit) {
    $q = \App\Models\Tenant::query();
    if ($slug = $this->argument('tenant')) {
        $q->where('slug', $slug);
    }
    $q->each(function ($tenant) use ($kit) {
        $kit->install($tenant);
        $this->line("  production workflows ready for {$tenant->name}");
    });
})->purpose('Install the starter production workflows and quality checklist (skips houses that already have workflows)');

/* Subscriptions: trials ending, renewals (saved card), failed-payment retries, grace periods, notices. */
Artisan::command('fhmp:billing', function (\App\Services\Billing\SubscriptionService $subs) {
    if (! app(\App\Services\Billing\Entitlements::class)->enabled()) {
        $this->info('Subscriptions are off (single-house mode or BILLING_ENABLED=false).');

        return;
    }
    $r = $subs->run();
    $this->info("Renewed {$r['renewed']}, failed {$r['failed']}, moved to free {$r['to_free']}, notices {$r['notices']}.");
})->purpose('Renew house subscriptions and move unpaid ones to the free plan');
\Illuminate\Support\Facades\Schedule::command('fhmp:billing')->hourly()->withoutOverlapping();

Artisan::command('fhmp:billing-plans', function () {
    $this->info(\App\Support\Billing\PlanDefaults::install() . ' default plan(s) added.');
})->purpose('Add the four starting plans if they are missing');

/*
| FR-MEA-011: remind staff to re-measure customers whose last approved
| measurements are older than the house's review interval. One follow-up
| per customer per month (deduplicated by source_key).
*/
Artisan::command('fhmp:measurement-reviews', function () {
    $created = 0;
    \App\Models\Tenant::whereIn('status', config('tenancy.usable_statuses'))->each(function ($tenant) use (&$created) {
        \App\Support\Tenancy\CurrentTenant::run($tenant, function ($tenant) use (&$created) {
            $months = (int) $tenant->setting('measurement_review_months', 6);
            if ($months < 1) {
                return;
            }
            $cutoff = now()->subMonths($months);
            $period = now($tenant->timezone)->format('Y-m');

            \App\Models\Customer::active()
                ->whereHas('measurementSessions', fn ($q) => $q->where('status', 'approved'))
                ->whereDoesntHave('measurementSessions', fn ($q) => $q->whereIn('status', ['approved', 'submitted'])->where(fn ($w) => $w->where('verified_at', '>=', $cutoff)->orWhere('submitted_at', '>=', $cutoff)))
                ->each(function ($customer) use ($months, $period, &$created) {
                    $task = \App\Models\FollowUpTask::firstOrCreate(
                        ['source_key' => "measurement-due:{$customer->id}:{$period}"],
                        [
                            'customer_id' => $customer->id,
                            'title'       => 'Measurements due for review',
                            'details'     => "Last approved measurements are over {$months} months old. This is a reminder to check, not a sign the measurements changed.",
                            'due_at'      => now()->addDays(3),
                            'assigned_to' => $customer->assigned_to,
                            'status'      => 'open',
                        ]
                    );
                    $created += (int) $task->wasRecentlyCreated;
                });
        });
    });
    $this->info("{$created} measurement review reminder(s) created.");
})->purpose('Create follow-ups for customers due for re-measuring');

\Illuminate\Support\Facades\Schedule::command('fhmp:measurement-reviews')->dailyAt('07:00');

/*
| SRS §17 "inventory balance drift": check the cached stock balances against
| the append-only ledger and active reservations. --fix rebuilds the cache.
*/
Artisan::command('fhmp:stock-reconcile {--fix : rebuild balances from the ledger}', function () {
    $total = 0;
    \App\Models\Tenant::query()->each(function ($tenant) use (&$total) {
        \App\Support\Tenancy\CurrentTenant::run($tenant, function ($tenant) use (&$total) {
            $diffs = app(\App\Services\Inventory\StockLedger::class)->reconcile((bool) $this->option('fix'));
            foreach ($diffs as $d) {
                $item = \App\Models\InventoryItem::find($d['itemId']);
                $this->warn(sprintf('  %s · %s · item %s (lot %s): on hand %s vs ledger %s, reserved %s vs %s',
                    $tenant->name, \App\Models\Branch::withTrashed()->find($d['branchId'])?->name, $item?->code, $d['lotKey'] ?: '—',
                    $d['haveOnHand'], $d['wantOnHand'], $d['haveReserved'], $d['wantReserved']));
            }
            $total += $diffs->count();
        });
    });
    $total ? $this->error("{$total} difference(s)" . ($this->option('fix') ? ' fixed.' : ' found. Run with --fix to rebuild.'))
           : $this->info('Stock balances match the ledger.');

    return $total && ! $this->option('fix') ? 1 : 0;
})->purpose('Check stock balances against the stock ledger');

\Illuminate\Support\Facades\Schedule::command('fhmp:stock-reconcile')->dailyAt('02:30');

/* FR-ORD-008: re-check online payments still pending (a missed webhook never loses a payment). */
Artisan::command('fhmp:verify-payments', function () {
    $n = 0;
    \App\Models\PaymentIntent::withoutGlobalScopes()->where('status', 'pending')->whereNotNull('authorization_url')
        ->where('created_at', '>=', now()->subDays(3))->get()
        ->each(function ($intent) use (&$n) {
            $tenant = \App\Models\Tenant::find($intent->tenant_id);
            \App\Support\Tenancy\CurrentTenant::run($tenant, function () use ($intent, &$n) {
                try {
                    $n += (int) (app(\App\Services\Payments\OnlinePaymentService::class)->confirm($intent)->status === 'succeeded');
                } catch (\Throwable $e) {
                    $this->warn("  {$intent->reference}: {$e->getMessage()}");
                }
            });
        });
    $this->info("{$n} online payment(s) confirmed.");
})->purpose('Verify pending online payments with the provider');

\Illuminate\Support\Facades\Schedule::command('fhmp:verify-payments')->everyFifteenMinutes();

/* Add the starter production workflows and checklist to houses that have none. */
Artisan::command('fhmp:production-kit {tenant? : slug; all houses when omitted}', function (\App\Services\Production\ProductionStarterKit $kit) {
    \App\Models\Tenant::query()->when($this->argument('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
        ->each(function ($tenant) use ($kit) {
            $kit->install($tenant);
            $this->line("  production workflows ready for {$tenant->name}");
        });
})->purpose('Install the starter production workflows and quality checklist');

/* FR-PRD-005 / §10: send fitting reminders that are due (cancelled or moved fittings are skipped). */
Artisan::command('fhmp:fitting-reminders', function () {
    $n = 0;
    \App\Models\Tenant::whereIn('status', config('tenancy.usable_statuses'))->each(function ($tenant) use (&$n) {
        \App\Support\Tenancy\CurrentTenant::run($tenant, function () use (&$n) {
            $n += app(\App\Services\Production\FittingService::class)->sendDue();
        });
    });
    $this->info("{$n} fitting reminder(s) sent.");
})->purpose('Send due fitting reminders');

\Illuminate\Support\Facades\Schedule::command('fhmp:fitting-reminders')->everyFiveMinutes()->withoutOverlapping();

/* FR-PRD-009: re-assess late-delivery risk for open orders; people hear only when it rises. */
Artisan::command('fhmp:delivery-risk', function () {
    $n = 0;
    \App\Models\Tenant::whereIn('status', config('tenancy.usable_statuses'))->each(function ($tenant) use (&$n) {
        \App\Support\Tenancy\CurrentTenant::run($tenant, function () use (&$n) {
            $n += app(\App\Services\Production\RiskService::class)->refreshAll();
        });
    });
    $this->info("{$n} open order(s) assessed.");
})->purpose('Flag orders at risk of late delivery');

\Illuminate\Support\Facades\Schedule::command('fhmp:delivery-risk')->dailyAt('07:15');

/* Scheduled reports: Monday-morning summary of last week to owners and finance staff (once per house per week). */
Artisan::command('fhmp:weekly-summary {tenant? : slug; all houses when omitted} {--force : send again even if sent this week}', function () {
    $n = 0;
    \App\Models\Tenant::whereIn('status', config('tenancy.usable_statuses'))
        ->when($this->argument('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
        ->each(function ($tenant) use (&$n) {
            $n += app(\App\Services\Reports\WeeklySummary::class)->sendFor($tenant, (bool) $this->option('force'));
        });
    $this->info("Weekly summary sent to {$n} person(s).");
})->purpose('E-mail last week\'s figures to owners and finance staff');

\Illuminate\Support\Facades\Schedule::command('fhmp:weekly-summary')->weeklyOn(1, '07:30')->timezone(config('app.timezone'));

/*
| Scheduled jobs (birthdays, measurement reviews, fittings, overdue payments…)
| are registered here with Schedule::command(...) as the modules land.
| They must run per tenant, in the tenant's timezone (SRS FR-NOT-006).
*/
