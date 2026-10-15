<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Styles & ready-made catalogue — SRS FR-MEA-006, FR-POS-007, §7 (fashion_styles,
 * style_versions, style_template_links, product_categories, products, product_variants).
 *
 * Bespoke styles are versioned: a published version (description, technical notes,
 * price, add-ons, lead time, images, linked measurement template versions) is frozen,
 * so an order that used "STY-00012 v3" can always be reproduced.
 * Ready-made products are sellable items with size/colour variants (SKU, barcode,
 * price). Stock quantities arrive with the Inventory module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogue_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('fashion_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->foreignId('category_id')->nullable()->constrained('catalogue_categories')->nullOnDelete();
            $table->enum('audience', ['any', 'female', 'male', 'child'])->default('any');
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->boolean('show_in_catalogue')->default(false);   // visible to customers once published
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('style_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('style_id')->constrained('fashion_styles')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');
            $table->text('description')->nullable();
            $table->text('technical_notes')->nullable();          // construction notes for the workroom
            $table->decimal('base_price', 14, 2)->nullable();
            $table->json('price_options')->nullable();            // [{label, amount}] optional add-ons
            $table->decimal('estimated_cost', 14, 2)->nullable(); // costing — hidden without permission
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('fabric_quantity', 8, 2)->nullable(); // typical fabric need
            $table->string('fabric_unit', 10)->nullable();        // yd, m
            $table->string('fabric_notes', 255)->nullable();
            $table->string('change_notes', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['style_id', 'version_no']);
        });

        Schema::create('style_template_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('style_version_id')->constrained('style_versions')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('measurement_templates')->cascadeOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained('measurement_template_versions')->nullOnDelete(); // pinned on publish
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['style_version_id', 'template_id']);
        });

        Schema::create('style_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('style_version_id')->constrained('style_versions')->cascadeOnDelete();
            $table->string('path');
            $table->string('caption', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);   // first = cover
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->foreignId('category_id')->nullable()->constrained('catalogue_categories')->nullOnDelete();
            $table->foreignId('style_id')->nullable()->constrained('fashion_styles')->nullOnDelete(); // "made from" style
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->boolean('show_in_catalogue')->default(true);
            $table->boolean('track_stock')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 60);
            $table->string('barcode', 40)->nullable();
            $table->string('size', 30)->nullable();
            $table->string('colour', 40)->nullable();
            $table->decimal('price', 14, 2);
            $table->decimal('cost_price', 14, 2)->nullable();
            $table->decimal('reorder_level', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('style_images');
        Schema::dropIfExists('style_template_links');
        Schema::dropIfExists('style_versions');
        Schema::dropIfExists('fashion_styles');
        Schema::dropIfExists('catalogue_categories');
    }
};
