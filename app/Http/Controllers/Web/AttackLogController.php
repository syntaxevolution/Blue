<?php

namespace App\Http\Controllers\Web;

use App\Domain\Combat\AttackLogService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
        private readonly AttackLogService $attackLog,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        if (! $this->attackLog->ownsDossier($player)) {
            // Non-owners don't see the feed, but we still clear their
            // badge — otherwise it would stay lit forever for anyone
            // who hasn't bought the dossier.
            $this->attackLog->markViewed($player);

            return Inertia::render('Game/AttackLog', [
                'owns_attack_log' => false,
                'attacks' => [],
            ]);
        }

        $attacks = $this->attackLog->recentAttacks($player);
        $this->attackLog->markViewed($player);

        return Inertia::render('Game/AttackLog', [
            'owns_attack_log' => true,
            'attacks' => $attacks,
        ]);
    }
}
