<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\WorldController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| All /api/v1/* endpoints live here, mounted via bootstrap/app.php with
| the 'api' prefix. Controllers under App\Http\Controllers\Api\V1\* must
| stay thin — validate input, call a domain service, return a JSON
| resource. No game logic inline.
|
| Auth-gated routes use the auth:sanctum middleware. Tokens are issued
| by Api\V1\AuthController and carried as Authorization: Bearer <token>.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public — no token required.
    Route::get('/world/info', [WorldController::class, 'info'])->name('world.info');

    Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

    // Authenticated — requires a valid Sanctum token.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/map', [MapController::class, 'show'])->name('map.show');
        Route::post('/map/move', [MapController::class, 'move'])->name('map.move');
        Route::post('/map/drill', [MapController::class, 'drill'])->name('map.drill');
    });

});
