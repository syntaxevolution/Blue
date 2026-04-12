<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableChatMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly string $username,
        public readonly string $message,
        public readonly string $timestamp,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'TableChatMessage';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'username' => $this->username,
            'message' => $this->message,
            'timestamp' => $this->timestamp,
        ];
    }
}
