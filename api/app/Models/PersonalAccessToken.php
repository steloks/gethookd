<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * An API key. Sanctum's token, plus a visible prefix and soft revocation.
 *
 * @property string|null $prefix
 * @property CarbonImmutable|null $revoked_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = ['name', 'prefix', 'token', 'abilities', 'expires_at'];

    /**
     * Added to Sanctum's own $casts (Laravel merges the property and this method).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
