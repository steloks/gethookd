<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\V0\AdController as V0AdController;
use Illuminate\Support\Facades\Route;

// v0: key from ?api_key= (see AppServiceProvider). Any auth failure becomes v0's generic 400.
Route::prefix('v0')->middleware(['auth:sanctum', 'abilities:'.ApiScope::SearchRead->value])->group(function () {
    Route::get('ads', [V0AdController::class, 'index']);
});
