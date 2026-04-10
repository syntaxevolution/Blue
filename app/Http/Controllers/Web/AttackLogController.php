<?php

namespace App\Http\Controllers\Web;

use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia controller for the Attack Log — a chronological list of
 * every raid the player has suffered.
 *
 * Gated behind the Counter-Intel Dossier item (items_catalog key
 * 'attack_log_dossier') purchased at the Fort Post. Unowned visits
 * render a locked state; owned visits see the full log joined with
 * the attacker's user.name.
 */
class AttackLogController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        $ownsLog = DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', 'attack_log_dossier')
            ->exists();

        if (! $ownsLog) {
            return Inertia::render('Game/AttackLog', [
                'owns_attack_log' => false,
                'attacks' => [],
            ]);
        }

        // Join attacks with the attacker's player -> user so we can show
        // the attacker's username. Defender = current player.
        $attacks = DB::table('attacks')
            ->where('attacks.defender_player_id', $player->id)
            ->join('players as attacker', 'attacker.id', '=', 'attacks.attacker_player_id')
            ->join('users', 'users.id', '=', 'attacker.user_id')
            ->orderByDesc('attacks.created_at')
            ->limit(100)
            ->get([
                'attacks.id',
                'attacks.outcome',
                'attacks.cash_stolen',
                'attacks.created_at',
                'users.name as attacker_username',
                'attacker.id as attacker_player_id',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'outcome' => (string) $row->outcome,
                'cash_stolen' => (float) $row->cash_stolen,
                'created_at' => $row->created_at,
                'attacker_username' => (string) $row->attacker_username,
                'attacker_player_id' => (int) $row->attacker_player_id,
            ])
            ->all();

        return Inertia::render('Game/AttackLog', [
            'owns_attack_log' => true,
            'attacks' => $attacks,
        ]);
    }
}
