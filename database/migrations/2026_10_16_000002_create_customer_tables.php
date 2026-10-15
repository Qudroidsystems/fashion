<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Customer CRM — SRS §5.3 (FR-CRM-001 … 006) and §7 "CRM" entity group. */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('customer_number', 40);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('title', 20)->nullable();
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();   // digits only, for duplicate matching
            $table->string('alt_phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();                 // optional (FR-CRM-001)
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('occupation', 100)->nullable();
            $table->string('source', 40)->nullable();             // walk-in, referral, instagram…
            $table->boolean('is_vip')->default(false);
            $table->json('preferences')->nullable();              // fit, fabrics, colours, styles…
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'archived', 'merged'])->default('active');
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // portal / app account (later)
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'customer_number']);
            $table->index(['tenant_id', 'phone_normalized']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });

        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 9)->default('#64748b');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('customer_tag_links', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();
            $table->primary(['customer_id', 'customer_tag_id']);
        });

        // FR-CRM-004: consent by channel and purpose.
        Schema::create('customer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('channel', ['sms', 'email', 'whatsapp', 'push']);
            $table->enum('purpose', ['transactional', 'marketing']);
            $table->boolean('granted')->default(false);
            $table->string('source', 40)->nullable();        // in person, web form, app…
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'channel', 'purpose']);
        });

        // FR-CRM-006: sensitive notes are only visible with a permission.
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'done', 'cancelled'])->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_tasks');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_consents');
        Schema::dropIfExists('customer_tag_links');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customers');
    }
};
