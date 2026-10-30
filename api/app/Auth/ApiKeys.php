<?php

namespace App\Auth;

use App\Enums\ApiScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues and revokes API keys (Sanctum personal access tokens).
 *
 * A key is "ghk_" + 40 random characters + a CRC32 checksum. Sanctum stores only its SHA-256.
 * Keys are handed out without Sanctum's "<id>|" part, so they do not reveal a database id;
 * Sanctum then finds them by hash.
 */
final class ApiKeys
{
    /** "ghk_" plus the first 8 random characters. */
    private const PREFIX_LENGTH = 12;

    /** "ghk_" (config sanctum.token_prefix) + Str::random(40) + crc32b hex, as Sanctum generates it. */
    private const FORMAT = '/\Aghk_[A-Za-z0-9]{40}[0-9a-f]{8}\z/';

    /**
     * @param  list<ApiScope>  $scopes  at least one
     * @return array{0: PersonalAccessToken, 1: string} the stored key and its plaintext, which is never retrievable again
     */
    public function issue(User $owner, string $name, array $scopes): array
    {
        if ($scopes === []) {
            throw new InvalidArgumentException('An API key needs at least one scope.');
        }

        $abilities = array_values(array_unique(array_map(fn (ApiScope $scope) => $scope->value, $scopes)));

        return DB::transaction(function () use ($owner, $name, $abilities): array {
            $new = $owner->createToken($name, $abilities);
            $plaintext = Str::after($new->plainTextToken, '|');

            /** @var PersonalAccessToken $key */
            $key = $new->accessToken;
            $key->forceFill(['prefix' => substr($plaintext, 0, self::PREFIX_LENGTH)])->save();

            return [$key, $plaintext];
        });
    }

    /**
     * Keeps the row so the key can still be listed; it stops authenticating at once. Revoking
     * again keeps the first revocation time.
     */
    public function revoke(PersonalAccessToken $key): void
    {
        PersonalAccessToken::query()->whereKey($key->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $key->refresh();
    }

    /**
     * Whether a presented key has our format. Anything else is rejected before it reaches the
     * database, e.g. "abc|x", which Sanctum would otherwise look up as id "abc" and fail with a query error.
     */
    public static function hasValidFormat(string $key): bool
    {
        return preg_match(self::FORMAT, $key) === 1;
    }
}
