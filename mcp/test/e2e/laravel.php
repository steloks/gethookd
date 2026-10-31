<?php

/*
 * The Laravel side of the end-to-end tests: prepares the test database and reads what the API
 * recorded. Runs with the environment the test gives it (gethookd_test, Redis database 15).
 *
 * Usage:
 *   php laravel.php setup <key name>...   fresh schema, 30 ads, one search:read key per name; prints {"name": "ghk_…"}
 *   php laravel.php revoke <prefix>       revokes the key with that visible prefix
 *   php laravel.php billed <prefix>       prints {"total": n, "mcp": n}: billable calls of that key
 */

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Models\PersonalAccessToken;
use App\Support\LocalUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestRedis;

$api = getenv('GETHOOKD_API_DIR');
require "{$api}/vendor/autoload.php";
$app = require "{$api}/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'gethookd_test') {
    fwrite(STDERR, "Refusing to run: the database is not gethookd_test.\n");
    exit(1);
}

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

switch ($command) {
    case 'setup':
        TestRedis::assertSafe(Redis::connection());
        Redis::connection()->flushdb();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Ad::factory()->count(30)->create();

        $keys = [];
        foreach ($args as $name) {
            [, $plaintext] = app(ApiKeys::class)->issue(LocalUser::get(), $name, [ApiScope::SearchRead]);
            $keys[$name] = $plaintext;
        }
        echo json_encode($keys);
        break;

    case 'revoke':
        app(ApiKeys::class)->revoke(PersonalAccessToken::query()->where('prefix', $args[0])->firstOrFail());
        break;

    case 'billed':
        $calls = DB::table('billable_calls')
            ->join('personal_access_tokens', 'personal_access_tokens.id', '=', 'billable_calls.token_id')
            ->where('personal_access_tokens.prefix', $args[0]);
        echo json_encode(['total' => (clone $calls)->count(), 'mcp' => (clone $calls)->where('client', 'mcp')->count()]);
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(1);
}
