<?php

namespace App\Http\Middleware;

use App\Search\QueryString;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\SafeText;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For v1 endpoints that take no query parameters: any parameter is rejected with the same 422
 * the search endpoint uses, instead of being silently ignored (the v0 problem), so a caller
 * that thinks ?fields=… or ?niche=… did something finds out.
 */
class RejectQueryParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        $names = array_values(array_unique(array_column(QueryString::pairs((string) $request->server('QUERY_STRING', '')), 0)));

        if ($names === []) {
            return $next($request);
        }

        $path = SafeText::path($request);
        $shown = array_map(fn (string $name) => SafeText::of($name), $names);
        $count = count($names);

        throw new ApiError(
            ErrorCode::InvalidParameters,
            ($count === 1 ? '1 problem' : "{$count} problems").' with the parameters: '.implode(', ', $shown).". {$path} takes no query parameters.",
            "Send instead: {$request->method()} {$path}",
            details: array_map(fn (string $name) => [
                'param' => $name,
                'issue' => 'unknown_parameter',
                'message' => "\"{$name}\" is not a parameter of {$path}, which takes none.",
                'allowed' => [],
            ], $shown),
        );
    }
}
