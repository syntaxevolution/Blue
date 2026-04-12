<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the planter's private channel the moment one of their
 * sabotage devices triggers (or fizzles, or is detected). Pairs with
 * RigSabotaged which goes to the victim. Rendered as a toast by the
 * Vue `useNotifications` composable and persisted by ActivityLogService.
 */
class SabotageTriggered implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $planterUserId,
        public readonly string $deviceKey,
        public readonly string $outcome,
        public readonly int $siphonedBarrels,
        public readonly int $sabotageId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->planterUserId);
    }

    public function broadcastAs(): string
    {
        return 'SabotageTriggered';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'sabotage.triggered',
            'title' => match ($this->outcome) {
                'drill_broken_and_siphoned' => "Your {$this->deviceKey} wrecked a rig and siphoned {$this->siphonedBarrels} barrels",
                'drill_broken' => "Your {$this->deviceKey} wrecked a rig",
                'siphoned_tier_one' => "Your {$this->deviceKey} siphoned {$this->siphonedBarrels} barrels from a tier-1 driller",
                'fizzled_tier_one' => "Your {$this->deviceKey} triggered on a tier-1 driller and did nothing",
                'fizzled_immune' => "Your {$this->deviceKey} fizzled on an immune player",
                'detected' => "Your {$this->deviceKey} was disarmed by a Tripwire Ward",
                default => "Your {$this->deviceKey} was triggered",
            },
            'body' => [
                'sabotage_id' => $this->sabotageId,
                'device_key' => $this->deviceKey,
                'outcome' => $this->outcome,
                'siphoned_barrels' => $this->siphonedBarrels,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
