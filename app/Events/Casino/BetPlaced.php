<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BetPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $numbers
     */
    public function __construct(
        public readonly int $tableId,
        public readonly string $username,
        public readonly string $betType,
        public readonly float $amount,
        public readonly array $numbers = [],
        public readonly ?string $betId = null,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'BetPlaced';
    }

    public function broadcastWith(): array
    {
        // numbers + bet_id are NEW — they let other clients render a
        // chip on the exact cell/region this bet covers, rather than
        // just scrolling a "username bet X on Y" text log. Required
        // for the table's live chip overlay.
        return [
            'table_id' => $this->tableId,
            'username' => $this->username,
            'bet_type' => $this->betType,
            'amount' => $this->amount,
            'numbers' => $this->numbers,
            'bet_id' => $this->betId,
        ];
    }
}
