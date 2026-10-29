<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands');
            $table->unsignedSmallInteger('niche_id');
            $table->foreign('niche_id')->references('id')->on('niches');
            $table->string('headline');
            $table->text('body_text');
            $table->string('format', 16);
            $table->string('status', 16);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('impression_rank')->nullable();
            $table->jsonb('countries');
            $table->timestampTz('discovered_at');

            $table->index('brand_id');
            $table->index(['start_date', 'id']);
            $table->index(['niche_id', 'start_date', 'id']);
        });

        // The spec's invariants live in the database so seed, ingest and tests cannot drift from them.
        DB::statement("ALTER TABLE ads ADD CONSTRAINT ads_format_check CHECK (format IN ('image', 'video', 'carousel'))");
        DB::statement("ALTER TABLE ads ADD CONSTRAINT ads_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE ads ADD CONSTRAINT ads_end_date_iff_inactive CHECK ((status = 'active') = (end_date IS NULL))");
        DB::statement('ALTER TABLE ads ADD CONSTRAINT ads_end_date_after_start CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE ads ADD CONSTRAINT ads_impression_rank_positive CHECK (impression_rank IS NULL OR impression_rank >= 1)');
        DB::statement("ALTER TABLE ads ADD CONSTRAINT ads_countries_array CHECK (jsonb_typeof(countries) = 'array')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
