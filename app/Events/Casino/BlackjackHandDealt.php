<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlackjackHandDealt implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly int $roundNumber,
        public readonly array $playerCardCounts,
        public readonly array $dealerUpCard,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'BlackjackHandDealt';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'round_number' => $this->roundNumber,
            'player_card_counts' => $this->playerCardCounts,
            'dealer_up_card' => $this->dealerUpCard,
        ];
    }
}
