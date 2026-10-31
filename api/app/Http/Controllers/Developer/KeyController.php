<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Keys\KeysController;
use App\Models\User;
use App\Support\LocalUser;
use Illuminate\Http\Request;

/**
 * The developer page's JSON endpoints: the keys of the single hard-coded local user (the spec
 * needs no login). Protected by CSRF and a local-host check (routes/web.php), not by a key.
 */
class KeyController extends KeysController
{
    protected function owner(Request $request): User
    {
        return LocalUser::get();
    }
}
