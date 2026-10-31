<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limit counters live in Redis, outside the database transaction each test rolls back,
        // and key ids restart with every fresh migration. Tests use their own Redis database
        // (phpunit.xml), emptied before each test, so counts never carry over.
        TestRedis::assertSafe(Redis::connection());
        Redis::connection()->flushdb();
    }
}
