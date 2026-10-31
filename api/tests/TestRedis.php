<?php

namespace Tests;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PredisConnection;
use RuntimeException;

/**
 * Tests empty their Redis database before each test, so they must only ever reach database 15.
 * Checks the live connection, not the config: a REDIS_URL ending in /0 overrides the configured
 * database when the connection is made, and the config would still say 15.
 */
final class TestRedis
{
    public const DATABASE = 15;

    public static function assertSafe(Connection $connection): void
    {
        $database = $connection instanceof PredisConnection
            ? $connection->client()->getConnection()->getParameters()->database
            : null;

        if ((string) $database !== (string) self::DATABASE) {
            throw new RuntimeException('Tests must use Redis database '.self::DATABASE.', not '.var_export($database, true).'; refusing to flush it.');
        }
    }
}
