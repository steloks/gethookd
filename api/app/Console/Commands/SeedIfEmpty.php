<?php

namespace App\Console\Commands;

use App\Models\Ad;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

class SeedIfEmpty extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:seed-if-empty';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the deterministic seed only if there are no ads yet (make up runs this on every start)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (Ad::query()->exists()) {
            $this->info('Ads already exist; not seeding. Run `make seed` to replace them with the seed data.');

            return self::SUCCESS;
        }

        $this->info('No ads yet; seeding.');

        return $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    }
}
