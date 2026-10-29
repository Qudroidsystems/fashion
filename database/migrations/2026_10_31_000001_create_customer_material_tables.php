<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer materials: fabric, lace, buttons or garments a customer leaves with the house
 * (to sew, alter or copy). Each one gets a tag number, photos and a shelf, and is followed
 * until it goes back to the customer (or is used up / written off), with reminders.
 *
 * message_logs: every e-mail, SMS and WhatsApp the platform sends to a customer, so staff can
 * see what was sent, when, and whether it got through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->string('number', 30);                                   // MAT-2026-00012 — written on the tag
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('kind', 30);                                     // fabric, lace, aso_oke, lining, accessories, garment, other
            $table->string('purpose', 30)->default('sew');                  // sew, alteration, repair, copy, other
            $table->string('description', 160);
            $table->string('colour', 60)->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 10)->nullable();                          // yd, m, pcs, set
            $table->text('condition_notes')->nullable();                    // stains, tears, what came with it
            $table->json('photos')->nullable();
            $table->string('location', 120)->nullable();                    // shelf / bin / with whom

            $table->string('status', 20)->default('held');                  // held, in_use, returned, used, written_off
            $table->timestamp('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('collect_by')->nullable();                         // date agreed with the customer

            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('returned_to', 120)->nullable();
            $table->string('returned_to_phone', 30)->nullable();
            $table->decimal('returned_quantity', 10, 2)->nullable();        // leftover handed back
            $table->string('return_note', 255)->nullable();
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();

            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id', 'status']);
        });

        Schema::create('customer_material_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_material_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);                                     // received, moved, in_use, back_in_store, returned, used, written_off, reminded, note
            $table->string('detail', 255)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_material_id', 'created_at']);
        });

        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 20);                                   // email, sms, whatsapp
            $table->string('to', 190)->nullable();
            $table->string('subject', 190)->nullable();
            $table->text('body');
            $table->string('status', 20);                                    // sent, failed, skipped
            $table->string('error', 255)->nullable();
            $table->string('purpose', 40)->nullable();                       // material_reminder, …
            $table->nullableMorphs('related');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete(); // null = automatic
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
        Schema::dropIfExists('customer_material_events');
        Schema::dropIfExists('customer_materials');
    }
};
