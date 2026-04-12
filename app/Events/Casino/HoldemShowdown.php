<?php

namespace App\Events\Casino;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HoldemShowdown implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly array $results,
        public readonly array $community,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('casino.table.'.$this->tableId);
    }

    public function broadcastAs(): string
    {
        return 'HoldemShowdown';
    }

    public function broadcastWith(): array
    {
        return ['table_id' => $this->tableId, 'results' => $this->results, 'community' => $this->community];
    }
}
