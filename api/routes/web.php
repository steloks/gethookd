<?php

use App\Http\Controllers\Developer\KeyController as DeveloperKeyController;
use App\Http\Middleware\LocalHostOnly;
use App\Http\Middleware\RejectQueryParameters;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/developer');

// The developer page (React, resources/js/developer), for the single local user.
Route::get('developer', fn () => view('developer'))->middleware(LocalHostOnly::class);

// The developer page's JSON endpoints, for the single local user (no login, as the spec allows).
// The web group adds the session and CSRF protection; LocalHostOnly refuses other host names.
// No rate limit and no key cap: local processes are trusted (README, "API keys and usage").
// Errors use the API's error shape (bootstrap/app.php).
Route::prefix('developer')->middleware([LocalHostOnly::class, RejectQueryParameters::class])->group(function () {
    Route::get('keys', [DeveloperKeyController::class, 'index']);
    Route::post('keys', [DeveloperKeyController::class, 'store']);
    Route::delete('keys/{id}', [DeveloperKeyController::class, 'destroy']);
    Route::get('usage', [DeveloperKeyController::class, 'usage']);
});
