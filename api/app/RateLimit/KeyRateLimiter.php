<?php

namespace App\RateLimit;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Redis;

/**
 * The per-key rate limit shared by v0, v1 and (through v1) the MCP server: at most LIMIT requests
 * per API key in each clock minute (12:00:00–12:00:59, 12:01:00–…).
 *
 * Fixed windows: one Redis counter per key and minute, incremented and given its expiry in one
 * Lua script, so concurrent requests can never both take the last slot. The trade-off: a caller
 * can send LIMIT requests at the end of one minute and LIMIT more at the start of the next.
 */
final class KeyRateLimiter
{
    public const LIMIT = 60;

    public const WINDOW_SECONDS = 60;

    /** INCR, and on the first hit set the expiry, as one step. */
    private const SCRIPT = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        if count == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return count
        LUA;

    public function hit(int $keyId, CarbonInterface $now): RateLimitResult
    {
        $time = (float) $now->format('U.u');
        $windowStart = intdiv((int) floor($time), self::WINDOW_SECONDS) * self::WINDOW_SECONDS;

        // The counter outlives its window a little, so a slow request can't recreate it without an expiry.
        $count = (int) Redis::connection()->eval(self::SCRIPT, 1, "rate-limit:{$keyId}:{$windowStart}", self::WINDOW_SECONDS * 2);

        return new RateLimitResult(
            allowed: $count <= self::LIMIT,
            remaining: max(0, self::LIMIT - $count),
            retryAfter: max(1, (int) ceil($windowStart + self::WINDOW_SECONDS - $time)),
        );
    }
}
