<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox for customer messages (order confirmed, ready, payment received, balance reminders,
 * fitting reminders, appointments, campaigns…). Each row is sent right after the page that
 * created it responds, and the scheduler retries anything still waiting. dedupe_key stops the
 * same update going twice (e.g. "order ready" for one order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('purpose', 40);                        // order_confirmed, order_ready, payment_received, balance_reminder, …
            $table->nullableMorphs('related');
            $table->string('subject', 190);
            $table->text('body');
            $table->json('channels');                             // in order of preference
            $table->boolean('first_only')->default(true);         // stop after the first channel that works
            $table->string('status', 20)->default('queued');      // queued, sending, sent, failed, cancelled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('result', 255)->nullable();            // "WhatsApp" or why it failed
            $table->string('dedupe_key', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['status', 'send_after']);
            $table->index(['tenant_id', 'related_type', 'related_id']);
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->foreignId('outgoing_message_id')->nullable()->after('purpose')->constrained('outgoing_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outgoing_message_id');
        });
        Schema::dropIfExists('outgoing_messages');
    }
};
