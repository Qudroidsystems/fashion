<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the system create follow-ups automatically without duplicates
 * (e.g. one "review measurement changes" task per session — SRS scenario 34).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->string('source_key', 100)->nullable()->after('status');
            $table->unique(['tenant_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'source_key']);
            $table->dropColumn('source_key');
        });
    }
};
