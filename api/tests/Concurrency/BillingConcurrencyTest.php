<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Support\LocalUser;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
 * The billed count is exact under concurrency. These tests start PHP's built-in server with 8
 * workers on the test database and fire requests in parallel, so requests really overlap, each in
 * its own process and database connection, as in production.
 */

/**
 * @param  list<array{0: string, 1: string, 2: array<string, string>}>  $requests  [method, path, headers]
 * @return list<Response>
 */
function fireTogether(string $base, array $requests): array
{
    return Http::pool(fn (Pool $pool) => array_map(
        fn (array $r) => $pool->withHeaders($r[2])->timeout(30)->send($r[0], $base.$r[1]),
        $requests,
    ));
}

function concurrencyKey(): string
{
    return app(ApiKeys::class)->issue(LocalUser::get(), 'concurrency', [ApiScope::SearchRead])[1];
}

beforeEach(function () {
    $this->artisan('migrate:fresh');
    Ad::factory()->count(5)->create();

    $port = random_int(20000, 29999);
    $this->base = "http://127.0.0.1:{$port}";
    // Laravel's router script expects to run from public/, as `artisan serve` does.
    $this->server = Process::path(public_path())
        ->env([
            'PHP_CLI_SERVER_WORKERS' => '8',
            'APP_ENV' => 'testing',
            'DB_DATABASE' => 'gethookd_test',
            'DB_URL' => '',
            'REDIS_DB' => '15',
            'CACHE_STORE' => 'array',
        ])
        ->start(['php', '-S', "127.0.0.1:{$port}", base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')]);

    $deadline = microtime(true) + 10;
    while (true) {
        try {
            if (Http::timeout(1)->get("{$this->base}/api/v1/niches")->status() === 401) {
                break;
            }
        } catch (Throwable) {
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The test server did not start: '.$this->server->latestErrorOutput());
        }
        usleep(100_000);
    }
});

afterEach(function () {
    // The workers outlive the main server process unless stopped themselves.
    Process::run(['pkill', '-TERM', '-P', (string) $this->server->id()]);
    $this->server->signal(SIGTERM);
    $this->server->wait();
    $this->artisan('migrate:fresh');
});

it('bills exactly the billable calls among concurrent requests', function () {
    $key = concurrencyKey();
    $bearer = ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json'];
    $requests = [
        ...array_fill(0, 20, ['GET', '/api/v1/ads?limit=1', $bearer]),
        ...array_fill(0, 10, ['GET', '/api/v1/ads?limit=2', $bearer + ['Idempotency-Key' => 'retry-1']]),
        ...array_fill(0, 5, ['GET', '/api/v0/ads?api_key='.urlencode($key), []]),
        ...array_fill(0, 5, ['GET', '/api/v1/niches', $bearer]),
        ...array_fill(0, 5, ['GET', '/api/v1/ads?nich=skincare', $bearer]),
    ];
    shuffle($requests);

    $responses = fireTogether($this->base, $requests);

    $statuses = array_count_values(array_map(fn (Response $r) => $r->status(), $responses));
    $billedHeaders = array_count_values(array_filter(array_map(fn (Response $r) => $r->header('Billed'), $responses)));
    expect($statuses)->toEqual([200 => 40, 422 => 5])
        // 20 searches + 1 for the 10 repeats of one Idempotency-Key + 5 v0 searches.
        ->and(DB::table('billable_calls')->count())->toBe(26)
        ->and($billedHeaders)->toEqual(['true' => 26, 'false' => 9])
        ->and(DB::table('billable_calls')->where('idempotency_key', 'retry-1')->count())->toBe(1);
});

it('stops exactly at the monthly quota when concurrent calls reach it', function () {
    $keys = [concurrencyKey(), concurrencyKey()];
    $user = LocalUser::get();
    DB::table('billable_calls')->insert(array_fill(0, 995, [
        'user_id' => $user->id, 'token_id' => 1, 'endpoint' => 'v1.ads.search', 'client' => 'rest', 'idempotency_key' => null, 'created_at' => now(),
    ]));

    $responses = fireTogether($this->base, array_map(
        fn (int $i) => ['GET', '/api/v1/ads?limit=1', ['Authorization' => 'Bearer '.$keys[$i % 2], 'Accept' => 'application/json']],
        range(0, 19),
    ));

    $statuses = array_count_values(array_map(fn (Response $r) => $r->status(), $responses));
    $codes = array_count_values(array_filter(array_map(fn (Response $r) => $r->json('error.code'), $responses)));
    expect($statuses)->toEqual([200 => 5, 429 => 15])
        ->and($codes)->toEqual(['quota_exceeded' => 15])
        ->and(DB::table('billable_calls')->count())->toBe(1000);
});

it('bills one Idempotency-Key once even when sent concurrently with different requests', function () {
    $bearer = ['Authorization' => 'Bearer '.concurrencyKey(), 'Accept' => 'application/json', 'Idempotency-Key' => 'shared'];
    $requests = [...array_fill(0, 6, ['GET', '/api/v1/ads?limit=1', $bearer]), ...array_fill(0, 6, ['GET', '/api/v1/ads?limit=2', $bearer])];
    shuffle($requests);

    $responses = fireTogether($this->base, $requests);

    $ok = array_values(array_filter($responses, fn (Response $r) => $r->status() === 200));
    $limits = array_unique(array_map(fn (Response $r) => $r->json('limit'), $ok));
    $reused = array_filter($responses, fn (Response $r) => $r->json('error.code') === 'idempotency_key_reused');
    expect(DB::table('billable_calls')->count())->toBe(1)
        // Every success repeats the one request the key was billed for; every other request is refused.
        ->and($limits)->toHaveCount(1)
        ->and(count($ok) + count($reused))->toBe(12)
        ->and(count(array_filter($ok, fn (Response $r) => $r->header('Billed') === 'true')))->toBe(1);
});
