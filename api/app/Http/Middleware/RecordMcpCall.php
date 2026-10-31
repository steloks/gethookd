<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes on the key when a request comes through the MCP server (it sends X-Client: mcp), so the
 * developer page can show that the user's AI tool is connected. Any authenticated request counts,
 * free and refused ones included: the point is that the call arrived.
 *
 * The header is self-declared, so this is used only for that check and for attribution, never for
 * billing. It is written at most once a minute per key, to keep a write off most requests.
 */
class RecordMcpCall
{
    public const RESOLUTION_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($request->header('X-Client') === 'mcp' && $token instanceof PersonalAccessToken) {
            $now = now();
            PersonalAccessToken::query()
                ->whereKey($token->getKey())
                ->where(fn ($query) => $query->whereNull('last_mcp_call_at')->orWhere('last_mcp_call_at', '<=', $now->copy()->subSeconds(self::RESOLUTION_SECONDS)))
                ->update(['last_mcp_call_at' => $now]);
        }

        return $next($request);
    }
}
