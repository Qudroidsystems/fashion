<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes, orders, invoices and payments — SRS §5.8 (FR-ORD-001…011), §7 orders & finance.
 *
 * Money is decimal(14,2) and every total is calculated on the server
 * (App\Services\Orders\Pricing). Balances are never stored: they are derived
 * from charges (order / invoice totals) less payment allocations, so there
 * is one source of truth. Posted payments, allocations and issued invoices
 * are never edited or deleted — reversals, refunds and voids are new records.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lineColumns = function (Blueprint $table) {
            $table->enum('kind', ['bespoke', 'ready_made', 'service', 'charge'])->default('bespoke');
            $table->foreignId('style_id')->nullable()->constrained('fashion_styles')->nullOnDelete();
            $table->foreignId('style_version_id')->nullable()->constrained('style_versions')->nullOnDelete();   // pinned (scenario 31)
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('description', 255);
            $table->json('options')->nullable();                      // chosen style add-ons [{label, amount}]
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2);                     // incl. chosen add-ons
            $table->decimal('list_price', 14, 2)->nullable();         // catalogue price when added (override audit)
            $table->decimal('discount', 14, 2)->default(0);           // per line, amount
            $table->decimal('line_total', 14, 2);
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
        };

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['draft', 'sent', 'accepted', 'declined', 'expired', 'converted'])->default('draft');
            $table->string('title', 150)->nullable();                 // e.g. "Wedding — bride & 6 bridesmaids"
            $table->date('event_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);     // line + order discounts
            $table->decimal('order_discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) use ($lineColumns) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $lineColumns($table);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->string('status', 30)->default('draft');           // see App\Support\Orders\OrderStatus
            $table->string('title', 150)->nullable();
            $table->date('event_date')->nullable();                   // wedding / occasion date
            $table->date('promised_date')->nullable();                // delivery promise
            $table->enum('priority', ['normal', 'rush'])->default('normal');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('order_discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('deposit_required', 14, 2)->default(0);
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();   // order owner / account manager
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'promised_date']);
        });

        Schema::create('order_lines', function (Blueprint $table) use ($lineColumns) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $lineColumns($table);
            $table->foreignId('measurement_session_id')->nullable()->constrained('measurement_sessions')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wearer', 120)->nullable();                // who it is for, if not the customer
            $table->enum('status', ['pending', 'in_production', 'ready', 'delivered', 'cancelled'])->default('pending');
            $table->timestamps();
        });

        Schema::create('order_material_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->foreignId('reservation_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('note', 500)->nullable();
            $table->boolean('customer_visible')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('reason', 255);
            $table->decimal('total_before', 14, 2);
            $table->decimal('total_after', 14, 2);
            $table->json('lines_before');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40)->nullable();                 // given when issued
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->enum('kind', ['deposit', 'balance', 'full', 'other'])->default('other');
            $table->enum('status', ['draft', 'issued', 'void'])->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);                             // receipt number
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'bank_transfer', 'card', 'mobile_money', 'cheque', 'other']);
            $table->string('reference', 120)->nullable();             // bank / provider reference
            $table->enum('status', ['confirmed', 'reversed'])->default('confirmed');
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'method', 'reference']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'bank_transfer', 'card', 'mobile_money', 'cheque', 'other']);
            $table->string('reason', 255);
            $table->enum('status', ['pending_approval', 'paid', 'rejected'])->default('pending_approval');
            $table->string('reference', 120)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();
        });

        /*
         * Where payment money went. Positive = applied to an order / invoice;
         * negative = taken back by a refund or a payment reversal. Never edited.
         */
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'invoice_id']);
        });

        // Link stock documents to orders now that orders exist.
        Schema::table('material_issues', fn (Blueprint $t) => $t->index(['tenant_id', 'order_id']));
        Schema::table('stock_reservations', fn (Blueprint $t) => $t->index(['tenant_id', 'order_id']));
    }

    public function down(): void
    {
        Schema::table('material_issues', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'order_id']));
        Schema::table('stock_reservations', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'order_id']));
        foreach (['payment_allocations', 'refunds', 'payments', 'invoice_lines', 'invoices', 'order_revisions', 'order_status_events',
            'order_material_requirements', 'order_lines', 'orders', 'quotation_lines', 'quotations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
