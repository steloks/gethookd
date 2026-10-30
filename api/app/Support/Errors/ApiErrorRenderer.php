<?php

namespace App\Support\Errors;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any exception raised by a non-v0 API request into an ApiError.
 *
 * Laravel has already converted some exceptions by the time they get here (a missing Sanctum
 * ability arrives as AccessDeniedHttpException, a missing model as NotFoundHttpException).
 * Anything unrecognised becomes internal_error, whose body never includes the exception's
 * message, so SQL, paths and secrets cannot leak.
 */
final class ApiErrorRenderer
{
    public static function toApiError(Throwable $e, Request $request): ApiError
    {
        return match (true) {
            $e instanceof ApiError => $e,
            $e instanceof AuthenticationException => self::unauthenticated($request),
            $e instanceof AccessDeniedHttpException && $e->getPrevious() instanceof MissingAbilityException => new ApiError(
                ErrorCode::InsufficientScope,
                'This API key is missing the scope: '.implode(', ', $e->getPrevious()->abilities()).'.',
            ),
            $e instanceof NotFoundHttpException => new ApiError(
                ErrorCode::NotFound,
                "There is no {$request->method()} ".SafeText::path($request).'.',
            ),
            $e instanceof MethodNotAllowedHttpException => new ApiError(
                ErrorCode::MethodNotAllowed,
                SafeText::path($request)." does not support {$request->method()}.",
                headers: ['Allow' => $e->getHeaders()['Allow']],
            ),
            $e instanceof HttpExceptionInterface => self::byStatus($e),
            default => new ApiError(ErrorCode::InternalError),
        };
    }

    /**
     * Any other HTTP exception (from the framework or a middleware) keeps its meaning: a client
     * error stays a non-retryable client error, and only overload or downtime says "retry".
     * Its message is not used, as it was not written for API callers.
     */
    private static function byStatus(HttpExceptionInterface $e): ApiError
    {
        $status = $e->getStatusCode();
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
        $retryAfter = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null;
        if ($status === 429) {
            // A rate-limited caller must always be told how long to wait.
            $retryAfter ??= 1;
        }
        $headers = $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter];

        return match (true) {
            $status === 429 => new ApiError(ErrorCode::RateLimited, retryAfter: $retryAfter, headers: $headers),
            $status === 503 => new ApiError(ErrorCode::Unavailable, retryAfter: $retryAfter, headers: $headers),
            $status >= 400 && $status < 500 => new ApiError(ErrorCode::BadRequest),
            default => new ApiError(ErrorCode::InternalError),
        };
    }

    /**
     * Says which of the three cases applies, so an agent knows what to fix. Never echoes the key.
     */
    private static function unauthenticated(Request $request): ApiError
    {
        $headers = ['WWW-Authenticate' => 'Bearer'];

        if ($request->bearerToken() !== null) {
            return new ApiError(
                ErrorCode::Unauthenticated,
                'The API key is invalid or has been revoked.',
                'Check that the whole key was copied, or create a new key on the developer page. Retrying with the same key will not help.',
                headers: $headers,
            );
        }

        if ($request->query->has('api_key')) {
            return new ApiError(
                ErrorCode::Unauthenticated,
                'v1 does not read the api_key query parameter.',
                'Send the same key as "Authorization: Bearer <key>" and remove api_key from the URL.',
                headers: $headers,
            );
        }

        return new ApiError(ErrorCode::Unauthenticated, 'No API key was sent.', headers: $headers);
    }
}
