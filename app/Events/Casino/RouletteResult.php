<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RouletteResult implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly int $roundNumber,
        public readonly int $number,
        public readonly string $color,
        public readonly array $payouts,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'RouletteResult';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'round_number' => $this->roundNumber,
            'number' => $this->number,
            'color' => $this->color,
            'payouts' => $this->payouts,
        ];
    }
}
