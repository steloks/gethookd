<?php

namespace App\Http\Middleware;

use App\Billing\BillableCall;
use App\Billing\Meter;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\ParameterIssue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the billable-call rule (README, "Billing") to one billable endpoint, named in the route:
 * ->middleware(MeterBillableCall::class.':v1.ads.search').
 *
 * Before the endpoint runs: a used Idempotency-Key must belong to this same request, and an account
 * over its quota is stopped (a repeat of a billed key costs nothing, so it is let through).
 * After: a GET that produced a 2xx is billed, unless it repeats a billed key.
 */
class MeterBillableCall
{
    private const KEY_FORMAT = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(private readonly Meter $meter) {}

    public function handle(Request $request, Closure $next, string $endpoint): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $now = now();
        $token = $request->user()->currentAccessToken();
        $idempotencyKey = $this->idempotencyKey($request);
        $fingerprint = hash('sha256', $request->method()."\n".$request->path()."\n".$request->server('QUERY_STRING', ''));

        $billedFor = $idempotencyKey === null ? null : $this->meter->billedFingerprint($token->getKey(), $idempotencyKey, $now);
        if ($billedFor !== null && $billedFor !== $fingerprint) {
            throw new ApiError(ErrorCode::IdempotencyKeyReused);
        }
        $repeat = $billedFor !== null;

        if (! $repeat && $this->meter->usage($request->user()->getKey(), $now) >= Meter::MONTHLY_QUOTA) {
            throw Meter::quotaExceeded($now);
        }

        $response = $next($request);
        if (! $response->isSuccessful()) {
            return $response;
        }

        $billed = ! $repeat && $this->meter->bill(new BillableCall(
            userId: $request->user()->getKey(),
            tokenId: $token->getKey(),
            endpoint: $endpoint,
            client: $request->header('X-Client') === 'mcp' ? 'mcp' : 'rest',
            idempotencyKey: $idempotencyKey,
            fingerprint: $fingerprint,
        ), $now);

        $response->headers->set('Billed', $billed ? 'true' : 'false');

        return $response;
    }

    /**
     * The Idempotency-Key header, or null if none was sent. An invalid one is a 422 on v1 and
     * ignored on v0, which ignores invalid input.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $values = $request->headers->all('idempotency-key');
        if ($values === []) {
            return null;
        }

        if (count($values) === 1 && preg_match(self::KEY_FORMAT, (string) $values[0]) === 1) {
            return $values[0];
        }

        if ($request->is('api/v0', 'api/v0/*')) {
            return null;
        }

        throw new ApiError(
            ErrorCode::InvalidParameters,
            '1 problem with the request: the Idempotency-Key header.',
            'Send one Idempotency-Key header of 1 to 255 visible ASCII characters (for example a UUID), or leave it out.',
            details: [[
                'param' => 'Idempotency-Key',
                'issue' => count($values) > 1 ? ParameterIssue::RepeatedParameter->value : ParameterIssue::InvalidValue->value,
                'message' => count($values) > 1 ? 'Idempotency-Key was sent more than once.' : 'Idempotency-Key must be 1 to 255 visible ASCII characters, without spaces.',
            ]],
        );
    }
}
