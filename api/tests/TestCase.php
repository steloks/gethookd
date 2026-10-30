<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limit counters live in Redis, outside the database transaction each test rolls back,
        // and key ids restart with every fresh migration. Tests use their own Redis database
        // (phpunit.xml), emptied before each test, so counts never carry over.
        if ((string) config('database.redis.default.database') !== '15') {
            throw new RuntimeException('Tests must use Redis database 15; refusing to flush another one.');
        }
        Redis::connection()->flushdb();
    }
}
