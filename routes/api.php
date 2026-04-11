<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AtlasController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ItemBreakController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\MdnAllianceController;
use App\Http\Controllers\Api\V1\MdnController;
use App\Http\Controllers\Api\V1\MdnJournalController;
use App\Http\Controllers\Api\V1\TeleportController;
use App\Http\Controllers\Api\V1\TransportController;
use App\Http\Controllers\Api\V1\UsernameController;
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

        // Username claim — available before claim completes.
        Route::post('/username/claim', [UsernameController::class, 'claim'])->name('username.claim');

        // Activity log — always accessible.
        Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
        Route::post('/activity/{activityLog}/read', [ActivityLogController::class, 'markRead'])->name('activity.read');
        Route::post('/activity/read-all', [ActivityLogController::class, 'markAllRead'])->name('activity.read_all');

        // Break-resolution routes — only allowed while broken.
        Route::post('/items/repair', [ItemBreakController::class, 'repair'])->name('items.repair');
        Route::post('/items/abandon', [ItemBreakController::class, 'abandon'])->name('items.abandon');

        // All other gameplay — gated by username claim + broken-item
        // guard. The middleware aliases resolve to the same classes
        // the web group uses.
        // Order mirrors routes/web.php: verified → claimed_username → broken-item.
        Route::middleware(['verified', 'require.claimed_username'])->group(function () {

            Route::middleware('block.broken_item')->group(function () {
                Route::get('/map', [MapController::class, 'show'])->name('map.show');
                Route::post('/map/move', [MapController::class, 'move'])->name('map.move');
                Route::post('/map/drill', [MapController::class, 'drill'])->name('map.drill');
                Route::post('/map/purchase', [MapController::class, 'purchase'])->name('map.purchase');
                Route::post('/map/spy', [MapController::class, 'spy'])->name('map.spy');
                Route::post('/map/attack', [MapController::class, 'attack'])->name('map.attack');

                Route::post('/map/transport', [TransportController::class, 'switch'])->name('map.transport');

                Route::get('/map/tile-exists', [TeleportController::class, 'tileExists'])->name('map.tile_exists');
                Route::post('/map/teleport', [TeleportController::class, 'teleport'])->name('map.teleport');

                Route::get('/atlas', [AtlasController::class, 'show'])->name('atlas.show');

                Route::prefix('mdn')->name('mdn.')->group(function () {
                    Route::get('/', [MdnController::class, 'index'])->name('index');
                    Route::get('/{mdn}', [MdnController::class, 'show'])->name('show');
                    Route::post('/', [MdnController::class, 'store'])->name('store');
                    Route::post('/{mdn}/join', [MdnController::class, 'join'])->name('join');
                    Route::post('/{mdn}/leave', [MdnController::class, 'leave'])->name('leave');
                    Route::post('/{mdn}/kick/{player}', [MdnController::class, 'kick'])->name('kick');
                    Route::post('/{mdn}/promote/{player}', [MdnController::class, 'promote'])->name('promote');
                    Route::post('/{mdn}/disband', [MdnController::class, 'disband'])->name('disband');

                    Route::post('/{mdn}/alliances', [MdnAllianceController::class, 'store'])->name('alliances.store');
                    Route::delete('/{mdn}/alliances/{alliance}', [MdnAllianceController::class, 'destroy'])->name('alliances.destroy');

                    Route::post('/{mdn}/journal', [MdnJournalController::class, 'store'])->name('journal.store');
                    Route::post('/{mdn}/journal/{entry}/vote', [MdnJournalController::class, 'vote'])->name('journal.vote');
                });
            });
        });
    });

});
