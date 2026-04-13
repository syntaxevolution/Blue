<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the defender's private channel the moment a wasteland
 * duel resolves against them, mirroring BaseUnderAttack.
 *
 * Fired by TileCombatService immediately after the DB transaction
 * commits. The attacker gets their outcome back via the API response
 * (they initiated) so no attacker-side broadcast is needed.
 *
 * Payload is intentionally anonymous — no attacker_username — so the
 * activity log row written by RecordActivityLog::handleTileCombat
 * Resolved shows "You were attacked on tile (x,y)" without leaking
 * identity. The Counter-Intel Dossier unmasks the attacker on the
 * /attack-log page via AttackLogService::recentAttacks().
 */
class TileCombatResolved implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $defenderUserId,
        public readonly string $outcome,         // attacker_win | defender_win
        public readonly int $oilStolen,
        public readonly int $tileCombatId,
        public readonly int $tileX,
        public readonly int $tileY,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->defenderUserId);
    }

    public function broadcastAs(): string
    {
        return 'TileCombatResolved';
    }

    public function broadcastWith(): array
    {
        // Anonymous on the wire — attacker identity lives in the
        // dossier, never in the toast.
        return [
            'type' => 'tile_combat.received',
            'title' => $this->outcome === 'attacker_win'
                ? "You were ambushed on tile ({$this->tileX}, {$this->tileY})"
                : "You fought off an attacker on tile ({$this->tileX}, {$this->tileY})",
            'body' => [
                'outcome' => $this->outcome,
                'oil_stolen' => $this->oilStolen,
                'tile_combat_id' => $this->tileCombatId,
                'tile_x' => $this->tileX,
                'tile_y' => $this->tileY,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
