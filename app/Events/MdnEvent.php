<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Scaffolded MDN-related notification event. Not fired anywhere yet —
 * reserved so the activity log listener and frontend toast subscriber
 * already understand the shape when Phase 4 (social) lands.
 */
class MdnEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $subType,
        public readonly string $title,
        public readonly array $body = [],
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->recipientUserId);
    }

    public function broadcastAs(): string
    {
        return 'MdnEvent';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => 'mdn.'.$this->subType,
            'title' => $this->title,
            'body' => $this->body,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
