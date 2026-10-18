<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House running costs (rent, salaries, power and fuel, transport…) for the
 * profit report (SRS FR-REP-005). Project expenses stay on the project;
 * stock bought for resale or production is in the stock ledger, not here.
 * Expenses are never edited after the day they were recorded and are voided,
 * not deleted, so reports for a closed month don't change silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('category', 60);
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->date('spent_on');
            $table->enum('method', ['cash', 'bank_transfer', 'card', 'mobile_money', 'cheque', 'other'])->default('cash');
            $table->string('paid_to', 120)->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('receipt_path')->nullable();
            $table->enum('status', ['recorded', 'void'])->default('recorded');
            $table->string('void_reason', 255)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'spent_on']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
