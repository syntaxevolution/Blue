<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\ActivityLogController;
use App\Http\Controllers\Web\AtlasController;
use App\Http\Controllers\Web\AttackLogController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ItemBreakController;
use App\Http\Controllers\Web\MapController;
use App\Http\Controllers\Web\MdnAllianceController;
use App\Http\Controllers\Web\MdnController;
use App\Http\Controllers\Web\MdnJournalController;
use App\Http\Controllers\Web\TeleportController;
use App\Http\Controllers\Web\TransportController;
use App\Http\Controllers\Web\Casino\BlackjackController;
use App\Http\Controllers\Web\Casino\CasinoController;
use App\Http\Controllers\Web\Casino\ChatController as CasinoChatController;
use App\Http\Controllers\Web\Casino\HoldemController;
use App\Http\Controllers\Web\Casino\RouletteController;
use App\Http\Controllers\Web\Casino\SlotsController;
use App\Http\Controllers\Web\UsernameController;
use App\Http\Controllers\Web\WorldController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Dashboard is rendered by DashboardController so config values
// (immunity hours, daily regen, bank cap, starting cash) can be
// sourced from GameConfig and passed as Inertia props.
Route::get('/dashboard', [DashboardController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Debug view — placeholder until Phase 1 ships the real map.
Route::get('/world', [WorldController::class, 'info'])->name('world.info');

// Profile routes — available before username claim and before
// verification, so users can edit email / claim username without
// being locked out. Claim flow requires auth but deliberately not
// require.claimed_username (otherwise you couldn't claim!).
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/username/claim', [UsernameController::class, 'claim'])->name('username.claim');
});

// Full game routes — require email verified AND username claimed.
// BlockOnBrokenItem intercepts any action the player tries to take
// while an item repair decision is pending (see ItemBreakService).
Route::middleware(['auth', 'verified', 'require.claimed_username'])->group(function () {

    // Break resolution routes are NOT behind block.broken_item — these
    // are the only actions a player can take while broken.
    Route::post('/items/repair', [ItemBreakController::class, 'repair'])->name('items.repair');
    Route::post('/items/abandon', [ItemBreakController::class, 'abandon'])->name('items.abandon');

    // Activity log is always accessible (broken or not).
    Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
    Route::post('/activity/{activityLog}/read', [ActivityLogController::class, 'markRead'])->name('activity.read');
    Route::post('/activity/read-all', [ActivityLogController::class, 'markAllRead'])->name('activity.read_all');

    // Everything else gets the broken-item guard + a baseline throttle.
    // Domain services are the real gate for game economy, but throttling
    // protects the shared VPS from script-level spam holding row locks.
    Route::middleware(['block.broken_item', 'throttle:120,1'])->group(function () {
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

        Route::get('/attack-log', [AttackLogController::class, 'show'])->name('attack_log.show');

        // MDN (Mutual Defense Network) — social layer. Same-MDN attack
        // blocking lives in AttackService/SpyService → MdnService, not
        // in middleware: the existing broken-item guard is enough here.
        Route::prefix('mdn')->name('mdn.')->group(function () {
            Route::get('/', [MdnController::class, 'index'])->name('index');
            Route::get('/create', [MdnController::class, 'create'])->name('create');
            Route::post('/', [MdnController::class, 'store'])->name('store');
            Route::get('/{mdn}', [MdnController::class, 'show'])->name('show');
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

        // Casino — Roughneck's Saloon
        Route::prefix('casino')->name('casino.')->group(function () {
            Route::get('/', [CasinoController::class, 'show'])->name('show');
            Route::post('/enter', [CasinoController::class, 'enter'])->name('enter');
            Route::post('/leave', [CasinoController::class, 'leave'])->name('leave');

            Route::prefix('slots')->name('slots.')->group(function () {
                Route::get('/', [SlotsController::class, 'show'])->name('show');
                Route::post('/spin', [SlotsController::class, 'spin'])->name('spin');
            });

            Route::prefix('roulette')->name('roulette.')->group(function () {
                Route::get('/', [RouletteController::class, 'index'])->name('index');
                Route::get('/{tableId}', [RouletteController::class, 'show'])->name('show');
                Route::post('/{tableId}/bet', [RouletteController::class, 'placeBet'])->name('bet');
            });

            Route::prefix('blackjack')->name('blackjack.')->group(function () {
                Route::get('/', [BlackjackController::class, 'index'])->name('index');
                Route::get('/{tableId}', [BlackjackController::class, 'show'])->name('show');
                Route::post('/{tableId}/join', [BlackjackController::class, 'join'])->name('join');
                Route::post('/{tableId}/bet', [BlackjackController::class, 'bet'])->name('bet');
                Route::post('/{tableId}/action', [BlackjackController::class, 'action'])->name('action');
                Route::post('/{tableId}/leave', [BlackjackController::class, 'leave'])->name('leave');
            });

            Route::prefix('holdem')->name('holdem.')->group(function () {
                Route::get('/', [HoldemController::class, 'index'])->name('index');
                Route::get('/{tableId}', [HoldemController::class, 'show'])->name('show');
                Route::post('/{tableId}/join', [HoldemController::class, 'join'])->name('join');
                Route::post('/{tableId}/action', [HoldemController::class, 'action'])->name('action');
                Route::post('/{tableId}/leave', [HoldemController::class, 'leave'])->name('leave');
            });

            Route::post('/table/{tableId}/chat', [CasinoChatController::class, 'send'])->name('chat.send');
        });
    });
});

require __DIR__.'/auth.php';
