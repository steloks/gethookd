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
        // The one meter: one row per billable call. Billing, the quota and the developer page all count these rows.
        Schema::create('billable_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('token_id')->constrained('personal_access_tokens');
            $table->string('endpoint', 32);
            $table->string('client', 8);
            $table->string('idempotency_key', 255)->nullable();
            $table->timestampTz('created_at');

            // The quota counts an account's calls in the current month.
            $table->index(['user_id', 'created_at']);
        });

        // Idempotency-Keys that have been billed, per API key, until they expire (24 hours).
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('personal_access_tokens');
            $table->string('key', 255);
            // SHA-256 of method, path and query string: the key is only valid for that request.
            $table->char('fingerprint', 64);
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');

            $table->unique(['token_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('billable_calls');
    }
};
