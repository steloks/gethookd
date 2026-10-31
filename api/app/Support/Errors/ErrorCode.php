<?php

namespace App\Support\Errors;

/**
 * Every error code the API can return (v1, and through it the MCP server). One place, so the
 * status, retry advice and default wording of a code can never differ between endpoints.
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case Unauthenticated = 'unauthenticated';
    case InsufficientScope = 'insufficient_scope';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case InvalidParameters = 'invalid_parameters';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case RateLimited = 'rate_limited';
    case QuotaExceeded = 'quota_exceeded';
    case InternalError = 'internal_error';
    case Unavailable = 'unavailable';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated => 401,
            self::InsufficientScope => 403,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InvalidParameters => 422,
            self::IdempotencyKeyReused => 422,
            self::RateLimited => 429,
            self::QuotaExceeded => 429,
            self::InternalError => 500,
            self::Unavailable => 503,
        };
    }

    /**
     * Whether sending the same request again can succeed without the caller changing anything.
     */
    public function retryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::InternalError, self::Unavailable => true,
            default => false,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be processed as sent.',
            self::Unauthenticated => 'The API key is missing, invalid or revoked.',
            self::InsufficientScope => 'This API key does not have the scope this endpoint needs.',
            self::Forbidden => 'This API key is not allowed to do this.',
            self::NotFound => 'There is no such resource.',
            self::MethodNotAllowed => 'This endpoint does not support that HTTP method.',
            self::InvalidParameters => 'Some request parameters are unknown or invalid.',
            self::IdempotencyKeyReused => 'This Idempotency-Key was already used for a different request.',
            self::RateLimited => 'Too many requests with this API key.',
            self::QuotaExceeded => 'This account has used its monthly quota of billable calls.',
            self::InternalError => 'Something went wrong on our side.',
            self::Unavailable => 'The service is temporarily unavailable.',
        };
    }

    public function nextStep(): string
    {
        return match ($this) {
            self::BadRequest => 'Check the request against the v1 OpenAPI spec and send a corrected one. Retrying it unchanged will not help.',
            self::Unauthenticated => 'Send a valid key as "Authorization: Bearer <key>". Create one on the developer page if needed.',
            self::InsufficientScope => 'Use a key that has the required scope, or create one with it on the developer page.',
            self::Forbidden => 'Do not retry this request with this key; it will be refused again.',
            self::NotFound => 'Check the path and id. Endpoints are listed in the v1 OpenAPI spec.',
            self::MethodNotAllowed => 'Use one of the methods in the Allow header.',
            self::InvalidParameters => 'Fix each parameter listed in details (allowed values and likely corrections are given there), then send the request again.',
            self::IdempotencyKeyReused => 'Send a new Idempotency-Key for a new request. Reuse a key only to retry exactly the same request.',
            self::RateLimited => 'Wait the number of seconds in retry_after (also the Retry-After header), then send the same request again.',
            self::QuotaExceeded => 'Do not retry until resets_at. /api/v1/niches still works, and so do retries of an already-billed Idempotency-Key.',
            self::InternalError => 'Retry the same request in a few seconds. If it keeps failing, report it.',
            self::Unavailable => 'Retry the same request in a few seconds.',
        };
    }
}
