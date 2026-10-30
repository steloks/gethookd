<?php

namespace App\Support\Errors;

/**
 * Every error code the API can return (v1, and through it the MCP server). One place, so the
 * status, retry advice and default wording of a code can never differ between endpoints.
 */
enum ErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case InsufficientScope = 'insufficient_scope';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case InternalError = 'internal_error';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::InsufficientScope => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InternalError => 500,
        };
    }

    /**
     * Whether sending the same request again can succeed without the caller changing anything.
     */
    public function retryable(): bool
    {
        return match ($this) {
            self::InternalError => true,
            default => false,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'The API key is missing, invalid or revoked.',
            self::InsufficientScope => 'This API key does not have the scope this endpoint needs.',
            self::NotFound => 'There is no such resource.',
            self::MethodNotAllowed => 'This endpoint does not support that HTTP method.',
            self::InternalError => 'Something went wrong on our side.',
        };
    }

    public function nextStep(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Send a valid key as "Authorization: Bearer <key>". Create one on the developer page if needed.',
            self::InsufficientScope => 'Use a key that has the required scope, or create one with it on the developer page.',
            self::NotFound => 'Check the path and id. Endpoints are listed in the v1 OpenAPI spec.',
            self::MethodNotAllowed => 'Use one of the methods in the Allow header.',
            self::InternalError => 'Retry the same request in a few seconds. If it keeps failing, report it.',
        };
    }
}
