<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the defender after a raid resolves. Complements
 * BaseUnderAttack by capturing the post-fight outcome + loot,
 * so the defender's activity log carries a full record.
 */
class RaidCompleted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $defenderUserId,
        public readonly string $attackerUsername,
        public readonly string $outcome,
        public readonly float $cashStolen,
        public readonly int $attackId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->defenderUserId);
    }

    public function broadcastAs(): string
    {
        return 'RaidCompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'raid.completed',
            'title' => $this->outcome === 'success'
                ? "Raid by {$this->attackerUsername} succeeded"
                : "Raid by {$this->attackerUsername} was repelled",
            'body' => [
                'attacker' => $this->attackerUsername,
                'outcome' => $this->outcome,
                'cash_stolen' => $this->cashStolen,
                'attack_id' => $this->attackId,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
