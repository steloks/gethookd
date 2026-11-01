<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\V0\AdController as V0AdController;
use App\Http\Controllers\Api\V1\AdController as V1AdController;
use App\Http\Controllers\Api\V1\KeyController as V1KeyController;
use App\Http\Controllers\Api\V1\NicheController as V1NicheController;
use App\Http\Middleware\MeterBillableCall;
use App\Http\Middleware\RateLimitPerKey;
use App\Http\Middleware\RecordMcpCall;
use App\Http\Middleware\RejectQueryParameters;
use Illuminate\Support\Facades\Route;

// Both versions: authenticate, then count the request against the key's rate limit (shared by v0,
// v1 and MCP), then check the scope. Order matters: the limit needs the key, and a request refused
// for its scope still counts as load.

// v0: key from ?api_key= (see AppServiceProvider). Any auth failure becomes v0's generic 400.
Route::prefix('v0')->middleware(['auth:sanctum', RateLimitPerKey::class, 'abilities:'.ApiScope::SearchRead->value])->group(function () {
    Route::get('ads', [V0AdController::class, 'index'])->middleware(MeterBillableCall::class.':v0.ads.search');
});

// v1: key from Authorization: Bearer only. Errors use the shape in App\Support\Errors.
// RecordMcpCall notes calls from the MCP server on the key (for the developer page's connect check).
Route::prefix('v1')->middleware(['auth:sanctum', RecordMcpCall::class, RateLimitPerKey::class])->group(function () {
    Route::middleware('abilities:'.ApiScope::SearchRead->value)->group(function () {
        // Billable endpoints (REFERENCE.md, "Billing"); /niches is free.
        Route::get('ads', [V1AdController::class, 'index'])->middleware(MeterBillableCall::class.':v1.ads.search');
        Route::get('ads/{id}', [V1AdController::class, 'show'])->middleware([RejectQueryParameters::class, MeterBillableCall::class.':v1.ads.show']);
        Route::get('niches', [V1NicheController::class, 'index'])->middleware(RejectQueryParameters::class);
    });

    // Key management: the keys of the account that owns the calling key. Never billed.
    Route::middleware(['abilities:'.ApiScope::KeysManage->value, RejectQueryParameters::class])->group(function () {
        Route::get('keys', [V1KeyController::class, 'index']);
        Route::post('keys', [V1KeyController::class, 'store']);
        Route::delete('keys/{id}', [V1KeyController::class, 'destroy']);
    });

    // Usage against the quota: readable with either scope, so an agent can check what is left.
    Route::get('usage', [V1KeyController::class, 'usage'])
        ->middleware(['ability:'.ApiScope::SearchRead->value.','.ApiScope::KeysManage->value, RejectQueryParameters::class]);
});
