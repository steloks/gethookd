<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\V0\AdController as V0AdController;
use App\Http\Controllers\Api\V1\AdController as V1AdController;
use App\Http\Controllers\Api\V1\NicheController as V1NicheController;
use App\Http\Middleware\RateLimitPerKey;
use App\Http\Middleware\RejectQueryParameters;
use Illuminate\Support\Facades\Route;

// Both versions: authenticate, then count the request against the key's rate limit (shared by v0,
// v1 and MCP), then check the scope. Order matters: the limit needs the key, and a request refused
// for its scope still counts as load.

// v0: key from ?api_key= (see AppServiceProvider). Any auth failure becomes v0's generic 400.
Route::prefix('v0')->middleware(['auth:sanctum', RateLimitPerKey::class, 'abilities:'.ApiScope::SearchRead->value])->group(function () {
    Route::get('ads', [V0AdController::class, 'index']);
});

// v1: key from Authorization: Bearer only. Errors use the shape in App\Support\Errors.
Route::prefix('v1')->middleware(['auth:sanctum', RateLimitPerKey::class, 'abilities:'.ApiScope::SearchRead->value])->group(function () {
    Route::get('ads', [V1AdController::class, 'index']);
    Route::get('ads/{id}', [V1AdController::class, 'show'])->middleware(RejectQueryParameters::class);
    Route::get('niches', [V1NicheController::class, 'index'])->middleware(RejectQueryParameters::class);
});
