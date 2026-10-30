<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The single hard-coded local user that owns every API key (the spec needs no login).
 */
final class LocalUser
{
    public const EMAIL = 'local@gethookd.test';

    public static function get(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Local developer', 'password' => Str::random(40)],
        );
    }
}
