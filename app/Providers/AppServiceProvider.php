<?php

namespace App\Providers;

use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(\App\Services\Billing\Entitlements::class);
    }

    public function boot(): void
    {
        // Pagination uses the Bootstrap 5 theme (matches the Velzon UI).
        Paginator::useBootstrapFive();

        // Branding for every view: the current fashion house, or the platform defaults.
        View::composer('*', function ($view) {
            $tenant = CurrentTenant::get();

            $brand = [
                    'tenant_id' => $tenant?->id,
                    'name'      => $tenant?->name ?? config('app.name'),
                    'logo'      => $tenant?->logo_url,          // null → views show the initials mark
                    'logo_light'=> $tenant?->logo_url,
                    'favicon'   => $tenant?->logo_url ?? asset('theme/layouts/assets/images/favicon.ico'),
                    'color'     => $tenant?->brand_color ?? '#405189',
                    'platform'  => config('app.name'),
            ];

            $view->with('brand', $brand)->with('currentTenant', $tenant);
        });

        // Plan limits (subscriptions): adding a customer, staff member or branch past the plan's limit is refused.
        $limit = fn (string $key) => function ($model) use ($key) {
            $tenant = $model->tenant_id ? \App\Models\Tenant::find($model->tenant_id) : CurrentTenant::get();
            app(\App\Services\Billing\Entitlements::class)->assertCanAdd($tenant, $key);
        };
        \App\Models\Customer::creating($limit('customers'));
        \App\Models\Branch::creating($limit('branches'));
        \App\Models\TenantMembership::creating(function ($m) use ($limit) {
            if (($m->status ?? 'active') === 'active') {
                $limit('staff')($m);
            }
        });

        // Sign-in events go to the audit trail (FR-IAM-005).
        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => request()?->ip(),
            ])->saveQuietly();

            AuditLogger::log('auth.login', $event->user, description: 'Signed in', userId: $event->user->getAuthIdentifier());
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                AuditLogger::log('auth.logout', $event->user, description: 'Signed out', userId: $event->user->getAuthIdentifier());
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            AuditLogger::log('auth.failed', $event->user,
                description: 'Failed sign-in for ' . mb_substr((string) ($event->credentials['email'] ?? ''), 0, 120),
                userId: $event->user?->getAuthIdentifier());
        });
    }
}
