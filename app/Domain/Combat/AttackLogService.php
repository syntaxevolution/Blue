<?php

namespace App\Domain\Combat;

use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Builds the chronological list of hostile events suffered by a player.
 *
 * Gated by the attack_log_dossier item purchased at the Fort post. The
 * dossier is the single unlock that reveals both raid attackers AND
 * sabotage planters — both are classified as "who did harm to me", so
 * a player who paid the dossier fee sees them in one merged feed.
 *
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
     * Chronological feed merging raid attacks and triggered sabotage
     * devices. Both are rendered by the Vue view as a unified "who did
     * harm to me" list. Entries are discriminated by the `kind` field:
     *
     *   kind=attack   — row came from the `attacks` table
     *   kind=sabotage — row came from `drill_point_sabotages` (this
     *                   player triggered someone else's device)
     *
     * Limit applies per source before the merge, so a player with a
     * very active oil field could see more than $limit total entries.
     * In practice 100+100 merged and sliced to 100 is fine for the
     * 100-user launch scale.
     *
     * @return list<array<string,mixed>>
     */
    public function recentAttacks(Player $player, int $limit = 100): array
    {
        $attacks = DB::table('attacks')
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
                'kind' => 'attack',
                'id' => 'attack-'.(int) $row->id,
                'source_id' => (int) $row->id,
                'outcome' => (string) $row->outcome,
                'cash_stolen' => (float) $row->cash_stolen,
                'created_at' => $row->created_at,
                'attacker_username' => (string) $row->attacker_username,
                'attacker_player_id' => (int) $row->attacker_player_id,
                'device_key' => null,
                'siphoned_barrels' => 0,
                'rig_broken' => false,
            ])
            ->all();

        // Sabotages where THIS player was the one who triggered the
        // trap (i.e. they drilled into someone else's device). Renders
        // every outcome — broken-rig, siphoned-only, detected, and the
        // fizzle variants — so the player sees both real harm and the
        // near-misses where someone tried to hurt them.
        //
        // `users` is a LEFT JOIN so a sabotage row whose planter's user
        // account was hard-deleted still appears in the feed (with an
        // anonymous "[deleted]" label) instead of being silently
        // dropped by an inner join.
        $sabotages = DB::table('drill_point_sabotages')
            ->where('drill_point_sabotages.triggered_by_player_id', $player->id)
            ->whereNotNull('drill_point_sabotages.triggered_at')
            ->join('players as planter', 'planter.id', '=', 'drill_point_sabotages.placed_by_player_id')
            ->leftJoin('users', 'users.id', '=', 'planter.user_id')
            ->orderByDesc('drill_point_sabotages.triggered_at')
            ->limit($limit)
            ->get([
                'drill_point_sabotages.id',
                'drill_point_sabotages.outcome',
                'drill_point_sabotages.device_key',
                'drill_point_sabotages.siphoned_barrels',
                'drill_point_sabotages.triggered_at as created_at',
                'users.name as attacker_username',
                'planter.id as attacker_player_id',
            ])
            ->map(fn ($row) => [
                'kind' => 'sabotage',
                'id' => 'sabotage-'.(int) $row->id,
                'source_id' => (int) $row->id,
                'outcome' => (string) $row->outcome,
                'cash_stolen' => 0.0,
                'created_at' => $row->created_at,
                'attacker_username' => (string) ($row->attacker_username ?? '[deleted]'),
                'attacker_player_id' => (int) $row->attacker_player_id,
                'device_key' => (string) $row->device_key,
                'siphoned_barrels' => (int) $row->siphoned_barrels,
                // rig_broken derives from the DB enum: only the
                // drill_broken* variants count. 'siphoned_only' means
                // tier-1 protected the rig while oil still flowed out —
                // must not render as a wrecked-rig entry.
                'rig_broken' => in_array((string) $row->outcome, ['drill_broken', 'drill_broken_and_siphoned'], true),
            ])
            ->all();

        // Merge chronologically and slice to the overall limit.
        $merged = array_merge($attacks, $sabotages);
        usort($merged, function (array $a, array $b) {
            $ta = is_string($a['created_at']) ? strtotime($a['created_at']) : (int) ($a['created_at']?->timestamp ?? 0);
            $tb = is_string($b['created_at']) ? strtotime($b['created_at']) : (int) ($b['created_at']?->timestamp ?? 0);

            return $tb <=> $ta;
        });

        return array_slice($merged, 0, $limit);
    }
}
