<?php

namespace App\Http\Middleware;

use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The developer page has no login (one hard-coded local user), so it only answers requests
 * addressed to this machine by name. A page on another site that points its own hostname at
 * 127.0.0.1 (DNS rebinding) sends its own Host header and is refused.
 */
class LocalHostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('app.developer_hosts'))));

        if (! in_array(strtolower($request->getHost()), $allowed, true)) {
            throw new ApiError(
                ErrorCode::Forbidden,
                'The developer page only answers on this machine.',
                'Open it at http://localhost:8000/developer. To allow another host name, add it to DEVELOPER_HOSTS.',
            );
        }

        return $next($request);
    }
}
