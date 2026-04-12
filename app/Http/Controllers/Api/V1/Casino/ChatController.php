<?php

namespace App\Http\Controllers\Api\V1\Casino;

use App\Domain\Casino\CasinoChatService;
use App\Domain\Exceptions\CasinoException;
use App\Domain\World\WorldService;
use App\Events\Casino\TableChatMessage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(
        private readonly CasinoChatService $chatService,
        private readonly WorldService $world,
    ) {}

    public function history(Request $request, int $tableId): JsonResponse
    {
        return response()->json([
            'data' => $this->chatService->recentMessages($tableId),
        ]);
    }

    public function send(Request $request, int $tableId): JsonResponse
    {
        $request->validate(['message' => ['required', 'string', 'max:500']]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $msg = $this->chatService->sendMessage($player->id, $tableId, $request->input('message'));

            TableChatMessage::dispatch(
                $tableId,
                $user->name,
                $request->input('message'),
                now()->toIso8601String(),
            );
        } catch (CasinoException $e) {
            return response()->json(['errors' => ['chat' => $e->getMessage()]], 422);
        }

        return response()->json(['data' => ['sent' => true]]);
    }
}
