<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the victim's private channel the moment an Abduction
 * Anchor successfully drags their base to a rival's current tile.
 *
 * Fired by BaseTeleportService::moveEnemyBase immediately after the
 * DB transaction commits. The online victim gets a toast with the
 * new coordinates; the offline victim sees the paired activity log
 * entry on their next login (recorded inside the same transaction).
 *
 * Why the attacker username IS revealed here (unlike BaseUnderAttack
 * which deliberately hides it behind the Counter-Intel Dossier
 * unlock): the Abduction Anchor requires the attacker to have a
 * successful spy on the victim within the last 24h, AND consumes a
 * 10k-barrel item per firing. That intel and opportunity cost is
 * itself the gate — the attack is loud by design, not by accident.
 * Keeping identity hidden would make the feature feel broken ("who
 * did this to me?") without a matching in-game path to answer. If
 * this needs to change we should redact the username here and in
 * the matching activity log payload in BaseTeleportService.
 */
class BaseRelocated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $defenderUserId,
        public readonly string $attackerUsername,
        public readonly int $newX,
        public readonly int $newY,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->defenderUserId);
    }

    public function broadcastAs(): string
    {
        return 'BaseRelocated';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'base.relocated',
            'title' => 'Your base has been forcibly relocated',
            'body' => [
                'attacker_username' => $this->attackerUsername,
                'new_x' => $this->newX,
                'new_y' => $this->newY,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
