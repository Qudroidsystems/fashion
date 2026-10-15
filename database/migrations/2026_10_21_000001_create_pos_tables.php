<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point of sale and online payments — SRS §5.7 (FR-POS-001…007), §6.3, FR-ORD-008, FR-ADM-004.
 *
 * A sale, its tenders, its stock movements and any order payments are written
 * in one transaction. `client_ref` makes a retried sale return the first one
 * (scenario 35). Provider callbacks are recorded in webhook_events (replay
 * protection) and payments are confirmed only after verifying with the
 * provider (scenario 36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();       // the cashier
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->string('variance_reason', 255)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('cash_shifts')->cascadeOnDelete();
            $table->enum('type', ['pay_in', 'pay_out']);
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->string('client_ref', 64);                        // idempotency: same ref → same sale
            $table->foreignId('shift_id')->constrained('cash_shifts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->enum('status', ['completed', 'voided'])->default('completed');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('tendered', 14, 2);
            $table->decimal('change_given', 14, 2)->default(0);
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'client_ref']);
            $table->index(['tenant_id', 'shift_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('kind', ['product', 'order_payment']);
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('list_price', 14, 2)->nullable();
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('returned_quantity', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'mobile_money']);
            $table->decimal('amount', 14, 2);                         // applied to the sale (cash net of change)
            $table->decimal('received', 14, 2)->nullable();           // cash handed over
            $table->string('reference', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();   // drawer the cash came from
            $table->enum('refund_method', ['cash', 'card', 'bank_transfer', 'mobile_money', 'store_credit']);
            $table->decimal('amount', 14, 2);
            $table->boolean('restock')->default(true);
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained('sale_lines')->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('branch_id')->constrained('sales')->nullOnDelete();
            $table->string('channel', 20)->nullable()->after('method');   // counter | pos | online
        });

        /* Online payment attempts with a provider (Paystack). */
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('provider', 20);
            $table->string('reference', 100)->unique();              // our reference sent to the provider
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->enum('status', ['pending', 'succeeded', 'failed', 'abandoned'])->default('pending');
            $table->string('authorization_url', 500)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('provider_channel', 40)->nullable();
            $table->json('provider_payload')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        /* Every provider callback, so a replayed one is recognised and ignored. */
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('event_key', 191);                        // provider event id or hash of the body
            $table->string('event_type', 60)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->enum('status', ['received', 'processed', 'ignored', 'failed'])->default('received');
            $table->string('message', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_intents');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
            $table->dropColumn('channel');
        });
        foreach (['sale_return_lines', 'sale_returns', 'sale_tenders', 'sale_lines', 'sales', 'cash_movements', 'cash_shifts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
