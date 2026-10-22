<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Online store: each fashion house's shop on its website.
 *
 *  - Products get a picture gallery and online fields (slug, badge, featured, SEO, ratings);
 *    variants get a "was" price and a swatch colour.
 *  - Shoppers have their own accounts per house (store_accounts), carts and wishlists.
 *  - A checkout is a shop_order (WEB-…) with a frozen copy of what was bought. Stock is
 *    reserved while it waits; once paid or accepted by staff it becomes a normal confirmed
 *    order (orders.id) with a payment, and the stock is posted to the ledger as sold.
 *  - Shipping zones, coupons, reviews and homepage banners are managed by the house.
 *  - payment_intents also carry Stripe and shop checkouts (customer is known only once the
 *    order is accepted, so customer_id may be empty for a shop checkout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 180)->nullable()->after('name');
            $table->boolean('is_featured')->default(false)->after('show_in_catalogue');
            $table->string('badge', 30)->nullable()->after('is_featured');           // "New", "Hot", "Limited"…
            $table->json('tags')->nullable()->after('badge');
            $table->text('details')->nullable()->after('description');               // fabric & care, fit notes
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->unsignedInteger('sold_count')->default(0);                      // online + till, for "best sellers"
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('compare_at_price', 14, 2)->nullable()->after('price');   // shown crossed out ("was")
            $table->string('colour_hex', 9)->nullable()->after('colour');             // swatch colour
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->string('alt', 150)->nullable();
            $table->string('colour', 40)->nullable();          // shown when this colour is picked
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('store_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('email', 160);
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('marketing_opt_in')->default(false);
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('store_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('store_accounts')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('store_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('store_accounts')->cascadeOnDelete();
            $table->string('label', 40)->nullable();            // Home, Office…
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('line1', 190);
            $table->string('line2', 190)->nullable();
            $table->string('city', 80);
            $table->string('state', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('store_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->foreignId('account_id')->nullable()->constrained('store_accounts')->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'account_id']);
        });

        Schema::create('store_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('store_carts')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['cart_id', 'product_variant_id']);
        });

        Schema::create('store_wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('store_accounts')->cascadeOnDelete();
            $table->string('token', 64)->nullable();            // guests: the cart cookie token
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'account_id']);
            $table->index(['tenant_id', 'token']);
        });

        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);                          // "Lagos", "South-West", "Rest of Nigeria"
            $table->json('states')->nullable();                  // empty = everywhere else
            $table->decimal('fee', 14, 2)->default(0);
            $table->decimal('free_over', 14, 2)->nullable();     // free delivery from this subtotal
            $table->string('eta', 60)->nullable();               // "1–2 working days"
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('description', 190)->nullable();
            $table->enum('type', ['percent', 'fixed', 'free_shipping'])->default('percent');
            $table->decimal('value', 14, 2)->default(0);
            $table->decimal('min_subtotal', 14, 2)->nullable();
            $table->decimal('max_discount', 14, 2)->nullable();  // cap for percentage coupons
            $table->unsignedInteger('usage_limit')->nullable();  // in total
            $table->unsignedInteger('usage_per_customer')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 40);
            $table->string('token', 64)->unique();               // the customer's private link to this order
            $table->string('source', 20)->default('web');        // web | whatsapp | staff
            $table->foreignId('account_id')->nullable()->constrained('store_accounts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();   // the confirmed order it became
            $table->string('status', 30)->default('awaiting_confirmation');
            $table->string('payment_method', 30);                // paystack | stripe | bank_transfer | whatsapp | pay_on_delivery
            $table->string('payment_status', 20)->default('unpaid');
            $table->enum('fulfilment', ['delivery', 'pickup'])->default('delivery');
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 30);
            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->foreignId('pickup_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('shipping_zone_id')->nullable()->constrained('shipping_zones')->nullOnDelete();
            $table->string('shipping_label', 120)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->string('coupon_code', 40)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('payment_proof_path')->nullable();     // bank transfer receipt from the customer
            $table->string('payment_reference', 120)->nullable(); // what the customer says they paid with
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->string('courier', 80)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->timestamp('expires_at')->nullable();          // stock hold ends (unpaid)
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('whatsapp_notified_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();   // staff-taken orders
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index(['tenant_id', 'account_id']);
        });

        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('name', 190);
            $table->string('variant_label', 120)->nullable();
            $table->string('sku', 60)->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->decimal('compare_at_price', 14, 2)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 14, 2);
            $table->foreignId('reservation_id')->nullable()->constrained('stock_reservations')->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shop_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->string('type', 30);                          // status | payment | note | message
            $table->string('status', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('customer_visible')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->cascadeOnDelete();
            $table->string('email', 160)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['coupon_id', 'shop_order_id']);
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('store_accounts')->nullOnDelete();
            $table->string('name', 80);
            $table->string('email', 160)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->boolean('verified_buyer')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('reply', 1000)->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'product_id', 'status']);
        });

        Schema::create('store_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('placement', ['hero', 'promo'])->default('hero');
            $table->string('eyebrow', 80)->nullable();
            $table->string('title', 120);
            $table->string('subtitle', 200)->nullable();
            $table->string('button_text', 40)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('image_path');
            $table->enum('text_colour', ['light', 'dark'])->default('light');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->foreignId('shop_order_id')->nullable()->after('invoice_id')->constrained('shop_orders')->nullOnDelete();
            $table->string('provider_reference', 191)->nullable()->after('reference');   // e.g. Stripe checkout session id
        });
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
        });

        $this->backfill();
    }

    /** Slugs for existing products; the shop feature for plans that have a website; new permissions for existing houses. */
    protected function backfill(): void
    {
        $seen = [];
        foreach (DB::table('products')->orderBy('id')->get(['id', 'tenant_id', 'name']) as $p) {
            $base = Str::slug($p->name) ?: 'product';
            $slug = $base;
            $i = 2;
            while (isset($seen[$p->tenant_id][$slug])) {
                $slug = $base . '-' . $i++;
            }
            $seen[$p->tenant_id][$slug] = true;
            DB::table('products')->where('id', $p->id)->update(['slug' => $slug]);
        }

        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            if (in_array('website', $features, true) && ! in_array('online_store', $features, true)) {
                $features[] = 'online_store';
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode(array_values($features))]);
            }
        }

        if (DB::table('tenants')->exists()) {
            rescue(function () {
                \App\Services\Tenancy\TenantProvisioner::syncPermissionCatalog();
                $provisioner = app(\App\Services\Tenancy\TenantProvisioner::class);
                \App\Models\Tenant::query()->each(fn ($t) => $provisioner->syncDefaultRoles($t));
            }, null, false);
        }
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_order_id');
            $table->dropColumn('provider_reference');
        });
        foreach (['store_banners', 'product_reviews', 'coupon_redemptions', 'shop_order_events', 'shop_order_items', 'shop_orders',
            'coupons', 'shipping_zones', 'store_wishlist_items', 'store_cart_items', 'store_carts', 'store_addresses',
            'store_password_resets', 'store_accounts', 'product_images'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn(['compare_at_price', 'colour_hex']));
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn(['slug', 'is_featured', 'badge', 'tags', 'details', 'seo_title', 'seo_description', 'sold_count', 'rating_avg', 'rating_count']);
        });
    }
};
