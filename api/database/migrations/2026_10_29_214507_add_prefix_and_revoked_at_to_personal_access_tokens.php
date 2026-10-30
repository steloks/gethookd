<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // The visible, non-secret start of the key (e.g. "ghk_ab12CD34"), so users can tell keys apart.
            $table->string('prefix', 16)->nullable()->after('name');
            // Revoked keys are kept, not deleted, so the key list still shows them.
            $table->timestamp('revoked_at')->nullable()->after('last_used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['prefix', 'revoked_at']);
        });
    }
};
