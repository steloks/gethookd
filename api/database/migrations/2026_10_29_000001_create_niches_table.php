<?php

use App\Support\NicheCatalog;
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
        Schema::create('niches', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('slug', 64)->unique();
            $table->string('name', 64)->unique();
        });

        // Reference data, not seed data: the 12 niches are part of the spec and
        // must exist wherever the schema exists (v0 resolves names against them).
        $position = 0;
        foreach (NicheCatalog::NICHES as $slug => $niche) {
            DB::table('niches')->insert([
                'id' => ++$position,
                'slug' => $slug,
                'name' => $niche['name'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('niches');
    }
};
