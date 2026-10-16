<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House websites: enquiries sent from a house's public site, and verification
 * of a house's own domain. Site content settings live in tenants.settings.website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->enum('type', ['enquiry', 'booking', 'measurements'])->default('enquiry');
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('style_id')->nullable()->constrained('fashion_styles')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time', 40)->nullable();
            $table->enum('status', ['new', 'contacted', 'converted', 'closed', 'spam'])->default('new');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('domain_verified_at')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('domain_verified_at'));
        Schema::dropIfExists('site_enquiries');
    }
};
