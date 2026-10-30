<?php

use App\Enums\ApiScope;
use App\Http\Controllers\Api\V0\AdController as V0AdController;
use App\Http\Controllers\Api\V1\AdController as V1AdController;
use App\Http\Controllers\Api\V1\NicheController as V1NicheController;
use App\Http\Middleware\RejectQueryParameters;
use Illuminate\Support\Facades\Route;

// v0: key from ?api_key= (see AppServiceProvider). Any auth failure becomes v0's generic 400.
Route::prefix('v0')->middleware(['auth:sanctum', 'abilities:'.ApiScope::SearchRead->value])->group(function () {
    Route::get('ads', [V0AdController::class, 'index']);
});

// v1: key from Authorization: Bearer only. Errors use the shape in App\Support\Errors.
Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:'.ApiScope::SearchRead->value])->group(function () {
    Route::get('ads', [V1AdController::class, 'index']);
    Route::get('ads/{id}', [V1AdController::class, 'show'])->middleware(RejectQueryParameters::class);
    Route::get('niches', [V1NicheController::class, 'index'])->middleware(RejectQueryParameters::class);
});
