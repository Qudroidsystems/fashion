<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SRS FR-TEN-001 / FR-TEN-005 / FR-TEN-006. */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();          // exposed to apps/URLs instead of the numeric id
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->string('domain')->nullable()->unique(); // optional verified custom domain
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->string('locale', 10)->default('en');
            $table->char('currency_code', 3)->default('NGN');
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 9)->nullable();
            $table->enum('status', ['trial', 'active', 'past_due', 'suspended', 'cancelled'])->default('trial')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->json('settings')->nullable();          // numbering formats, measurement units, notification prefs…
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
