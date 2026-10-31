<?php

use App\Http\Controllers\Developer\KeyController as DeveloperKeyController;
use App\Http\Middleware\LocalHostOnly;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The developer page's JSON endpoints, for the single local user (no login, as the spec allows).
// The web group adds the session and CSRF protection; LocalHostOnly refuses other host names.
// Errors use the API's error shape (bootstrap/app.php).
Route::prefix('developer')->middleware(LocalHostOnly::class)->group(function () {
    Route::get('keys', [DeveloperKeyController::class, 'index']);
    Route::post('keys', [DeveloperKeyController::class, 'store']);
    Route::delete('keys/{id}', [DeveloperKeyController::class, 'destroy']);
    Route::get('usage', [DeveloperKeyController::class, 'usage']);
});
