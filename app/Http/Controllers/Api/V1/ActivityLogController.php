<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\ActivityLogService;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $entries = $this->activityLog->paginate($userId);

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'total' => $entries->total(),
                'per_page' => $entries->perPage(),
                'current_page' => $entries->currentPage(),
                'unread_count' => $this->activityLog->unreadCount($userId),
            ],
        ]);
    }

    public function markRead(Request $request, ActivityLog $activityLog): JsonResponse
    {
        abort_if($activityLog->user_id !== $request->user()->id, 403);
        $this->activityLog->markRead($request->user()->id, $activityLog->id);

        return response()->json(['status' => 'ok']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->activityLog->markAllRead($request->user()->id);

        return response()->json(['marked' => $count]);
    }
}
