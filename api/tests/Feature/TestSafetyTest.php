<?php

use Illuminate\Redis\RedisManager;
use Tests\TestRedis;

// The flush before each test must never reach a real Redis database.
function redisConnectionFor(array $overrides)
{
    $config = config('database.redis');
    $config['default'] = [...$config['default'], ...$overrides];

    return (new RedisManager(app(), 'predis', $config))->connection();
}

it('allows flushing the test database', function () {
    TestRedis::assertSafe(redisConnectionFor([]));

    expect(true)->toBeTrue();
});

it('refuses a connection to another database, even when the config says 15', function (array $overrides) {
    expect(fn () => TestRedis::assertSafe(redisConnectionFor($overrides)))->toThrow(RuntimeException::class, 'refusing to flush');
})->with([
    'REDIS_URL pointing at database 0' => [['url' => 'redis://127.0.0.1:56379/0', 'database' => '15']],
    'database 0' => [['database' => '0']],
]);
