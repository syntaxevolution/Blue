<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlackjackPlayerAction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly int $seat,
        public readonly string $action,
        public readonly int $handTotal,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'BlackjackPlayerAction';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'seat' => $this->seat,
            'action' => $this->action,
            'hand_total' => $this->handTotal,
        ];
    }
}
