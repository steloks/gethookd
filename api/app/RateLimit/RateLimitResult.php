<?php

namespace App\RateLimit;

final readonly class RateLimitResult
{
    /**
     * @param  int  $retryAfter  whole seconds until the current window ends (at least 1)
     */
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfter,
    ) {}
}
