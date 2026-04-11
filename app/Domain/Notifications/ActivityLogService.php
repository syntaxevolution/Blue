<?php

namespace App\Domain\Notifications;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Writes persistent activity log rows whenever a broadcast event fires.
 *
 * Events are broadcast instantly (Reverb → private user channel), but a
 * user who was offline when the event fired needs somewhere to scroll
 * back through what they missed. That's this log.
 *
 * The ActivityLog row carries the same payload as the broadcast, so
 * the frontend can render the same toast UI for historical entries.
 */
class ActivityLogService
{
    public function record(int $userId, string $type, string $title, array $body = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'read_at' => null,
            'created_at' => now(),
        ]);
    }

    public function paginate(int $userId, int $perPage = 25): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function markRead(int $userId, int $activityLogId): void
    {
        ActivityLog::query()
            ->where('user_id', $userId)
            ->where('id', $activityLogId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(int $userId): int
    {
        return ActivityLog::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadCount(int $userId): int
    {
        return ActivityLog::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
