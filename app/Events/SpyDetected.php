<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the target (defender) user's private channel the moment
 * their security stack catches a spy attempt.
 *
 * Only fired when SpyService's detection roll succeeds — undetected
 * spies silently succeed or fail, preserving stealth as a viable strat.
 */
class SpyDetected implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $defenderUserId,
        public readonly string $spyUsername,
        public readonly bool $spySucceeded,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->defenderUserId);
    }

    public function broadcastAs(): string
    {
        return 'SpyDetected';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'spy.detected',
            'title' => 'Spy detected at your base',
            'body' => [
                'spy_succeeded' => $this->spySucceeded,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
