<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Campaigns: one message to a group of customers who agreed to promotions (festive offers, new collections…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            $table->text('body');
            $table->json('channels');
            $table->json('audience');                          // {type: all|vip|tags|birthday_month|recent|lapsed, tag_ids?, days?}
            $table->string('status', 20)->default('draft');    // draft, scheduled, sending, sent, cancelled
            $table->timestamp('send_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
