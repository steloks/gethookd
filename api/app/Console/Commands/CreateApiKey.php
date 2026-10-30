<?php

namespace App\Console\Commands;

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Support\LocalUser;
use Illuminate\Console\Command;

class CreateApiKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keys:create
        {name : A label to tell this key apart}
        {--scope=* : search:read and/or keys:manage (default: search:read)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an API key for the local user and print it once';

    /**
     * Execute the console command.
     */
    public function handle(ApiKeys $keys): int
    {
        $requested = $this->option('scope') ?: [ApiScope::SearchRead->value];
        $scopes = array_map(fn (string $scope) => ApiScope::tryFrom($scope), $requested);

        if (in_array(null, $scopes, true)) {
            $this->error('Unknown scope. Allowed: '.implode(', ', ApiScope::values()));

            return self::INVALID;
        }

        [$key, $plaintext] = $keys->issue(LocalUser::get(), $this->argument('name'), $scopes);

        $this->info("Created key {$key->prefix} with scopes: ".implode(', ', $key->abilities));
        $this->line($plaintext);
        $this->warn('This is the only time the full key is shown. Store it now.');

        return self::SUCCESS;
    }
}
