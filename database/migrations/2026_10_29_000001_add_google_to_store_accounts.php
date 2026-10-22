<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shoppers can sign up / sign in to a house's shop with Google. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_accounts', function (Blueprint $table) {
            $table->string('google_id', 64)->nullable()->after('phone');
            $table->string('avatar_url', 500)->nullable()->after('google_id');
            $table->timestamp('email_verified_at')->nullable()->after('avatar_url');
            $table->unique(['tenant_id', 'google_id']);
        });
    }

    public function down(): void
    {
        Schema::table('store_accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'google_id']);
            $table->dropColumn(['google_id', 'avatar_url', 'email_verified_at']);
        });
    }
};
