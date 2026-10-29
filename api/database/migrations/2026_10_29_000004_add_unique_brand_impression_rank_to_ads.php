<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * impression_rank is a position in one brand's list, so two ads of the same brand can never
     * share a rank. Existing ranks are never rewritten (a rewrite would move ads under a live
     * impressions cursor); new ads get NULL or the brand's current max + 1.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX ads_brand_impression_rank_unique ON ads (brand_id, impression_rank) WHERE impression_rank IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ads_brand_impression_rank_unique');
    }
};
