<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the defending user's private channel the moment a raid
 * resolves against them, so an online player sees a toast instantly
 * and an offline player sees the activity log on their next login.
 *
 * Fired by AttackService immediately after the DB transaction commits.
 */
class BaseUnderAttack implements ShouldBroadcast
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
        return 'BaseUnderAttack';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'attack.incoming',
            'title' => $this->outcome === 'success'
                ? "{$this->attackerUsername} raided your base"
                : "{$this->attackerUsername} tried to raid your base",
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
