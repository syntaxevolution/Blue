<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlackjackPayout implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly int $dealerTotal,
        public readonly bool $dealerBust,
        public readonly array $results,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'BlackjackPayout';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'dealer_total' => $this->dealerTotal,
            'dealer_bust' => $this->dealerBust,
            'results' => $this->results,
        ];
    }
}
