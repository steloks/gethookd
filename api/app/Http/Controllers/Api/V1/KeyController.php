<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Keys\KeysController;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * /api/v1/keys and /api/v1/usage: the keys of the account that owns the Bearer key.
 */
class KeyController extends KeysController
{
    protected function owner(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * The calling key's own scopes: a key can't create a key that can do more than it can.
     */
    protected function grantableScopes(Request $request): ?array
    {
        return array_values($request->user()->currentAccessToken()->abilities ?? []);
    }
}
