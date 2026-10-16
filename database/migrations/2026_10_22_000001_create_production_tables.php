<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production, quality and delivery (SRS §5.9, §6.2, §7 "Production").
 *
 * Workflows are templates of stages. Planning an order copies the stages into
 * production_tasks per garment line, so editing a workflow later never changes
 * work already planned. Every change to a task is an append-only
 * production_event; the task row is the queryable current state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('items');                                      // [{label, required}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('production_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->json('line_kinds');                                 // order line kinds it applies to: bespoke, service, ready_made, charge
            $table->foreignId('category_id')->nullable()->constrained('catalogue_categories')->nullOnDelete();   // narrower match for bespoke styles
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('production_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('production_workflows')->cascadeOnDelete();
            $table->string('name', 80);
            $table->enum('type', ['work', 'fitting', 'qc'])->default('work');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('duration_hours')->default(8); // expected working hours (8 = one day)
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();   // who normally does it
            $table->boolean('requires_previous')->default(true);       // prerequisite: earlier stages done
            $table->boolean('customer_visible')->default(false);
            $table->foreignId('checklist_id')->nullable()->constrained('quality_checklists')->nullOnDelete();
            $table->string('instructions', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('production_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('production_stages')->nullOnDelete();
            $table->enum('kind', ['stage', 'alteration', 'rework'])->default('stage');
            $table->string('name', 120);
            $table->enum('type', ['work', 'fitting', 'qc'])->default('work');
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('duration_hours')->default(8);
            $table->boolean('requires_previous')->default(true);
            $table->boolean('customer_visible')->default(false);
            $table->foreignId('checklist_id')->nullable()->constrained('quality_checklists')->nullOnDelete();
            $table->enum('status', ['blocked', 'pending', 'in_progress', 'on_hold', 'done', 'cancelled'])->default('pending');
            $table->enum('priority', ['normal', 'high', 'rush'])->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('blocks_task_id')->nullable()->constrained('production_tasks')->nullOnDelete();   // rework/alteration holds this task
            // Alterations (FR-PRD-007)
            $table->string('reason', 255)->nullable();
            $table->enum('responsible_party', ['customer', 'house', 'staff', 'supplier'])->nullable();
            $table->enum('cost_treatment', ['free', 'chargeable', 'absorbed'])->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->string('resolution', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_to', 'status']);
            $table->index(['order_line_id', 'position']);
        });

        Schema::create('production_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('production_tasks')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->cascadeOnDelete();
            $table->string('type', 30);                                 // created, assigned, started, paused, resumed, completed, reopened, note, blocked, unblocked, qc_passed, qc_failed, cancelled
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('note', 1000)->nullable();
            $table->string('evidence_path')->nullable();
            $table->boolean('customer_visible')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['task_id', 'id']);
            $table->index(['order_id', 'id']);
        });

        Schema::create('quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('production_tasks')->nullOnDelete();
            $table->foreignId('checklist_id')->nullable()->constrained('quality_checklists')->nullOnDelete();
            $table->json('results');                                    // [{label, passed, note}]
            $table->enum('result', ['pass', 'fail']);
            $table->text('issues')->nullable();
            $table->foreignId('rework_task_id')->nullable()->constrained('production_tasks')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('fittings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('production_tasks')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->enum('status', ['scheduled', 'attended', 'no_show', 'cancelled', 'rescheduled'])->default('scheduled');
            $table->enum('outcome', ['approved', 'alterations'])->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('rescheduled_to_id')->nullable()->constrained('fittings')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        // One row per reminder so a reschedule can cancel exactly the stale ones (scenario 40).
        Schema::create('fitting_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('fitting_id')->constrained('fittings')->cascadeOnDelete();
            $table->dateTime('remind_at');
            $table->unsignedSmallInteger('hours_before');
            $table->enum('status', ['pending', 'sent', 'cancelled', 'skipped'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'remind_at']);
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->json('line_ids');
            $table->enum('method', ['collection', 'delivery'])->default('collection');
            $table->dateTime('handed_over_at');
            $table->string('recipient_name', 120);
            $table->string('recipient_phone', 30)->nullable();
            $table->string('relationship', 60)->nullable();             // self, sister, driver…
            $table->enum('confirmation', ['signature', 'id_checked', 'code', 'photo', 'none'])->default('none');
            $table->string('confirmation_ref', 120)->nullable();
            $table->string('evidence_path')->nullable();
            $table->decimal('balance_at_handover', 14, 2)->default(0);
            $table->text('outstanding_conditions')->nullable();
            $table->string('address', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::table('material_issues', function (Blueprint $table) {
            $table->foreignId('order_line_id')->nullable()->after('order_id')->constrained('order_lines')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->after('order_line_id')->constrained('production_tasks')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('risk_level', 10)->nullable()->after('priority');   // null|watch|high (FR-PRD-009)
            $table->json('risk_reasons')->nullable()->after('risk_level');
            $table->timestamp('risk_assessed_at')->nullable()->after('risk_reasons');
            $table->string('risk_notified_level', 10)->nullable()->after('risk_assessed_at');   // last level people were told about
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['risk_level', 'risk_reasons', 'risk_assessed_at', 'risk_notified_level']));
        Schema::table('material_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('order_line_id');
        });
        foreach (['deliveries', 'fitting_reminders', 'fittings', 'quality_checks', 'production_events', 'production_tasks', 'production_stages', 'production_workflows', 'quality_checklists'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
