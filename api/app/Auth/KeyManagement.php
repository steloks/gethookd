<?php

namespace App\Auth;

use App\Billing\Meter;
use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Search\Suggest;
use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\ParameterIssue;
use App\Support\Errors\SafeText;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What the developer page and the /api/v1/keys endpoints do with an account's keys: list,
 * create, revoke, and read usage. Usage is counted from billable_calls, the table billing writes
 * (through Meter), so the page can never disagree with the bill.
 */
final class KeyManagement
{
    public const MAX_NAME_LENGTH = 100;

    /** The fields a create request may send. */
    public const CREATE_FIELDS = ['name', 'scopes'];

    public function __construct(
        private readonly ApiKeys $keys,
        private readonly Meter $meter,
    ) {}

    /**
     * The account's keys, newest first, revoked ones included (they are kept, never deleted),
     * each with its billable calls in the current period.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $owner, CarbonInterface $now): array
    {
        $keys = PersonalAccessToken::query()
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', $owner->getKey())
            ->orderByDesc('id')
            ->get();

        [$start, $end] = Meter::period($now);
        $calls = DB::table('billable_calls')
            ->where('user_id', $owner->getKey())
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('token_id')
            ->selectRaw('token_id, count(*) as calls')
            ->pluck('calls', 'token_id');

        return $keys->map(fn (PersonalAccessToken $key) => self::present($key, (int) ($calls[$key->getKey()] ?? 0)))->values()->all();
    }

    /**
     * Creates a key from a request body. Unknown fields and invalid values are rejected, like
     * every other v1 input.
     *
     * @param  list<string>|null  $grantable  the scopes the caller may give the new key (its own,
     *                                        for an API key); null for the account owner (the developer page)
     * @return array{0: array<string, mixed>, 1: string} the key as listed, and its plaintext (shown once)
     *
     * @throws ApiError invalid_parameters, bad_request when the body is not a JSON object, or
     *                  insufficient_scope for a scope the caller doesn't have
     */
    public function create(User $owner, mixed $body, ?array $grantable = null): array
    {
        [$name, $scopes] = $this->parseCreate($body);

        // A key can't hand out more than it has: a keys:manage-only key (say, a provisioning
        // script's) can't mint a key that searches.
        $missing = $grantable === null ? [] : array_values(array_diff(array_map(fn (ApiScope $s) => $s->value, $scopes), $grantable));
        if ($missing !== []) {
            throw new ApiError(
                ErrorCode::InsufficientScope,
                'This API key can only create keys with scopes it has itself ('.implode(', ', $grantable).'); it lacks '.implode(', ', $missing).'.',
                'Send "scopes" with only those scopes, or create the key on the developer page or with a key that has them.',
            );
        }
        [$key, $plaintext] = $this->keys->issue($owner, $name, $scopes);

        return [self::present($key->refresh(), 0), $plaintext];
    }

    /**
     * Revokes one of the account's keys (it stops working on its very next request). A key of
     * another account, or one that doesn't exist, is not_found; revoking twice keeps the first time.
     *
     * @throws ApiError not_found
     */
    public function revoke(User $owner, string $id, CarbonInterface $now): array
    {
        $key = ctype_digit($id) && strlen($id) <= 18 ? PersonalAccessToken::query()
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', $owner->getKey())
            ->find((int) $id) : null;

        if ($key === null) {
            throw new ApiError(
                ErrorCode::NotFound,
                'This account has no key with id "'.SafeText::of($id).'".',
                'List the keys (GET /api/v1/keys) and use the id of the key to revoke.',
            );
        }

        $this->keys->revoke($key);

        return self::present($key, $this->callsOf($key, $now));
    }

    /**
     * The account's billable calls this period against the quota, from the billing table.
     *
     * @return array<string, mixed>
     */
    public function usage(User $owner, CarbonInterface $now): array
    {
        [$start, $end] = Meter::period($now);
        // The total is the meter's own count, the one the quota is enforced with.
        $used = $this->meter->usage($owner->getKey(), $now);
        $byClient = DB::table('billable_calls')
            ->where('user_id', $owner->getKey())
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('client')
            ->selectRaw('client, count(*) as calls')
            ->pluck('calls', 'client');

        return [
            'period_start' => $start->utc()->toIso8601ZuluString(),
            'resets_at' => $end->utc()->toIso8601ZuluString(),
            'billable_calls' => $used,
            'quota' => Meter::MONTHLY_QUOTA,
            'remaining' => max(0, Meter::MONTHLY_QUOTA - $used),
            'by_client' => ['rest' => (int) ($byClient['rest'] ?? 0), 'mcp' => (int) ($byClient['mcp'] ?? 0)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(PersonalAccessToken $key, int $billableCallsThisPeriod): array
    {
        return [
            'id' => $key->getKey(),
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => array_values($key->abilities ?? []),
            'created_at' => $key->created_at?->utc()->toIso8601ZuluString(),
            'last_used_at' => $key->last_used_at?->utc()->toIso8601ZuluString(),
            'revoked_at' => $key->revoked_at?->utc()->toIso8601ZuluString(),
            'billable_calls_this_period' => $billableCallsThisPeriod,
        ];
    }

    private function callsOf(PersonalAccessToken $key, CarbonInterface $now): int
    {
        [$start, $end] = Meter::period($now);

        return DB::table('billable_calls')->where('token_id', $key->getKey())
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
    }

    /**
     * @return array{0: string, 1: list<ApiScope>}
     */
    private function parseCreate(mixed $body): array
    {
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ApiError(
                ErrorCode::BadRequest,
                'The request body must be a JSON object.',
                'Send {"name": "<a label>", "scopes": ["search:read"]} with Content-Type: application/json.',
            );
        }

        $problems = [];
        foreach (array_keys($body) as $field) {
            if (! in_array($field, self::CREATE_FIELDS, true)) {
                $problems[] = self::problem((string) $field, ParameterIssue::UnknownParameter, '"'.SafeText::of((string) $field).'" is not a field of a new key.', self::CREATE_FIELDS, Suggest::closest((string) $field, self::CREATE_FIELDS));
            }
        }

        $name = $body['name'] ?? null;
        if ($name === null) {
            $problems[] = self::problem('name', ParameterIssue::Missing, 'name is required: a label to tell this key apart, e.g. "laptop" or "Claude Desktop".');
        } elseif (! is_string($name) || trim($name) === '') {
            $problems[] = self::problem('name', is_string($name) ? ParameterIssue::EmptyValue : ParameterIssue::InvalidValue, 'name must be text of 1 to '.self::MAX_NAME_LENGTH.' characters.');
        } elseif (mb_strlen(trim($name)) > self::MAX_NAME_LENGTH) {
            $problems[] = self::problem('name', ParameterIssue::TooLong, 'name is longer than '.self::MAX_NAME_LENGTH.' characters.');
        } elseif (SafeText::containsKey($name)) {
            // A name is stored and listed as typed; a key pasted here would be shown in plaintext.
            $problems[] = self::problem('name', ParameterIssue::InvalidValue, 'name looks like an API key. Names are shown in key lists, so use a label such as "laptop" instead, and revoke that key if it was a real one.');
        }

        $scopes = [ApiScope::SearchRead];
        if (array_key_exists('scopes', $body)) {
            $raw = $body['scopes'];
            if (! is_array($raw) || ! array_is_list($raw)) {
                $problems[] = self::problem('scopes', ParameterIssue::InvalidValue, 'scopes must be a list, e.g. ["search:read"].', ApiScope::values());
            } elseif ($raw === []) {
                $problems[] = self::problem('scopes', ParameterIssue::EmptyValue, 'A key needs at least one scope. Leave scopes out for ["search:read"].', ApiScope::values());
            } else {
                $scopes = [];
                foreach ($raw as $scope) {
                    $valid = is_string($scope) ? ApiScope::tryFrom($scope) : null;
                    if ($valid === null) {
                        $shown = is_string($scope) ? '"'.SafeText::of($scope).'"' : 'A non-text value';
                        $problems[] = self::problem('scopes', ParameterIssue::InvalidValue, "{$shown} is not a scope.", ApiScope::values(), is_string($scope) ? Suggest::closest($scope, ApiScope::values()) : null);
                    } else {
                        $scopes[] = $valid;
                    }
                }
            }
        }

        if ($problems !== []) {
            $count = count($problems);
            throw new ApiError(
                ErrorCode::InvalidParameters,
                ($count === 1 ? '1 problem' : "{$count} problems").' with the new key. See details.',
                details: $problems,
            );
        }

        return [trim($name), $scopes];
    }

    /**
     * @param  list<string>|null  $allowed
     * @return array<string, mixed>
     */
    private static function problem(string $param, ParameterIssue $issue, string $message, ?array $allowed = null, ?string $didYouMean = null): array
    {
        return [
            'param' => SafeText::of($param),
            'issue' => $issue->value,
            'message' => $message,
            ...($allowed === null ? [] : ['allowed' => $allowed]),
            ...($didYouMean === null ? [] : ['did_you_mean' => $didYouMean]),
        ];
    }
}
