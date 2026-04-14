<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the placer's private channel the moment one of their
 * sabotage loot crates is triggered (or fizzles on an immune victim).
 *
 * Pairs with LootCrateOpened which goes to the opener. The two events
 * are distinct so the frontend can render very different UIs: the
 * opener gets a "you were trapped" toast, the placer gets a "your
 * trap worked — here's the take" toast with the victim's name.
 *
 * The opener's name is included in broadcastWith so the placer's toast
 * can say "Your crate got {username}" without an extra lookup — the
 * AttackLog page is still the authoritative view for the full list.
 */
class SabotageLootCrateTriggered implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $placerUserId,
        public readonly int $crateId,
        public readonly string $deviceKey,
        public readonly string $outcomeKind, // sabotage_oil | sabotage_cash | immune_no_effect
        public readonly float $amount,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->placerUserId);
    }

    public function broadcastAs(): string
    {
        return 'SabotageLootCrateTriggered';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'loot.sabotage.triggered',
            'title' => match ($this->outcomeKind) {
                'sabotage_oil' => 'Your sabotage crate siphoned '.((int) $this->amount).' barrels',
                'sabotage_cash' => 'Your sabotage crate siphoned A'.number_format($this->amount, 2),
                'immune_no_effect' => 'Your sabotage crate fizzled on an immune player',
                default => 'Your sabotage crate was triggered',
            },
            'body' => [
                'crate_id' => $this->crateId,
                'device_key' => $this->deviceKey,
                'outcome_kind' => $this->outcomeKind,
                'amount' => $this->amount,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
