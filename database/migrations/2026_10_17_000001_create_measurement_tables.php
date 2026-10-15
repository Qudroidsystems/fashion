<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Measurements — SRS §5.4, §5.5, §6.1, §6.5, §7 (Measurement configuration + Measurements).
 *
 * Canonical storage: every length is stored in MILLIMETRES (decimal 10,2).
 * The unit the staff/customer typed is kept next to it; UI conversion is display-only.
 *
 * Versioning: a published template version keeps a SNAPSHOT of each parameter
 * definition (code, label, type, range, instructions). Editing a parameter or
 * the template later never changes a published version or any session that used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('label', 80);
            $table->string('body_area', 30)->nullable();          // upper, lower, full, other
            $table->enum('data_type', ['length', 'number', 'text', 'select', 'boolean'])->default('length');
            $table->boolean('is_bilateral')->default(false);       // separate left / right values
            $table->json('options')->nullable();                   // for select
            $table->decimal('min_mm', 10, 2)->nullable();          // validation range (canonical)
            $table->decimal('max_mm', 10, 2)->nullable();
            $table->decimal('change_threshold_mm', 10, 2)->nullable(); // flag when it moves more than this
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();              // how to measure (shown to staff and customers)
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('measurement_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 40);
            $table->string('garment_category', 60)->nullable();
            $table->enum('audience', ['any', 'female', 'male', 'child'])->default('any');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();   // latest published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('measurement_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('measurement_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');
            $table->text('change_notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'version_no']);
        });

        Schema::create('measurement_template_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_version_id')->constrained('measurement_template_versions')->cascadeOnDelete();
            $table->foreignId('parameter_id')->nullable()->constrained('measurement_parameters')->nullOnDelete();
            // Snapshot of the parameter definition at publish time:
            $table->string('code', 40);
            $table->string('label', 80);
            $table->enum('data_type', ['length', 'number', 'text', 'select', 'boolean'])->default('length');
            $table->boolean('is_bilateral')->default(false);
            $table->json('options')->nullable();
            $table->decimal('min_mm', 10, 2)->nullable();
            $table->decimal('max_mm', 10, 2)->nullable();
            $table->decimal('change_threshold_mm', 10, 2)->nullable();
            $table->text('instructions')->nullable();
            // Template-specific:
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('guidance', 255)->nullable();
            $table->timestamps();

            $table->unique(['template_version_id', 'code']);
        });

        Schema::create('measurement_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained('measurement_template_versions')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'superseded'])->default('draft');
            $table->enum('capture_method', ['staff_in_person', 'customer_link', 'customer_app', 'imported'])->default('staff_in_person');
            $table->string('display_unit', 4)->default('in');
            $table->timestamp('measured_at')->nullable();
            $table->foreignId('measured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('measurement_sessions')->nullOnDelete();
            $table->string('correction_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->json('change_summary')->nullable();          // flags vs the previous approved session
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'status', 'submitted_at']);
        });

        Schema::create('measurement_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('measurement_sessions')->cascadeOnDelete();
            $table->foreignId('template_parameter_id')->constrained('measurement_template_parameters')->restrictOnDelete();
            $table->string('parameter_code', 40);
            $table->enum('side', ['both', 'left', 'right'])->default('both');
            $table->decimal('value_mm', 10, 2)->nullable();       // canonical (length)
            $table->decimal('number_value', 12, 3)->nullable();   // non-length numbers
            $table->decimal('original_value', 12, 3)->nullable(); // as typed
            $table->string('original_unit', 4)->nullable();
            $table->string('text_value', 255)->nullable();        // text / select / boolean
            $table->enum('validation_state', ['ok', 'out_of_range', 'flagged'])->default('ok');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'template_parameter_id', 'side']);
        });

        // SRS §5.5 secure customer measurement request links.
        Schema::create('measurement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('template_version_id')->constrained('measurement_template_versions')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();             // sha256 of the token; the token itself is never stored
            $table->enum('status', ['pending', 'submitted', 'expired', 'revoked'])->default('pending');
            $table->string('message', 500)->nullable();
            $table->timestamp('expires_at');
            $table->date('deadline')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('session_id')->nullable()->constrained('measurement_sessions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_requests');
        Schema::dropIfExists('measurement_values');
        Schema::dropIfExists('measurement_sessions');
        Schema::dropIfExists('measurement_template_parameters');
        Schema::dropIfExists('measurement_template_versions');
        Schema::dropIfExists('measurement_templates');
        Schema::dropIfExists('measurement_parameters');
    }
};
