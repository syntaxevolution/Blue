<?php

namespace App\Listeners;

use App\Domain\Notifications\ActivityLogService;
use App\Events\BaseUnderAttack;
use App\Events\MdnEvent;
use App\Events\RaidCompleted;
use App\Events\SpyDetected;
use App\Events\TileCombatResolved;

/**
 * Persists every notification broadcast event into the activity_logs
 * table so offline players can see what they missed.
 *
 * Registered in AppServiceProvider::boot() via Event::listen() for all
 * four event classes. The listener runs in-process (no queue), which
 * is fine for our per-event volume (a few writes per action).
 */
class RecordActivityLog
{
    public function __construct(
        private readonly ActivityLogService $log,
    ) {}

    public function handleBaseUnderAttack(BaseUnderAttack $event): void
    {
        $payload = $event->broadcastWith();
        $this->log->record(
            $event->defenderUserId,
            $payload['type'],
            $payload['title'],
            $payload['body'] ?? [],
        );
    }

    public function handleSpyDetected(SpyDetected $event): void
    {
        $payload = $event->broadcastWith();
        $this->log->record(
            $event->defenderUserId,
            $payload['type'],
            $payload['title'],
            $payload['body'] ?? [],
        );
    }

    public function handleRaidCompleted(RaidCompleted $event): void
    {
        $payload = $event->broadcastWith();
        $this->log->record(
            $event->defenderUserId,
            $payload['type'],
            $payload['title'],
            $payload['body'] ?? [],
        );
    }

    public function handleMdnEvent(MdnEvent $event): void
    {
        $payload = $event->broadcastWith();
        $this->log->record(
            $event->recipientUserId,
            $payload['type'],
            $payload['title'],
            $payload['body'] ?? [],
        );
    }

    public function handleTileCombatResolved(TileCombatResolved $event): void
    {
        $payload = $event->broadcastWith();
        $this->log->record(
            $event->defenderUserId,
            $payload['type'],
            $payload['title'],
            $payload['body'] ?? [],
        );
    }
}
