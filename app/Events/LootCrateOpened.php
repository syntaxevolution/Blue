<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the opener's private channel the moment they open a
 * loot crate (real or sabotage) so the UI can render a toast without
 * waiting for a page refresh.
 *
 * Persistence is handled separately by ActivityLogService — this event
 * exists purely for the real-time push. Pairs with
 * SabotageLootCrateTriggered which is broadcast to the *placer* of a
 * sabotage crate when someone else opens it.
 */
class LootCrateOpened implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $outcome  Outcome payload from LootCrateService::open
     */
    public function __construct(
        public readonly int $openerUserId,
        public readonly int $crateId,
        public readonly string $crateKind, // 'real' | 'sabotage'
        public readonly array $outcome,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->openerUserId);
    }

    public function broadcastAs(): string
    {
        return 'LootCrateOpened';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'loot.opened',
            'title' => $this->renderTitle(),
            'body' => [
                'crate_id' => $this->crateId,
                'crate_kind' => $this->crateKind,
                'outcome' => $this->outcome,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function renderTitle(): string
    {
        $kind = (string) ($this->outcome['kind'] ?? 'nothing');

        return match ($kind) {
            'oil' => 'Found a loot crate — +'.((int) ($this->outcome['barrels'] ?? 0)).' barrels',
            'cash' => 'Found a loot crate — +A'.number_format((float) ($this->outcome['cash'] ?? 0), 2),
            'item' => 'Found a loot crate — '.((string) ($this->outcome['item_name'] ?? 'a mystery item')),
            'item_dupe' => 'Found a loot crate — but you already own that item',
            'sabotage_oil' => 'It was a trap! Oil siphoned',
            'sabotage_cash' => 'It was a trap! Cash siphoned',
            'immune_no_effect' => 'That crate was a trap — immunity held',
            default => 'Opened a loot crate — nothing inside',
        };
    }
}
