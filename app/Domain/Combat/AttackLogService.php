<?php

namespace App\Domain\Combat;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Builds the chronological list of raids suffered by a player.
 *
 * Gated by the attack_log_dossier item purchased at the Fort post.
 * Extracted out of Web\AttackLogController so the controller stays
 * thin and the domain owns every query.
 */
class AttackLogService
{
    public const DOSSIER_ITEM_KEY = 'attack_log_dossier';

    public function ownsDossier(Player $player): bool
    {
        return DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', self::DOSSIER_ITEM_KEY)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->exists();
    }

    /**
     * @return list<array{id:int,outcome:string,cash_stolen:float,created_at:mixed,attacker_username:string,attacker_player_id:int}>
     */
    public function recentAttacks(Player $player, int $limit = 100): array
    {
        return DB::table('attacks')
            ->where('attacks.defender_player_id', $player->id)
            ->join('players as attacker', 'attacker.id', '=', 'attacks.attacker_player_id')
            ->join('users', 'users.id', '=', 'attacker.user_id')
            ->orderByDesc('attacks.created_at')
            ->limit($limit)
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
    }
}
