<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Running numbers for tenant documents (customers, quotes, orders,
     * invoices, receipts…) — SRS FR-ORD-004. One row per tenant + key + period.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 40);          // customer, order, invoice…
            $table->string('period', 10)->default('');   // '' or '2026' when the format resets yearly
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
