<?php

namespace App\Http\Controllers\Web;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnJournalService;
use App\Domain\Mdn\MdnService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Models\Mdn;
use App\Models\MdnAlliance;
use App\Models\MdnMembership;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia controller for MDN management. All logic lives in
 * App\Domain\Mdn\*; this controller only validates input, calls the
 * service, and redirects with flash state.
 */
class MdnController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly MdnService $mdnSvc,
        private readonly MdnJournalService $journalSvc,
        private readonly GameConfigResolver $config,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $mdns = Mdn::query()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'tag', 'member_count', 'motto', 'leader_player_id']);

        $ownMdn = $player->mdn_id !== null ? Mdn::find($player->mdn_id) : null;

        return Inertia::render('Game/Mdn/Index', [
            'mdns' => $mdns,
            'own_mdn' => $ownMdn,
            'player_id' => $player->id,
            'max_members' => (int) $this->config->get('mdn.max_members', 50),
            'creation_cost' => (float) $this->config->get('mdn.creation_cost_cash', 0),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Game/Mdn/Create', [
            'name_max' => (int) $this->config->get('mdn.name_max_length', 50),
            'tag_max' => (int) $this->config->get('mdn.tag_max_length', 6),
            'motto_max' => (int) $this->config->get('mdn.motto_max_length', 200),
            'creation_cost' => (float) $this->config->get('mdn.creation_cost_cash', 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.(int) $this->config->get('mdn.name_max_length', 50)],
            'tag' => ['required', 'string', 'max:'.(int) $this->config->get('mdn.tag_max_length', 6)],
            'motto' => ['nullable', 'string', 'max:'.(int) $this->config->get('mdn.motto_max_length', 200)],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $mdn = $this->mdnSvc->create($player->id, $data['name'], $data['tag'], $data['motto'] ?? null);
        } catch (MdnException $e) {
            return redirect()->route('mdn.create')->withErrors(['mdn' => $e->getMessage()])->withInput();
        }

        return redirect()->route('mdn.show', $mdn->id)->with('status', 'MDN created.');
    }

    public function show(Request $request, int $mdn): Response
    {
        $mdnRow = Mdn::findOrFail($mdn);
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $members = MdnMembership::query()
            ->where('mdn_id', $mdnRow->id)
            ->with('player:id,user_id,akzar_cash,oil_barrels,mdn_id')
            ->orderByRaw("CASE role WHEN 'leader' THEN 0 WHEN 'officer' THEN 1 ELSE 2 END")
            ->orderBy('joined_at')
            ->get();

        // Resolve usernames without a new join.
        $userIds = $members->pluck('player.user_id')->filter()->unique()->all();
        $userNames = \App\Models\User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        $alliances = MdnAlliance::query()
            ->where('mdn_a_id', $mdnRow->id)
            ->orWhere('mdn_b_id', $mdnRow->id)
            ->get();

        $allyIds = $alliances->map(fn ($a) => $a->mdn_a_id === $mdnRow->id ? $a->mdn_b_id : $a->mdn_a_id)->unique();
        $allyMdns = Mdn::query()->whereIn('id', $allyIds)->get(['id', 'name', 'tag']);

        $journal = $this->journalSvc->list($mdnRow->id, $request->query('sort', 'helpful'));

        $ownMembership = MdnMembership::query()
            ->where('mdn_id', $mdnRow->id)
            ->where('player_id', $player->id)
            ->first();

        return Inertia::render('Game/Mdn/Show', [
            'mdn' => $mdnRow,
            'members' => $members->map(fn ($m) => [
                'player_id' => $m->player_id,
                'user_name' => $userNames[$m->player?->user_id] ?? 'Unknown',
                'role' => $m->role,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'akzar_cash' => (float) ($m->player?->akzar_cash ?? 0),
            ])->values(),
            'alliances' => $alliances->map(fn ($a) => [
                'id' => $a->id,
                'other_mdn' => $allyMdns->firstWhere('id', $a->mdn_a_id === $mdnRow->id ? $a->mdn_b_id : $a->mdn_a_id),
                'declared_at' => $a->declared_at?->toIso8601String(),
            ])->values(),
            'journal' => $journal->map(fn ($e) => [
                'id' => $e->id,
                'author_player_id' => $e->author_player_id,
                'tile_id' => $e->tile_id,
                'body' => $e->body,
                'helpful_count' => $e->helpful_count,
                'unhelpful_count' => $e->unhelpful_count,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'player_id' => $player->id,
            'is_member' => $ownMembership !== null,
            'is_leader' => $ownMembership?->role === MdnService::ROLE_LEADER,
            'own_mdn_id' => $player->mdn_id,
        ]);
    }

    public function join(Request $request, int $mdn): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->join($player->id, $mdn);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['mdn' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn)->with('status', 'Joined MDN.');
    }

    public function leave(Request $request, int $mdn): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->leave($player->id);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['mdn' => $e->getMessage()]);
        }

        return redirect()->route('mdn.index')->with('status', 'You have left the MDN.');
    }

    public function kick(Request $request, int $mdn, int $player): RedirectResponse
    {
        $user = $request->user();
        $leader = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->kick($leader->id, $player);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['mdn' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn)->with('status', 'Member removed.');
    }

    public function promote(Request $request, int $mdn, int $player): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:officer,member'],
        ]);

        $user = $request->user();
        $leader = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->promote($leader->id, $player, $data['role']);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['mdn' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn)->with('status', 'Role updated.');
    }

    public function disband(Request $request, int $mdn): RedirectResponse
    {
        $user = $request->user();
        $leader = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->mdnSvc->disband($leader->id);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['mdn' => $e->getMessage()]);
        }

        return redirect()->route('mdn.index')->with('status', 'MDN disbanded.');
    }
}
