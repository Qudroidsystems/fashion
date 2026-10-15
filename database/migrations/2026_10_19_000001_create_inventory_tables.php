<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory & procurement — SRS §5.6 (FR-INV-001…010), §7 catalogue/inventory + procurement.
 *
 * Locations are the house's branches (shop, workshop, warehouse…).
 * stock_movements is the append-only ledger; stock_balances is a cached
 * projection updated in the same transaction and checked by
 * `php artisan fhmp:stock-reconcile`.
 *
 * Quantities are decimal(14,3) so fabric can be 2.75 yd; each item says how
 * many decimals it uses (FR-INV-007). Valuation uses weighted-average cost
 * (FR-INV-009); customer-owned material is excluded (FR-INV-010).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('bank_details', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('code', 60);                              // item code / SKU
            $table->string('name', 150);
            $table->enum('type', ['fabric', 'accessory', 'consumable', 'product', 'equipment', 'customer_material']);
            $table->string('category', 80)->nullable();
            $table->string('unit', 15)->default('pcs');              // yd, m, pcs, roll, kg, spool, pack…
            $table->unsignedTinyInteger('decimals')->default(0);     // quantity precision 0–3
            $table->string('barcode', 40)->nullable();
            $table->string('colour', 60)->nullable();
            $table->string('width', 30)->nullable();                 // fabric width, e.g. 58"
            $table->text('description')->nullable();
            $table->decimal('average_cost', 14, 4)->default(0);      // weighted-average unit cost
            $table->decimal('last_cost', 14, 4)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->decimal('reorder_level', 14, 3)->nullable();
            $table->decimal('reorder_quantity', 14, 3)->nullable();
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); // owner of customer material
            $table->boolean('track_lots')->default(false);           // rolls / dye lots / remnants
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'barcode']);
            $table->unique(['tenant_id', 'product_variant_id']);
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('lot_no', 60);
            $table->enum('kind', ['lot', 'roll', 'remnant'])->default('lot');
            $table->string('colour', 60)->nullable();
            $table->string('width', 30)->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('parent_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete(); // remnant cut from
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'lot_no']);
        });

        /* The ledger. Never updated or deleted (enforced in StockMovement). */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->enum('type', [
                'opening', 'receipt', 'supplier_return', 'issue', 'production_return', 'wastage',
                'transfer_out', 'transfer_in', 'adjustment', 'count', 'sale', 'sale_return', 'customer_material',
            ]);
            $table->decimal('quantity', 14, 3);                      // signed: + in, − out
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('balance_after', 14, 3);                 // on-hand at this item/location/lot after the move
            $table->string('source_type', 60)->nullable();           // goods_receipt, material_issue, stock_transfer, stock_adjustment, sale…
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference', 60)->nullable();             // document number shown in the ledger
            $table->string('reason', 255)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();      // linked when the Orders module lands
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'item_id', 'occurred_at']);
            $table->index(['tenant_id', 'branch_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        /* Cached balance per item / location / lot (lot_key 0 = no lot). Rebuildable from the ledger. */
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedBigInteger('lot_key')->default(0);
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->decimal('reserved', 14, 3)->default(0);
            $table->timestamp('low_stock_alerted_at')->nullable();   // de-duplicates low-stock alerts
            $table->timestamps();

            $table->unique(['item_id', 'branch_id', 'lot_key']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('consumed', 14, 3)->default(0);
            $table->enum('status', ['active', 'consumed', 'released'])->default('active');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('reference', 120)->nullable();            // e.g. "Mrs Eze — wedding gown"
            $table->date('needed_by')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_id', 'status']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();   // deliver to
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'partially_received', 'received', 'cancelled', 'closed'])->default('draft');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('supplier_reference', 60)->nullable();    // delivery note / waybill no.
            $table->timestamp('received_at');
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
        });

        /* Adjustments, wastage and stock counts share one approvable document. */
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->enum('kind', ['adjustment', 'count', 'opening', 'wastage', 'customer_material']);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->string('reason', 255)->nullable();
            $table->decimal('value_change', 14, 2)->default(0);      // signed value of the change at average cost
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->decimal('expected_quantity', 14, 3)->nullable();  // counts: system quantity when counted
            $table->decimal('counted_quantity', 14, 3)->nullable();   // counts: what was found
            $table->decimal('quantity_change', 14, 3)->default(0);    // signed
            $table->decimal('unit_cost', 14, 4)->nullable();          // opening / customer material
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('job_reference', 120);                    // which garment / job
            $table->foreignId('issued_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('material_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('material_issue_id')->constrained('material_issues')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('returned_quantity', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'material_issue_lines', 'material_issues', 'stock_adjustment_lines', 'stock_adjustments',
            'stock_transfer_lines', 'stock_transfers', 'goods_receipt_lines', 'goods_receipts',
            'purchase_order_lines', 'purchase_orders', 'stock_reservations', 'stock_balances',
            'stock_movements', 'stock_lots', 'inventory_items', 'suppliers',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
