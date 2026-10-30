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
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case InvalidParameters = 'invalid_parameters';
    case RateLimited = 'rate_limited';
    case InternalError = 'internal_error';
    case Unavailable = 'unavailable';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated => 401,
            self::InsufficientScope => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InvalidParameters => 422,
            self::RateLimited => 429,
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
            self::NotFound => 'There is no such resource.',
            self::MethodNotAllowed => 'This endpoint does not support that HTTP method.',
            self::InvalidParameters => 'Some request parameters are unknown or invalid.',
            self::RateLimited => 'Too many requests with this API key.',
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
            self::NotFound => 'Check the path and id. Endpoints are listed in the v1 OpenAPI spec.',
            self::MethodNotAllowed => 'Use one of the methods in the Allow header.',
            self::InvalidParameters => 'Fix each parameter listed in details (allowed values and likely corrections are given there), then send the request again.',
            self::RateLimited => 'Wait the number of seconds in retry_after (also the Retry-After header), then send the same request again.',
            self::InternalError => 'Retry the same request in a few seconds. If it keeps failing, report it.',
            self::Unavailable => 'Retry the same request in a few seconds.',
        };
    }
}
