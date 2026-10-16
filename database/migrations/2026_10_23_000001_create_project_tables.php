<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weddings, events and bulk projects (SRS §5.10, §6.4, §7 "Projects").
 *
 * Money never lives on the project itself. Every garment is an order line, on
 * either the project's main (client-billed) order or a self-paying
 * participant's own order; orders.project_id links them. Project totals are
 * sums over those orders, so nothing is counted twice (FR-PRJ-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->string('name', 160);
            $table->enum('type', ['wedding', 'event', 'uniform', 'corporate', 'group', 'other'])->default('wedding');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();        // the client who signs and pays
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['planning', 'active', 'completed', 'cancelled'])->default('planning');
            $table->date('event_date')->nullable();
            $table->date('delivery_date')->nullable();                                             // when everything must be handed over
            $table->string('venue', 255)->nullable();
            $table->decimal('contract_amount', 14, 2)->default(0);                                // agreed price, compared with garment totals
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->text('client_message')->nullable();                                           // shown on the tracking page
            $table->text('internal_notes')->nullable();
            // Client tracking page (FR-PRJ-006): random token, hash for lookup, encrypted copy so staff can re-share it.
            $table->string('tracking_token_hash', 64)->nullable()->unique();
            $table->text('tracking_token')->nullable();
            $table->timestamp('tracking_expires_at')->nullable();
            $table->timestamp('tracking_viewed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event_date']);
        });

        Schema::create('project_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();   // created when they are measured or pay
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('role', 60)->nullable();                                                // Bride, Groom, Bridesmaid, Staff…
            $table->string('group_name', 60)->nullable();                                          // Bridesmaids, Groomsmen, Family…
            $table->enum('pays', ['client', 'self'])->default('client');                           // aso-ebi: guests often pay for their own
            $table->enum('status', ['active', 'withdrawn'])->default('active');
            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('quotation_id')->constrained('projects')->nullOnDelete();
        });
        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('participant_id')->nullable()->after('order_id')->constrained('project_participants')->nullOnDelete();
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'done', 'skipped'])->default('pending');
            $table->string('notes', 500)->nullable();
            // Shown to the client only once someone allowed to publish has published it (FR-PRJ-005, scenario 39).
            $table->boolean('customer_visible')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('body');
            $table->json('images')->nullable();
            $table->boolean('customer_visible')->default(false);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('category', 40);                                                        // fabric, accessories, labour, transport, other
            $table->string('description', 160)->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('description', 160);
            $table->decimal('amount', 14, 2);
            $table->date('spent_on');
            $table->string('paid_to', 120)->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('label', 80);
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Material issued for a project order is the project's cost (scenario 38).
        Schema::table('material_issues', function (Blueprint $table) {
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_issues', fn (Blueprint $t) => $t->dropIndex(['project_id']));
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('participant_id'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        foreach (['project_payment_schedules', 'project_expenses', 'project_budget_lines', 'project_updates', 'project_milestones', 'project_participants', 'projects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
