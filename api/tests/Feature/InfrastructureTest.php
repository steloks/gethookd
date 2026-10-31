<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('runs the test suite against the Postgres test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('gethookd_test')
        ->and(DB::selectOne('select 1 as ok')->ok)->toBe(1);
});

it('runs as the testing environment, whatever the shell or container sets', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('logging.default'))->toBe('single');
});

it('can reach Redis', function () {
    Cache::store('redis')->put('infra-check', 'ok', 5);

    expect(Cache::store('redis')->get('infra-check'))->toBe('ok');
});

it('runs every database session in UTC', function () {
    expect(config('database.connections.pgsql.timezone'))->toBe('UTC')
        ->and(DB::selectOne('SHOW timezone')->TimeZone)->toBe('UTC');
});
