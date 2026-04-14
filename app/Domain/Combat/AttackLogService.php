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
     * Count hostile events the player hasn't seen yet — i.e. events
     * newer than their `hostility_log_last_viewed_at` bookmark. Merges
     * the same three sources as `recentAttacks()` (incoming raids,
     * triggered sabotage traps, tile-combat engagements) so the navbar
     * badge matches what they'll see on the /attack-log page.
     *
     * A NULL bookmark means "never visited /attack-log" — count every
     * hostile row the player has on file. The migration that adds this
     * column backfills existing players to now() so a fresh deploy
     * doesn't suddenly light up old raid history.
     */
    public function unreadCount(Player $player): int
    {
        $since = $player->hostility_log_last_viewed_at;

        $attacksQuery = DB::table('attacks')
            ->where('defender_player_id', $player->id);
        $sabotagesQuery = DB::table('drill_point_sabotages')
            ->where('triggered_by_player_id', $player->id)
            ->whereNotNull('triggered_at');
        $tileCombatsQuery = DB::table('tile_combats')
            ->where(function ($q) use ($player) {
                $q->where('attacker_player_id', $player->id)
                    ->orWhere('defender_player_id', $player->id);
            });

        // Loot-crate hostility:
        //   - Player opened a sabotage crate placed by someone else
        //     → counts as an incoming hostile event for the opener.
        //   - Player IS the placer and someone else triggered their
        //     crate → counts as a hostile event to log (they'll see
        //     "your crate fired" in the feed).
        //
        // In both cases we explicitly exclude the placer-opening-
        // their-own-crate path (rejected upstream by the service, but
        // the query guards against stale test data or legacy rows).
        $lootCrateVictimQuery = DB::table('tile_loot_crates')
            ->where('opened_by_player_id', $player->id)
            ->whereNotNull('placed_by_player_id')
            ->whereNotNull('opened_at')
            ->whereColumn('opened_by_player_id', '!=', 'placed_by_player_id');
        $lootCratePlacerQuery = DB::table('tile_loot_crates')
            ->where('placed_by_player_id', $player->id)
            ->whereNotNull('opened_at')
            ->whereColumn('opened_by_player_id', '!=', 'placed_by_player_id');

        if ($since !== null) {
            $attacksQuery->where('created_at', '>', $since);
            $sabotagesQuery->where('triggered_at', '>', $since);
            $tileCombatsQuery->where('created_at', '>', $since);
            $lootCrateVictimQuery->where('opened_at', '>', $since);
            $lootCratePlacerQuery->where('opened_at', '>', $since);
        }

        return $attacksQuery->count()
            + $sabotagesQuery->count()
            + $tileCombatsQuery->count()
            + $lootCrateVictimQuery->count()
            + $lootCratePlacerQuery->count();
    }

    /**
     * Stamp the player's bookmark so all hostile events up to now
     * are treated as read. Called from AttackLogController::show().
     */
    public function markViewed(Player $player): void
    {
        $player->forceFill(['hostility_log_last_viewed_at' => now()])->save();
    }

    /**
     * Chronological feed merging raid attacks, triggered sabotage
     * devices AND tile-combat engagements. All three are rendered by
     * the Vue view as a unified "who did harm to me" list. Entries
     * are discriminated by the `kind` field:
     *
     *   kind=attack       — row came from the `attacks` table
     *                       (someone raided this player's base)
     *   kind=sabotage     — row came from `drill_point_sabotages`
     *                       (this player triggered someone else's device)
     *   kind=tile_combat  — row came from `tile_combats` — this player
     *                       was either the aggressor or the defender
     *                       on a wasteland duel. `role` discriminates.
     *
     * Limit applies per source before the merge, so a player with a
     * very active oil field could see more than $limit total entries.
     * In practice 100+100+100 merged and sliced to 100 is fine for the
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
                // Shared envelope slots — null for non-tile-combat rows.
                'role' => null,
                'oil_stolen' => 0,
                'tile_x' => null,
                'tile_y' => null,
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
                'role' => null,
                'oil_stolen' => 0,
                'tile_x' => null,
                'tile_y' => null,
            ])
            ->all();

        // Tile combat — include rows where the player was EITHER the
        // attacker or defender. `role` tells the renderer which side
        // they were on, so the UI can label it "You attacked X" vs
        // "X attacked you on the road". The attacker's username is
        // surfaced alongside role=defender rows so the dossier-gated
        // feed fulfils its "who ambushed me" purpose.
        //
        // `tiles` is a plain join because tile_combats.tile_id is a
        // hard FK; users is a LEFT JOIN on whichever side of the fight
        // the viewer was NOT on, so a hard-deleted opponent shows up
        // as [deleted] instead of silently dropping.
        $tileCombats = DB::table('tile_combats')
            ->where(function ($q) use ($player) {
                $q->where('attacker_player_id', $player->id)
                    ->orWhere('defender_player_id', $player->id);
            })
            ->join('tiles', 'tiles.id', '=', 'tile_combats.tile_id')
            ->leftJoin('players as atk', 'atk.id', '=', 'tile_combats.attacker_player_id')
            ->leftJoin('players as def', 'def.id', '=', 'tile_combats.defender_player_id')
            ->leftJoin('users as atk_user', 'atk_user.id', '=', 'atk.user_id')
            ->leftJoin('users as def_user', 'def_user.id', '=', 'def.user_id')
            ->orderByDesc('tile_combats.created_at')
            ->limit($limit)
            ->get([
                'tile_combats.id',
                'tile_combats.attacker_player_id',
                'tile_combats.defender_player_id',
                'tile_combats.outcome',
                'tile_combats.oil_stolen',
                'tile_combats.created_at',
                'tiles.x as tile_x',
                'tiles.y as tile_y',
                'atk.id as atk_player_id',
                'def.id as def_player_id',
                'atk_user.name as atk_username',
                'def_user.name as def_username',
            ])
            ->map(function ($row) use ($player) {
                $isDefender = (int) $row->defender_player_id === (int) $player->id;
                $role = $isDefender ? 'defender' : 'attacker';
                // "Opponent" = whoever wasn't $player. This is what the
                // dossier UI shows — "AMBUSHER: Rusty_Vulture" on the
                // defender side; "TARGET: X" on the attacker side.
                $opponentUsername = $isDefender
                    ? ($row->atk_username ?? '[deleted]')
                    : ($row->def_username ?? '[deleted]');
                $opponentPlayerId = $isDefender
                    ? (int) $row->attacker_player_id
                    : (int) $row->defender_player_id;

                return [
                    'kind' => 'tile_combat',
                    'id' => 'tile_combat-'.(int) $row->id,
                    'source_id' => (int) $row->id,
                    'outcome' => (string) $row->outcome,
                    'cash_stolen' => 0.0,
                    'created_at' => $row->created_at,
                    // Reuse attacker_username as the "opponent" slot so
                    // the existing template column works without a
                    // schema widening. The `role` field tells the UI
                    // whether the reader was attacking or defending.
                    'attacker_username' => (string) $opponentUsername,
                    'attacker_player_id' => $opponentPlayerId,
                    'device_key' => null,
                    'siphoned_barrels' => 0,
                    'rig_broken' => false,
                    'role' => $role,
                    'oil_stolen' => (int) $row->oil_stolen,
                    'tile_x' => (int) $row->tile_x,
                    'tile_y' => (int) $row->tile_y,
                ];
            })
            ->all();

        // Loot crate hostility rows — two sources:
        //   kind=loot_crate_victim — the viewing player opened a
        //     sabotage crate someone else planted on a wasteland
        //     tile. Role=victim, attacker_username surfaces the
        //     planter (with the usual LEFT JOIN for deleted users).
        //   kind=loot_crate_placer — the viewing player placed a
        //     sabotage crate and someone else triggered it. Role=
        //     placer, attacker_username is the *opener* (who the
        //     crate hit), so the feed reads "You got X with your
        //     crate".
        //
        // Real crates (placed_by_player_id IS NULL) never appear in
        // this feed — opening a free loot crate isn't hostile and
        // doesn't belong in the Hostility Log.
        $lootCrateVictim = DB::table('tile_loot_crates')
            ->whereNotNull('tile_loot_crates.placed_by_player_id')
            ->whereNotNull('tile_loot_crates.opened_at')
            ->where('tile_loot_crates.opened_by_player_id', $player->id)
            ->whereColumn('tile_loot_crates.opened_by_player_id', '!=', 'tile_loot_crates.placed_by_player_id')
            ->leftJoin('players as placer', 'placer.id', '=', 'tile_loot_crates.placed_by_player_id')
            ->leftJoin('users as placer_user', 'placer_user.id', '=', 'placer.user_id')
            ->orderByDesc('tile_loot_crates.opened_at')
            ->limit($limit)
            ->get([
                'tile_loot_crates.id',
                'tile_loot_crates.device_key',
                'tile_loot_crates.outcome',
                'tile_loot_crates.opened_at as created_at',
                'tile_loot_crates.tile_x',
                'tile_loot_crates.tile_y',
                'placer.id as placer_player_id',
                'placer_user.name as placer_username',
            ])
            ->map(function ($row) {
                $outcome = is_string($row->outcome) ? (array) json_decode($row->outcome, true) : (array) $row->outcome;
                $kind = (string) ($outcome['kind'] ?? '');
                $amount = (float) ($outcome['amount'] ?? 0);

                return [
                    'kind' => 'loot_crate_victim',
                    'id' => 'loot-v-'.(int) $row->id,
                    'source_id' => (int) $row->id,
                    'outcome' => $kind,
                    'cash_stolen' => $kind === 'sabotage_cash' ? $amount : 0.0,
                    'created_at' => $row->created_at,
                    'attacker_username' => (string) ($row->placer_username ?? '[deleted]'),
                    'attacker_player_id' => (int) ($row->placer_player_id ?? 0),
                    'device_key' => (string) $row->device_key,
                    'siphoned_barrels' => $kind === 'sabotage_oil' ? (int) $amount : 0,
                    'rig_broken' => false,
                    'role' => 'victim',
                    'oil_stolen' => $kind === 'sabotage_oil' ? (int) $amount : 0,
                    'tile_x' => (int) $row->tile_x,
                    'tile_y' => (int) $row->tile_y,
                ];
            })
            ->all();

        $lootCratePlacer = DB::table('tile_loot_crates')
            ->where('tile_loot_crates.placed_by_player_id', $player->id)
            ->whereNotNull('tile_loot_crates.opened_at')
            ->whereColumn('tile_loot_crates.opened_by_player_id', '!=', 'tile_loot_crates.placed_by_player_id')
            ->leftJoin('players as opener', 'opener.id', '=', 'tile_loot_crates.opened_by_player_id')
            ->leftJoin('users as opener_user', 'opener_user.id', '=', 'opener.user_id')
            ->orderByDesc('tile_loot_crates.opened_at')
            ->limit($limit)
            ->get([
                'tile_loot_crates.id',
                'tile_loot_crates.device_key',
                'tile_loot_crates.outcome',
                'tile_loot_crates.opened_at as created_at',
                'tile_loot_crates.tile_x',
                'tile_loot_crates.tile_y',
                'opener.id as opener_player_id',
                'opener_user.name as opener_username',
            ])
            ->map(function ($row) {
                $outcome = is_string($row->outcome) ? (array) json_decode($row->outcome, true) : (array) $row->outcome;
                $kind = (string) ($outcome['kind'] ?? '');
                $amount = (float) ($outcome['amount'] ?? 0);

                return [
                    'kind' => 'loot_crate_placer',
                    'id' => 'loot-p-'.(int) $row->id,
                    'source_id' => (int) $row->id,
                    'outcome' => $kind,
                    'cash_stolen' => $kind === 'sabotage_cash' ? $amount : 0.0,
                    'created_at' => $row->created_at,
                    'attacker_username' => (string) ($row->opener_username ?? '[deleted]'),
                    'attacker_player_id' => (int) ($row->opener_player_id ?? 0),
                    'device_key' => (string) $row->device_key,
                    'siphoned_barrels' => $kind === 'sabotage_oil' ? (int) $amount : 0,
                    'rig_broken' => false,
                    'role' => 'placer',
                    'oil_stolen' => $kind === 'sabotage_oil' ? (int) $amount : 0,
                    'tile_x' => (int) $row->tile_x,
                    'tile_y' => (int) $row->tile_y,
                ];
            })
            ->all();

        // Merge chronologically and slice to the overall limit.
        $merged = array_merge($attacks, $sabotages, $tileCombats, $lootCrateVictim, $lootCratePlacer);
        usort($merged, function (array $a, array $b) {
            $ta = is_string($a['created_at']) ? strtotime($a['created_at']) : (int) ($a['created_at']?->timestamp ?? 0);
            $tb = is_string($b['created_at']) ? strtotime($b['created_at']) : (int) ($b['created_at']?->timestamp ?? 0);

            return $tb <=> $ta;
        });

        return array_slice($merged, 0, $limit);
    }
}
