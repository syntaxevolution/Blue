<?php

use App\Domain\Casino\CasinoService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private per-user channel used by toast notifications and activity log
| push updates. Only the user whose ID matches may subscribe — the
| auth callback rejects any mismatched attempt.
|
*/

Broadcast::channel('App.Models.User.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});

Broadcast::channel('casino.table.{tableId}', function ($user, int $tableId) {
    $player = $user->player;
    if ($player === null) {
        return false;
    }

    // Only players who have paid the entry fee (active casino session)
    // may subscribe to a casino table's presence channel. This prevents
    // unpaid players from receiving live game state.
    if (! app(CasinoService::class)->hasActiveSession($player->id)) {
        return false;
    }

    return [
        'id' => $player->id,
        'name' => $user->name,
    ];
});
