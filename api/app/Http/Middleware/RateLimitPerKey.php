<?php

namespace App\Http\Middleware;

use App\RateLimit\KeyRateLimiter;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs right after authentication, on v0 and v1 alike, so every authenticated request counts
 * against its key, including ones later refused for scope or parameters: the limit is about load,
 * not billing. If Redis is down the request is refused (503) rather than let through unlimited.
 */
class RateLimitPerKey
{
    public function __construct(private readonly KeyRateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (int) $request->user()->currentAccessToken()->getKey();

        try {
            $result = $this->limiter->hit($keyId, now());
        } catch (Throwable $e) {
            report($e);

            throw new ApiError(ErrorCode::Unavailable, 'Rate limiting is unavailable, so the request was not run.', retryAfter: 5, headers: ['Retry-After' => '5']);
        }

        if (! $result->allowed) {
            throw new ApiError(
                ErrorCode::RateLimited,
                'This API key has used its '.KeyRateLimiter::LIMIT.' requests for this minute. The limit is shared by v0, v1 and the MCP server.',
                "Wait {$result->retryAfter} seconds (retry_after), then send the same request again.",
                retryAfter: $result->retryAfter,
                headers: ['Retry-After' => (string) $result->retryAfter],
            );
        }

        return $next($request);
    }
}
