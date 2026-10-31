<?php

use App\Support\Errors\ApiError;
use App\Support\Errors\ApiErrorRenderer;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // There is no login page: an unauthenticated API call is an auth error, never a redirect.
        // Without this, Laravel resolves route('login') for clients that don't send Accept: JSON and
        // every bad key ends in a reported RouteNotFoundException instead of an AuthenticationException.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'developer/*') || $request->expectsJson(),
        );

        // A thrown ApiError is a client error the response already explains, not something to log.
        $exceptions->dontReport(ApiError::class);

        // v0 contract: every error, of any kind, is 400 {"error":"Invalid request"}.
        // Exceptions are still reported as usual; only the response is fixed.
        // The one exception is the rate limit, which the assessment requires on v0 too: a 429 with
        // Retry-After, keeping v0's body (see README, Assumptions).
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/v0', 'api/v0/*')) {
                return $e instanceof ApiError && $e->errorCode === ErrorCode::RateLimited
                    ? response()->json(['error' => 'Invalid request'], 429, $e->headers)
                    : response()->json(['error' => 'Invalid request'], 400);
            }

            // Every other API path (v1, and unknown ones such as /api/v2) uses the one error shape,
            // and so do the developer page's JSON endpoints.
            if ($request->is('api/*', 'developer/*')) {
                return ApiErrorRenderer::toApiError($e, $request)->toResponse();
            }
        });
    })->create();
