<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlackjackDealerTurn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly array $cards,
        public readonly int $total,
        public readonly bool $bust,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'BlackjackDealerTurn';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'cards' => $this->cards,
            'total' => $this->total,
            'bust' => $this->bust,
        ];
    }
}
