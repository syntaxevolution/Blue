<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnJournalService;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Models\Mdn;
use App\Models\MdnAlliance;
use App\Models\MdnMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MdnController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly MdnService $mdnSvc,
        private readonly MdnJournalService $journalSvc,
        private readonly GameConfigResolver $config,
    ) {}

    public function index(): JsonResponse
    {
        $mdns = Mdn::query()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'tag', 'member_count', 'motto', 'leader_player_id']);

        return response()->json(['data' => $mdns]);
    }

    public function show(int $mdn): JsonResponse
    {
        $mdnRow = Mdn::findOrFail($mdn);
        $members = MdnMembership::query()->where('mdn_id', $mdnRow->id)->get();
        $alliances = MdnAlliance::query()
            ->where('mdn_a_id', $mdnRow->id)
            ->orWhere('mdn_b_id', $mdnRow->id)
            ->get();
        $journal = $this->journalSvc->list($mdnRow->id);

        return response()->json([
            'mdn' => $mdnRow,
            'members' => $members,
            'alliances' => $alliances,
            'journal' => $journal,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $nameMax = (int) $this->config->get('mdn.name_max_length', 50);
        $tagMax = (int) $this->config->get('mdn.tag_max_length', 6);
        $mottoMax = (int) $this->config->get('mdn.motto_max_length', 200);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.$nameMax],
            'tag' => ['required', 'string', 'max:'.$tagMax],
            'motto' => ['nullable', 'string', 'max:'.$mottoMax],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $mdn = $this->mdnSvc->create($player->id, $data['name'], $data['tag'], $data['motto'] ?? null);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $mdn], 201);
    }

    public function join(Request $request, int $mdn): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->join($player->id, $mdn);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, int $mdn): JsonResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->leave($player->id);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function kick(Request $request, int $mdn, int $player): JsonResponse
    {
        $user = $request->user();
        $leader = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->kick($leader->id, $player);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function promote(Request $request, int $mdn, int $player): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string', 'in:officer,member']]);

        $user = $request->user();
        $leader = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->promote($leader->id, $player, $data['role']);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function disband(Request $request, int $mdn): JsonResponse
    {
        $user = $request->user();
        $leader = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->disband($leader->id);
        } catch (MdnException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
