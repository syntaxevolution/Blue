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
            $this->dedupeKey('raid.alert', $event->defenderUserId, $payload, $event->attackId),
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
            $this->dedupeKey('spy.detected', $event->defenderUserId, $payload, $event->spyAttemptId),
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
            $this->dedupeKey('raid.alert', $event->defenderUserId, $payload, $event->attackId),
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
            $this->dedupeKey('mdn.alert', $event->recipientUserId, $payload),
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
            $this->dedupeKey('tile_combat.alert', $event->defenderUserId, $payload, $event->tileCombatId),
        );
    }

    private function dedupeKey(string $scope, int $userId, array $payload, int|string|null $naturalId = null): string
    {
        if ($naturalId !== null && $naturalId !== '') {
            return "{$scope}:{$naturalId}";
        }

        return $scope.':'.sha1(json_encode([
            'user_id' => $userId,
            'type' => $payload['type'] ?? null,
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? [],
            // Fallback dedupe is deliberately short-windowed: it
            // collapses duplicate handling of the same alert without
            // suppressing a genuinely identical alert later.
            'minute' => now()->format('Y-m-d H:i'),
        ], JSON_UNESCAPED_SLASHES));
    }
}
