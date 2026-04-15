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
