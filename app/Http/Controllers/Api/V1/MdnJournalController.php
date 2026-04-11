<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnJournalService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MdnJournalController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly MdnJournalService $journal,
    ) {}

    public function store(Request $request, int $mdn): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'tile_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $entry = $this->journal->addEntry($player->id, $data['tile_id'] ?? null, $data['body']);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $entry], 201);
    }

    public function vote(Request $request, int $mdn, int $entry): JsonResponse
    {
        $data = $request->validate(['vote' => ['required', 'string', 'in:helpful,unhelpful']]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->journal->vote($player->id, $entry, $data['vote']);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
