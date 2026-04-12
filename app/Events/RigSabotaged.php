<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the victim's private channel the moment a planted
 * sabotage device triggers on them. Deliberately anonymous — the
 * planter's identity is only revealed via the Counter-Intel Dossier
 * (Attack Log), mirroring the raid notification pattern.
 *
 * Pairs with SabotageTriggered which goes to the planter.
 */
class RigSabotaged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $victimUserId,
        public readonly string $deviceKey,
        public readonly string $outcome,
        public readonly int $siphonedBarrels,
        public readonly bool $rigBroken,
        public readonly int $sabotageId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->victimUserId);
    }

    public function broadcastAs(): string
    {
        return 'RigSabotaged';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'sabotage.hit',
            'title' => match ($this->outcome) {
                'drill_broken_and_siphoned' => "Your rig was wrecked and {$this->siphonedBarrels} barrels were siphoned",
                'drill_broken' => 'Your rig was wrecked by a planted device',
                'siphoned_tier_one' => "A siphon charge drained {$this->siphonedBarrels} barrels from your stash",
                'fizzled_tier_one' => 'A booby-trap triggered but your starter rig shrugged it off',
                'fizzled_immune' => 'A planted device triggered on you — you got lucky this time',
                'detected' => 'A Tripwire Ward saved your rig from a planted device',
                default => 'A planted device triggered on your drill',
            },
            'body' => [
                'sabotage_id' => $this->sabotageId,
                'device_key' => $this->deviceKey,
                'outcome' => $this->outcome,
                'siphoned_barrels' => $this->siphonedBarrels,
                'rig_broken' => $this->rigBroken,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
