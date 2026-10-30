<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Matches the v1 "impressions" sort: impression_rank ASC NULLS LAST, id DESC.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX ads_impressions_sort_index ON ads (impression_rank ASC NULLS LAST, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX ads_impressions_sort_index');
    }
};
