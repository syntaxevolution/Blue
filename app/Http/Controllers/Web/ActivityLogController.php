<?php

namespace App\Http\Controllers\Web;

use App\Domain\Notifications\ActivityLogService;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;
        $entries = $this->activityLog->paginate($userId);

        return Inertia::render('ActivityLog', [
            'entries' => $entries,
            'unread_count' => $this->activityLog->unreadCount($userId),
        ]);
    }

    public function markRead(Request $request, ActivityLog $activityLog): RedirectResponse
    {
        abort_if($activityLog->user_id !== $request->user()->id, 403);
        $this->activityLog->markRead($request->user()->id, $activityLog->id);

        return Redirect::back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->activityLog->markAllRead($request->user()->id);

        return Redirect::back();
    }
}
