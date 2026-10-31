<?php

use App\Auth\ApiKeys;
use App\Enums\ApiScope;
use App\Models\Ad;
use App\Support\LocalUser;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The rate limit and the billed count are exact under concurrency. Each test runs 20 worker
 * processes (tests/Concurrency/worker.php), like 20 PHP-FPM workers: each boots the app, they wait
 * at a barrier, then all send their requests through the HTTP kernel at once, each with its own
 * database and Redis connections. The data must be committed for the workers to see it, so these
 * tests migrate the test database fresh before and after each test instead of using a transaction.
 */

const WORKERS = 20;

/**
 * Sends the requests from WORKERS processes at once (spread round-robin) and returns every response.
 *
 * @param  list<array{0: string, 1: array<string, string>}>  $requests  [uri, headers]
 * @param  (Closure(): void)|null  $beforeGo  runs once every worker is ready, just before they start
 * @return list<array{status: int, billed: ?string, retry_after: ?string, json: mixed}>
 */
function fireTogether(array $requests, ?Closure $beforeGo = null): array
{
    $dir = storage_path('framework/testing/concurrency-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($dir);
    $shares = array_fill(0, WORKERS, []);
    foreach (array_values($requests) as $i => $request) {
        $shares[$i % WORKERS][] = $request;
    }
    foreach ($shares as $worker => $share) {
        file_put_contents("{$dir}/requests.{$worker}.json", json_encode($share));
    }

    try {
        $pool = Process::pool(function (Pool $pool) use ($dir) {
            foreach (range(0, WORKERS - 1) as $worker) {
                $pool->as((string) $worker)->forever()->env([
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'gethookd_test',
                    'DB_URL' => '',
                    'REDIS_DB' => '15',
                    'REDIS_URL' => '',
                    'CACHE_STORE' => 'array',
                ])->command([PHP_BINARY, base_path('tests/Concurrency/worker.php'), "{$dir}/requests.{$worker}.json", $dir, (string) $worker]);
            }
        })->start();

        $deadline = microtime(true) + 30;
        while (count(glob("{$dir}/ready.*")) < WORKERS) {
            if (microtime(true) > $deadline || ! $pool->running()->count()) {
                throw new RuntimeException('The workers did not get ready.');
            }
            usleep(10_000);
        }
        $beforeGo?->__invoke();
        touch("{$dir}/go");

        $responses = [];
        foreach ($pool->wait() as $worker => $result) {
            if (! $result->successful()) {
                throw new RuntimeException("Worker {$worker} failed: ".$result->errorOutput().$result->output());
            }
            $responses = [...$responses, ...json_decode($result->output(), true)];
        }

        return $responses;
    } finally {
        File::deleteDirectory($dir);
    }
}

/**
 * @param  list<array{status: int}>  $responses
 * @return array<int, int> count per status
 */
function statuses(array $responses): array
{
    $counts = array_count_values(array_column($responses, 'status'));
    ksort($counts);

    return $counts;
}

function concurrencyKey(): string
{
    return app(ApiKeys::class)->issue(LocalUser::get(), 'concurrency', [ApiScope::SearchRead])[1];
}

beforeEach(function () {
    $this->artisan('migrate:fresh');
    Ad::factory()->count(5)->create();
});

afterEach(function () {
    $this->artisan('migrate:fresh');
});

it('lets exactly 60 of 100 concurrent requests from one key through, across v0 and v1', function () {
    $key = concurrencyKey();

    $responses = fireTogether(
        array_map(fn (int $i) => $i % 2 ? ['/api/v0/ads?api_key='.urlencode($key), []] : ['/api/v1/niches', ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json']], range(0, 99)),
        // The limit is per clock minute: start early enough in a minute that the burst can't straddle two.
        function () {
            if (time() % 60 > 50) {
                usleep((int) ((61 - time() % 60) * 1_000_000));
            }
        },
    );

    $retryAfter = array_map('intval', array_filter(array_column($responses, 'retry_after')));
    expect(statuses($responses))->toBe([200 => 60, 429 => 40])
        ->and($retryAfter)->toHaveCount(40)
        ->and(min($retryAfter))->toBeGreaterThanOrEqual(1)
        ->and(max($retryAfter))->toBeLessThanOrEqual(60);
});

it('bills exactly the billable calls among concurrent requests', function () {
    $key = concurrencyKey();
    $bearer = ['Authorization' => "Bearer {$key}", 'Accept' => 'application/json'];
    $requests = [
        ...array_fill(0, 20, ['/api/v1/ads?limit=1', $bearer]),
        ...array_fill(0, 10, ['/api/v1/ads?limit=2', $bearer + ['Idempotency-Key' => 'retry-1']]),
        ...array_fill(0, 5, ['/api/v0/ads?api_key='.urlencode($key), []]),
        ...array_fill(0, 5, ['/api/v1/niches', $bearer]),
        ...array_fill(0, 5, ['/api/v1/ads?nich=skincare', $bearer]),
    ];
    shuffle($requests);

    $responses = fireTogether($requests);

    expect(statuses($responses))->toBe([200 => 40, 422 => 5])
        // 20 searches + 1 for the 10 repeats of one Idempotency-Key + 5 v0 searches.
        ->and(DB::table('billable_calls')->count())->toBe(26)
        ->and(array_count_values(array_filter(array_column($responses, 'billed'))))->toEqual(['true' => 26, 'false' => 9])
        ->and(DB::table('billable_calls')->where('idempotency_key', 'retry-1')->count())->toBe(1);
});

it('stops exactly at the monthly quota when concurrent calls reach it', function () {
    $keys = [concurrencyKey(), concurrencyKey()];
    $user = LocalUser::get();
    DB::table('billable_calls')->insert(array_fill(0, 995, [
        'user_id' => $user->id, 'token_id' => 1, 'endpoint' => 'v1.ads.search', 'client' => 'rest', 'idempotency_key' => null, 'created_at' => now(),
    ]));

    $responses = fireTogether(array_map(
        fn (int $i) => ['/api/v1/ads?limit=1', ['Authorization' => 'Bearer '.$keys[$i % 2], 'Accept' => 'application/json']],
        range(0, 19),
    ));

    expect(statuses($responses))->toBe([200 => 5, 429 => 15])
        ->and(array_count_values(array_filter(array_map(fn (array $r) => $r['json']['error']['code'] ?? null, $responses))))->toBe(['quota_exceeded' => 15])
        ->and(DB::table('billable_calls')->count())->toBe(1000);
});

it('bills one Idempotency-Key once even when sent concurrently with different requests', function () {
    $bearer = ['Authorization' => 'Bearer '.concurrencyKey(), 'Accept' => 'application/json', 'Idempotency-Key' => 'shared'];
    $requests = [...array_fill(0, 10, ['/api/v1/ads?limit=1', $bearer]), ...array_fill(0, 10, ['/api/v1/ads?limit=2', $bearer])];
    shuffle($requests);

    $responses = fireTogether($requests);

    $ok = array_values(array_filter($responses, fn (array $r) => $r['status'] === 200));
    $reused = array_filter($responses, fn (array $r) => ($r['json']['error']['code'] ?? null) === 'idempotency_key_reused');
    expect(DB::table('billable_calls')->count())->toBe(1)
        // Every success repeats the one request the key was billed for; every other request is refused.
        ->and(array_unique(array_map(fn (array $r) => $r['json']['limit'], $ok)))->toHaveCount(1)
        ->and(count($ok) + count($reused))->toBe(20)
        ->and(count(array_filter($ok, fn (array $r) => $r['billed'] === 'true')))->toBe(1);
});
