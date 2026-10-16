<?php

use App\Support\Billing\PlanDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions for fashion houses (multi-house mode). Platform-level tables:
 * a house never edits them, the platform super admin does.
 *
 *  - plans: name, prices, limits (customers, staff, branches; null = unlimited) and features.
 *  - subscriptions: one per house — plan, trial/period dates, renewal card (encrypted).
 *  - subscription_invoices: every charge attempt, paid through the platform's own Paystack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('tagline', 160)->nullable();
            $table->decimal('price_monthly', 14, 2)->default(0);
            $table->decimal('price_yearly', 14, 2)->default(0);
            $table->json('limits');                                   // {customers: 20, staff: 2, branches: 1}; null value = unlimited
            $table->json('features');                                 // ["pos", "inventory", …] — see App\Support\Billing\Features
            $table->boolean('is_free')->default(false);               // the plan houses fall back to
            $table->boolean('is_active')->default(true);              // can be chosen
            $table->boolean('is_public')->default(true);              // shown on the landing and billing pages
            $table->boolean('highlight')->default(false);             // "most popular"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->enum('interval', ['monthly', 'yearly'])->default('monthly');
            $table->enum('status', ['trialing', 'active', 'past_due'])->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();      // null = no end (free plan, or granted by the platform)
            $table->boolean('cancel_at_period_end')->default(false);
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();   // downgrade waiting for the period end
            $table->enum('pending_interval', ['monthly', 'yearly'])->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('last_notice_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->text('authorization')->nullable();                // encrypted Paystack authorization (reusable card)
            $table->string('card_label', 80)->nullable();             // "Visa •••• 4081, exp 09/28"
            $table->string('billing_email', 160)->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 40)->unique();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->enum('interval', ['monthly', 'yearly']);
            $table->enum('reason', ['new', 'upgrade', 'renewal', 'manual'])->default('new');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('NGN');
            $table->enum('status', ['pending', 'paid', 'failed', 'void'])->default('pending');
            $table->string('reference', 80)->unique();
            $table->string('gateway', 20)->default('paystack');
            $table->string('channel', 30)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        PlanDefaults::install();

        // Houses that already exist keep everything: they start on the top plan with no end date,
        // and the platform super admin moves them to the right plan.
        $top = \Illuminate\Support\Facades\DB::table('plans')->orderByDesc('sort_order')->value('id');
        foreach (\Illuminate\Support\Facades\DB::table('tenants')->pluck('id') as $tenantId) {
            \Illuminate\Support\Facades\DB::table('subscriptions')->insert([
                'tenant_id' => $tenantId, 'plan_id' => $top, 'interval' => 'monthly', 'status' => 'active',
                'current_period_start' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
