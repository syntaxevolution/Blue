<?php

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

    return [
        'id' => $player->id,
        'name' => $user->name,
    ];
});
