<?php

namespace App\Domain\Bot;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Items\PassiveBonusService;
use App\Domain\Loot\LootCrateService;
use App\Domain\World\FogOfWarService;
use App\Models\Attack;
use App\Models\DrillPoint;
use App\Models\Item;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Post;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Decides what a bot should do next.
 *
 * The planner does NOT execute actions — it only returns a goal
 * descriptor. BotGoalExecutor is the one that travels, drills, shops,
 * etc. Splitting the two keeps the planner pure (queries + priority
 * ladder, no side effects) and makes the executor trivially unit
 * testable against a hand-written goal.
 *
 * ## Priority ladder
 *
 * Goals are picked by a fixed priority, not a weighted roll:
 *
 *   1. raid         — we already have a valid in-window spy, target
 *                     still raid-worthy, no cooldown
 *   2. spy          — known enemy base above cash floor, no in-window
 *                     spy, tier allows it
 *   3. sabotage     — bot owns a deployable, there's a rival-contested
 *                     oil field, tier allows it
 *   3.5 shop-urgent — barrels sit above
 *                     upgrade_threshold × shop_urgent_barrel_multiplier
 *                     AND there's an affordable unowned
 *                     set_drill_tier item at a reachable tech post.
 *                     Stops bots from hoarding barrels while never
 *                     upgrading their rig.
 *   4. drill        — nearest drillable oil field with daily-limit
 *                     headroom. Skipped when
 *                     bot_consecutive_drill_count >=
 *                     bots.force_explore_after_drills, which forces
 *                     the planner to fall through to shop/explore so
 *                     the bot actually uses the rest of the feature
 *                     set instead of camping the nearest field.
 *   5. shop         — barrels above upgrade_threshold AND a reachable
 *                     post sells an upgrade we don't own
 *   6. explore      — fallback: walk a fresh heading and reveal fog
 *
 * Casino tiles are never a valid target. They're filtered out at every
 * nearest*-of-type helper, and the explore step validator rotates
 * heading rather than walking onto one.
 *
 * ## Defensive mode
 *
 * If the bot was the defender in `bots.defensive_mode.recent_attack_threshold`
 * or more attacks within the configured window, it enters defensive
 * mode for as long as those attacks remain inside the window:
 *
 *  - Shop priority flips to fortification → security → drill_tier →
 *    strength → stealth regardless of risk_tolerance.
 *  - Raid goals target the most recent attacker first (revenge),
 *    regardless of whose base holds more cash. Subject to normal
 *    raid gating (immunity, MDN rules, cooldowns, min_target_cash).
 *
 * Defensive mode is derived on every planner call from the `attacks`
 * table — no persistent "mood" column. Flipping is automatic when the
 * relevant rows age out of the window.
 */
class BotGoalPlanner
{
    public const KIND_RAID = 'raid';

    public const KIND_SPY = 'spy';

    public const KIND_SABOTAGE = 'sabotage';

    public const KIND_LOOT_TRAP = 'loot_trap';

    public const KIND_DRILL = 'drill';

    public const KIND_SHOP = 'shop';

    public const KIND_EXPLORE = 'explore';

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly FogOfWarService $fogOfWar,
        private readonly PassiveBonusService $passiveBonus,
    ) {}

    /**
     * Build a fresh goal for the bot, or return null if nothing is
     * viable (shouldn't normally happen — explore is always available).
     *
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    public function pickGoal(Player $bot, array $tierCfg): ?array
    {
        // Priority 0 — Admin-commanded swarm. When the bots:swarm
        // command has stamped a forced raid target on this bot, it
        // overrides every tier gate (including can_raid=false for
        // easy bots) and every normal priority until the bot fires
        // an attack at the target. BotDecisionService clears the
        // column after a completed raid goal so the bot drops back
        // into the normal ladder automatically on the next tick.
        $forced = $this->pickForcedRaidGoal($bot);
        if ($forced !== null) {
            return $forced;
        }

        $defensiveMode = $this->isInDefensiveMode($bot);

        // Priority 1 — Raid (post-spy revenge or opportunity strike).
        if (($tierCfg['can_raid'] ?? false) === true) {
            $raid = $this->pickRaidGoal($bot, $tierCfg, $defensiveMode);
            if ($raid !== null) {
                return $raid;
            }
        }

        // Priority 2 — Spy. Only bother if we don't already have a
        // usable in-window spy hanging around (raid goal above
        // already consumed any viable one).
        if (($tierCfg['can_raid'] ?? false) === true) {
            $spy = $this->pickSpyGoal($bot, $tierCfg, $defensiveMode);
            if ($spy !== null) {
                return $spy;
            }
        }

        // Priority 3 — Sabotage (drill point traps + wasteland loot
        // crate traps). BOTH paths are gated on the strength check:
        // bots that lose attacks regularly should focus on levelling
        // up before harassing other players. The check skips the
        // entire sabotage layer in one shot — a weak bot falls
        // through to shop/drill/explore so its next planner cycle
        // builds it up instead.
        $strongEnoughForSabotage = $this->isStrongEnoughForSabotage($bot);

        if ($strongEnoughForSabotage && ($tierCfg['can_sabotage'] ?? false) === true) {
            $sabotage = $this->pickSabotageGoal($bot);
            if ($sabotage !== null) {
                return $sabotage;
            }
        }

        if ($strongEnoughForSabotage && ($tierCfg['can_place_loot_traps'] ?? false) === true) {
            $lootTrap = $this->pickLootTrapGoal($bot, $tierCfg);
            if ($lootTrap !== null) {
                return $lootTrap;
            }
        }

        // Priority 3.5 — Shop-urgent. Only drill-tier upgrades
        // qualify here (not stat items, not transports) — the point
        // is to stop a bot from sitting on 40k barrels while still
        // running a Dentist Drill.
        $shopUrgent = $this->pickShopUrgentGoal($bot, $tierCfg);
        if ($shopUrgent !== null) {
            return $shopUrgent;
        }

        // Priority 4 — Drill. Skipped when the diversification counter
        // is tripped so the bot falls through to shop → explore and
        // actually engages with the rest of the game instead of
        // camping oil fields.
        $forceExploreAfter = (int) $this->config->get('bots.force_explore_after_drills', 5);
        $drillStreakTripped = (int) $bot->bot_consecutive_drill_count >= $forceExploreAfter;

        if (! $drillStreakTripped) {
            $drill = $this->pickDrillGoal($bot);
            if ($drill !== null) {
                return $drill;
            }
        }

        // Priority 5 — Shop (discretionary).
        $shop = $this->pickShopGoal($bot, $tierCfg, $defensiveMode);
        if ($shop !== null) {
            return $shop;
        }

        // Priority 6 — Explore. Always falls through to this.
        return $this->pickExploreGoal($bot);
    }

    /**
     * Derive defensive mode from the attack history. Defender-side
     * attack rows in the last N hours, threshold from config.
     */
    public function isInDefensiveMode(Player $bot): bool
    {
        $window = (int) $this->config->get('bots.defensive_mode.recent_attack_window_hours', 24);
        $threshold = (int) $this->config->get('bots.defensive_mode.recent_attack_threshold', 2);

        $count = Attack::query()
            ->where('defender_player_id', $bot->id)
            ->where('created_at', '>=', now()->subHours($window))
            ->count();

        return $count >= $threshold;
    }

    /**
     * Most recent attacker in the revenge window, if any. Used to
     * prefer revenge targets over generic high-cash marks when the
     * bot is in defensive mode.
     */
    private function mostRecentAttackerId(Player $bot): ?int
    {
        $window = (int) $this->config->get('bots.defensive_mode.revenge_target_ttl_hours', 12);

        $row = Attack::query()
            ->where('defender_player_id', $bot->id)
            ->where('created_at', '>=', now()->subHours($window))
            ->orderByDesc('created_at')
            ->value('attacker_player_id');

        return $row !== null ? (int) $row : null;
    }

    /**
     * Strength gate for ALL sabotage planner paths. Returns true if
     * the bot has earned the right to harass other players.
     *
     * Two halves combined with OR:
     *
     *   1. Recent attack-history check. If the bot has launched at
     *      least `min_recent_attacks` raids inside the window AND its
     *      win rate is at or above `min_recent_win_rate`, it has
     *      proven combat capability and can sabotage freely.
     *
     *   2. Stat-baseline fallback. If the bot has fewer than the
     *      minimum recent attacks (or none — newly spawned, or
     *      careful play style) we fall back to a raw stat baseline:
     *      strength + fortification at or above the configured
     *      threshold. This stops a quiet but well-equipped bot from
     *      being permanently locked out.
     *
     * Bots failing BOTH halves return false and the planner skips
     * every sabotage layer for that tick, falling through to the
     * shop / drill / explore ladder. Once they level up enough
     * (or rack up wins) the gate naturally re-opens.
     *
     * Public so BotDecisionService and tests can call it for
     * diagnostics — the planner uses it internally too.
     */
    public function isStrongEnoughForSabotage(Player $bot): bool
    {
        $cfg = (array) $this->config->get('bots.sabotage_gate', []);
        $window = (int) ($cfg['recent_window_hours'] ?? 48);
        $minAttacks = (int) ($cfg['min_recent_attacks'] ?? 3);
        $minWinRate = (float) ($cfg['min_recent_win_rate'] ?? 0.5);
        $minStatTotal = (int) ($cfg['min_stat_total_fallback'] ?? 8);

        // Half 1: combat record. Look at the bot's outgoing raids in
        // the window and compute the win rate. The `attacks` table
        // stores `outcome` as a string enum; raids the bot won are
        // tagged 'success' (matches AttackService — kept loose-coupled
        // here to avoid a dependency on the combat namespace).
        $rows = Attack::query()
            ->where('attacker_player_id', $bot->id)
            ->where('created_at', '>=', now()->subHours($window))
            ->get(['outcome']);

        $total = $rows->count();
        if ($total >= $minAttacks) {
            $wins = $rows->filter(fn ($r) => (string) $r->outcome === 'success')->count();
            $rate = $total > 0 ? $wins / $total : 0.0;

            return $rate >= $minWinRate;
        }

        // Half 2: stat baseline fallback. Strength + fortification
        // captures both offensive and defensive readiness, so a bot
        // that has dumped barrels into either side gets credit. We
        // intentionally exclude stealth/security so a pure scout
        // bot can't unlock sabotage without ever fighting.
        $statTotal = (int) $bot->strength + (int) $bot->fortification;

        return $statTotal >= $minStatTotal;
    }

    /**
     * Pick a wasteland tile within the bot's travel range to plant
     * a sabotage loot crate on. Requires:
     *
     *   - At least one owned `crate_siphon_oil` or `crate_siphon_cash`
     *     (deployable_loot_crate effect).
     *   - At least `loot_trap.min_free_slots` free deployment-cap
     *     slots so the bot doesn't fill its allowance with one
     *     plant and starve the next tick.
     *   - A discovered wasteland tile within
     *     `loot_trap.travel_range_tiles` of the bot's current
     *     position that does NOT already have an unopened crate.
     *
     * Bots prefer wasteland tiles closer to enemy bases (raid bait)
     * but if none is in fog-of-war range, they fall back to any
     * eligible wasteland.
     *
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    private function pickLootTrapGoal(Player $bot, array $tierCfg): ?array
    {
        $deviceKey = $this->firstOwnedDeployableLootCrate($bot->id);
        if ($deviceKey === null) {
            return null;
        }

        // Free-slot check via the same service the human-facing
        // deploy guard uses. Resolved inline rather than injected to
        // avoid bloating the planner constructor; this only fires for
        // sabotage-eligible bots so the call cost is negligible.
        /** @var LootCrateService $lootCrates */
        $lootCrates = app(LootCrateService::class);
        $cap = $lootCrates->deploymentCap();
        $current = $lootCrates->currentlyDeployedCount((int) $bot->id);
        $minFree = (int) $this->config->get('bots.loot_trap.min_free_slots', 2);
        if ($cap - $current < $minFree) {
            return null;
        }

        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        /** @var Tile|null $currentTile */
        $currentTile = Tile::query()->find($bot->current_tile_id);
        if ($currentTile === null) {
            return null;
        }

        $range = (int) $this->config->get('bots.loot_trap.travel_range_tiles', 12);

        // Eligible wasteland tiles: discovered, within range, no
        // already-deployed crate. We exclude the bot's current tile
        // explicitly — placing a trap and immediately walking off
        // is fine, but the executor handles arrival-then-place in
        // one step so we don't need a special-case.
        $candidateTiles = Tile::query()
            ->whereIn('id', $discovered)
            ->where('type', 'wasteland')
            ->get(['id', 'x', 'y']);

        if ($candidateTiles->isEmpty()) {
            return null;
        }

        // Drop tiles already holding a crate (real or sabotage —
        // one slot per tile). Single query keyed by (x,y) to avoid
        // N+1.
        $tileKeysWithCrate = DB::table('tile_loot_crates')
            ->whereNull('opened_at')
            ->whereIn('tile_x', $candidateTiles->pluck('x')->all())
            ->whereIn('tile_y', $candidateTiles->pluck('y')->all())
            ->select('tile_x', 'tile_y')
            ->get()
            ->map(fn ($r) => $r->tile_x.':'.$r->tile_y)
            ->all();
        $occupied = array_flip($tileKeysWithCrate);

        $eligible = $candidateTiles->filter(function (Tile $t) use ($currentTile, $range, $occupied) {
            $dist = abs($t->x - $currentTile->x) + abs($t->y - $currentTile->y);
            if ($dist > $range) {
                return false;
            }

            return ! isset($occupied[$t->x.':'.$t->y]);
        });

        if ($eligible->isEmpty()) {
            return null;
        }

        // Closest first — same heuristic as nearestDiscoveredPostOfType,
        // and stops a bot from marching across the map for one trap.
        /** @var Tile|null $chosen */
        $chosen = $eligible
            ->sortBy(fn (Tile $t) => abs($t->x - $currentTile->x) + abs($t->y - $currentTile->y))
            ->first();

        if ($chosen === null) {
            return null;
        }

        return [
            'kind' => self::KIND_LOOT_TRAP,
            'tile_id' => (int) $chosen->id,
            'item_key' => $deviceKey,
        ];
    }

    /**
     * First deployable_loot_crate item in the bot's active inventory,
     * or null. Mirrors firstOwnedDeployable but keys on the
     * loot-crate effect rather than the drill-sabotage effect.
     */
    private function firstOwnedDeployableLootCrate(int $playerId): ?string
    {
        $rows = DB::table('player_items')
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->where('player_items.player_id', $playerId)
            ->where('player_items.status', 'active')
            ->where('player_items.quantity', '>', 0)
            ->whereNotNull('items_catalog.effects')
            ->select('items_catalog.key', 'items_catalog.effects')
            ->get();

        foreach ($rows as $row) {
            $effects = is_string($row->effects) ? json_decode($row->effects, true) : (array) $row->effects;
            if (is_array($effects) && isset($effects['deployable_loot_crate'])) {
                return (string) $row->key;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Goal pickers
    // ------------------------------------------------------------------

    /**
     * Admin-commanded swarm goal: when the bots:swarm artisan
     * command has stamped a forced raid target on this bot, return
     * a raid goal (if a valid in-window spy exists) or a spy goal
     * (to refresh intel first) pointed at the victim. Bypasses
     * tier can_raid flags — even an easy bot will hunt the target.
     *
     * Returns null when:
     *   - No forced target set.
     *   - Forced TTL has expired → column is left dirty for
     *     BotDecisionService::tick to sweep; planner falls through
     *     to the normal ladder so the bot doesn't stall.
     *   - Target no longer exists, is immune, is in the same MDN,
     *     or their base tile has been deleted. In all these cases
     *     we leave the column set so tick() can decide whether to
     *     clear it via the "raid goal completed" hook; the bot
     *     falls through to the normal ladder for the current tick.
     *
     * @return array<string,mixed>|null
     */
    private function pickForcedRaidGoal(Player $bot): ?array
    {
        $forcedTargetId = $bot->bot_forced_raid_target_player_id;
        if ($forcedTargetId === null) {
            return null;
        }

        // TTL expired → treat as cleared, let the normal ladder run
        // this tick. BotDecisionService::tick() clears the column
        // after the first successful raid against the forced target;
        // the TTL sweep lives there too for the "target permanently
        // unreachable" case.
        if ($bot->bot_forced_raid_expires_at !== null
            && $bot->bot_forced_raid_expires_at->isPast()) {
            return null;
        }

        /** @var Player|null $target */
        $target = Player::query()->find((int) $forcedTargetId);
        if ($target === null) {
            return null;
        }

        // Immune targets: hold position. The planner can't bypass
        // immunity on the server side (AttackService would throw)
        // so there's no point returning a raid goal while the
        // target is protected. Bot falls through to normal ladder
        // this tick; the swarm column stays set so the next tick
        // re-evaluates once immunity expires.
        if ($target->immunity_expires_at !== null
            && $target->immunity_expires_at->isFuture()) {
            return null;
        }

        // Same MDN gate: respected even under swarm. Spec rule
        // says same-MDN attacks are blocked — the command doesn't
        // override that.
        if ($bot->mdn_id !== null && (int) $target->mdn_id === (int) $bot->mdn_id) {
            return null;
        }

        /** @var Tile|null $baseTile */
        $baseTile = Tile::query()->find($target->base_tile_id);
        if ($baseTile === null || $baseTile->type === 'casino') {
            return null;
        }

        // Raid cooldown: if we already attacked this target within the
        // cooldown window, AttackService will reject the attempt.
        // Filter here so the bot doesn't spin on a doomed goal.
        $raidCooldownHours = (int) $this->config->get('combat.raid_cooldown_hours', 12);
        if ($raidCooldownHours > 0) {
            $onCooldown = Attack::query()
                ->where('attacker_player_id', $bot->id)
                ->where('defender_player_id', (int) $forcedTargetId)
                ->where('created_at', '>=', now()->subHours($raidCooldownHours))
                ->exists();

            if ($onCooldown) {
                return null;
            }
        }

        // Is there a valid in-window spy on this target? If yes,
        // return a raid goal. If no, return a spy goal (the bot
        // will walk to the base and spy, then re-plan and raid on
        // the next tick).
        $spyDecayHours = (int) $this->config->get('combat.spy_decay_hours', 24);

        /** @var SpyAttempt|null $spy */
        $spy = SpyAttempt::query()
            ->where('spy_player_id', $bot->id)
            ->where('target_player_id', $target->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($spyDecayHours))
            ->orderByDesc('created_at')
            ->first();

        if ($spy !== null) {
            return [
                'kind' => self::KIND_RAID,
                'tile_id' => (int) $target->base_tile_id,
                'target_player_id' => (int) $target->id,
                'spy_id' => (int) $spy->id,
                'defensive_revenge' => false,
                'forced_swarm' => true,
            ];
        }

        return [
            'kind' => self::KIND_SPY,
            'tile_id' => (int) $target->base_tile_id,
            'target_player_id' => (int) $target->id,
            'defensive_revenge' => false,
            'forced_swarm' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    private function pickRaidGoal(Player $bot, array $tierCfg, bool $defensiveMode): ?array
    {
        $spyDecayHours = (int) $this->config->get('combat.spy_decay_hours', 24);

        /** @var SpyAttempt|null $spy */
        $spy = SpyAttempt::query()
            ->where('spy_player_id', $bot->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($spyDecayHours))
            ->orderByDesc('created_at')
            ->first();

        if ($spy === null) {
            return null;
        }

        // Validate that the mark is still raid-worthy right now: not
        // immune, not in our MDN, still above cash floor, not a casino
        // tile (shouldn't ever be, but belt and braces).
        $target = Player::query()->find($spy->target_player_id);
        if ($target === null) {
            return null;
        }

        if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
            return null;
        }
        if ($bot->mdn_id !== null && $target->mdn_id === $bot->mdn_id) {
            return null;
        }
        $minCash = (float) ($tierCfg['min_target_cash'] ?? 5.0);
        if ((float) $target->akzar_cash < $minCash) {
            return null;
        }

        $raidCooldownHours = (int) $this->config->get('combat.raid_cooldown_hours', 12);
        if ($raidCooldownHours > 0) {
            $onCooldown = Attack::query()
                ->where('attacker_player_id', $bot->id)
                ->where('defender_player_id', $target->id)
                ->where('created_at', '>=', now()->subHours($raidCooldownHours))
                ->exists();

            if ($onCooldown) {
                return null;
            }
        }

        $targetTile = Tile::query()->find($target->base_tile_id);
        if ($targetTile === null || $targetTile->type === 'casino') {
            return null;
        }

        return [
            'kind' => self::KIND_RAID,
            'tile_id' => (int) $target->base_tile_id,
            'target_player_id' => (int) $target->id,
            'spy_id' => (int) $spy->id,
            'defensive_revenge' => $defensiveMode
                && $this->mostRecentAttackerId($bot) === (int) $target->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    private function pickSpyGoal(Player $bot, array $tierCfg, bool $defensiveMode): ?array
    {
        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        $minCash = (float) ($tierCfg['min_target_cash'] ?? 5.0);
        $revengeAttackerId = $defensiveMode ? $this->mostRecentAttackerId($bot) : null;
        $spyCooldownHours = (int) $this->config->get('combat.spy.cooldown_hours', 12);

        // Targets we've spied (success OR failure) inside the cooldown
        // window are off-limits — SpyService will throw inCooldown()
        // and BotGoalExecutor will invalidate the goal. Filter them
        // out at planning time so we don't burn a tick on a doomed pick.
        $spyCooldownTargetIds = $spyCooldownHours > 0
            ? SpyAttempt::query()
                ->where('spy_player_id', $bot->id)
                ->where('created_at', '>=', now()->subHours($spyCooldownHours))
                ->pluck('target_player_id')
                ->all()
            : [];

        $query = Player::query()
            ->whereIn('base_tile_id', $discovered)
            ->where('id', '!=', $bot->id)
            ->where('akzar_cash', '>=', $minCash)
            ->when($spyCooldownTargetIds !== [], fn ($q) => $q->whereNotIn('id', $spyCooldownTargetIds))
            ->where(function ($q) {
                $q->whereNull('immunity_expires_at')
                    ->orWhere('immunity_expires_at', '<', now());
            })
            ->when($bot->mdn_id !== null, fn ($q) => $q->where(function ($q2) use ($bot) {
                $q2->whereNull('mdn_id')->orWhere('mdn_id', '!=', $bot->mdn_id);
            }));

        // Defensive mode: prefer the revenge target if it's still
        // reachable and raid-eligible. Falls through to cash-sorted
        // list if the attacker moved outside the discovered area or
        // went immune.
        if ($revengeAttackerId !== null) {
            $revenge = (clone $query)->where('id', $revengeAttackerId)->first();
            if ($revenge !== null) {
                $tile = Tile::query()->find($revenge->base_tile_id);
                if ($tile !== null && $tile->type !== 'casino') {
                    return [
                        'kind' => self::KIND_SPY,
                        'tile_id' => (int) $revenge->base_tile_id,
                        'target_player_id' => (int) $revenge->id,
                        'defensive_revenge' => true,
                    ];
                }
            }
        }

        /** @var Player|null $target */
        $target = $query->orderByDesc('akzar_cash')->first();
        if ($target === null) {
            return null;
        }

        $tile = Tile::query()->find($target->base_tile_id);
        if ($tile === null || $tile->type === 'casino') {
            return null;
        }

        return [
            'kind' => self::KIND_SPY,
            'tile_id' => (int) $target->base_tile_id,
            'target_player_id' => (int) $target->id,
            'defensive_revenge' => false,
        ];
    }

    /**
     * Pick a contested oil field to plant a device on. "Contested" =
     * rival (non-self) player_drill_counts rows for the field inside
     * the configured window, at least min_rival_hits of them.
     *
     * @return array<string,mixed>|null
     */
    private function pickSabotageGoal(Player $bot): ?array
    {
        // Must own a deployable to even consider this goal. Resolved
        // in PHP rather than a JSON query so MariaDB doesn't trip on
        // MySQL 8-only JSON_SEARCH syntax (same reason
        // SabotageService::playerOwnsScanner iterates in PHP).
        $deviceKey = $this->firstOwnedDeployable($bot->id);
        if ($deviceKey === null) {
            return null;
        }

        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        $windowHours = (int) $this->config->get('bots.sabotage.rival_drill_window_hours', 24);
        $minHits = (int) $this->config->get('bots.sabotage.min_rival_hits', 2);

        // Oil fields we can see, where rivals have drilled recently.
        $candidateFieldIds = DB::table('player_drill_counts as pdc')
            ->join('oil_fields as of', 'of.id', '=', 'pdc.oil_field_id')
            ->whereIn('of.tile_id', $discovered)
            ->where('pdc.player_id', '!=', $bot->id)
            ->where('pdc.updated_at', '>=', now()->subHours($windowHours))
            ->select('pdc.oil_field_id', DB::raw('SUM(pdc.drill_count) as rival_hits'))
            ->groupBy('pdc.oil_field_id')
            ->having('rival_hits', '>=', $minHits)
            ->orderByDesc('rival_hits')
            ->pluck('pdc.oil_field_id')
            ->all();

        if ($candidateFieldIds === []) {
            return null;
        }

        // Pick the nearest of those fields. Rival-hit ranking is
        // pre-filtered above; distance is the tiebreaker so the bot
        // doesn't march across the map to plant a single device.
        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return null;
        }

        /** @var OilField|null $chosen */
        $chosen = OilField::query()
            ->whereIn('id', $candidateFieldIds)
            ->with('tile')
            ->get()
            ->sortBy(function (OilField $f) use ($current) {
                $tile = $f->tile;
                if ($tile === null) {
                    return PHP_INT_MAX;
                }

                return abs($tile->x - $current->x) + abs($tile->y - $current->y);
            })
            ->first();

        if ($chosen === null || $chosen->tile === null || $chosen->tile->type === 'casino') {
            return null;
        }

        // Pick an unrigged, undrilled drill point. If all are rigged
        // or all are drilled, skip — nothing to plant on.
        $point = DrillPoint::query()
            ->where('oil_field_id', $chosen->id)
            ->whereNull('drilled_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('drill_point_sabotages')
                    ->whereColumn('drill_point_sabotages.drill_point_id', 'drill_points.id')
                    ->whereNull('drill_point_sabotages.triggered_at');
            })
            ->orderByRaw("FIELD(quality, 'gusher', 'standard', 'trickle', 'dry')")
            ->first();

        if ($point === null) {
            return null;
        }

        return [
            'kind' => self::KIND_SABOTAGE,
            'tile_id' => (int) $chosen->tile->id,
            'oil_field_id' => (int) $chosen->id,
            'grid_x' => (int) $point->grid_x,
            'grid_y' => (int) $point->grid_y,
            'device_key' => $deviceKey,
        ];
    }

    /**
     * Shop-urgent: promote a drill-tier upgrade above drilling itself
     * when the bot is clearly stockpiling. Narrower than the regular
     * pickShopGoal:
     *
     *   - Gated on barrels ≥ upgrade_threshold × shop_urgent_barrel_multiplier
     *     so it fires only when the bot has an obvious surplus.
     *   - Only `set_drill_tier` items qualify (not stat items, not
     *     transports, not drill-yield passives). The point is raw drill
     *     capability — stats and transports stay in the discretionary
     *     shop layer.
     *   - Target drill_tier must strictly exceed current drill_tier so
     *     the bot never "upgrades" sideways or downwards.
     *   - Needs a discovered tech post. If none is in fog yet, the
     *     bot will fall through to drill (still earns barrels) or
     *     eventually explore (finds one).
     *
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    private function pickShopUrgentGoal(Player $bot, array $tierCfg): ?array
    {
        $baseThreshold = (int) ($tierCfg['upgrade_threshold_barrels'] ?? 300);
        $multiplier = (float) $this->config->get('bots.shop_urgent_barrel_multiplier', 2.0);
        $gate = (int) ceil($baseThreshold * $multiplier);

        if ((int) $bot->oil_barrels < $gate) {
            return null;
        }

        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return null;
        }

        $post = $this->nearestDiscoveredPostOfType($bot, $discovered, $current, 'tech');
        if ($post === null) {
            return null;
        }

        // Walk tech items, most expensive first, and pick the first
        // affordable, unowned, strictly-higher-tier drill. Same
        // affordability rules as the discretionary shop path.
        $items = Item::query()
            ->where('post_type', 'tech')
            ->orderByDesc('price_barrels')
            ->get();

        $ownedKeys = DB::table('player_items')
            ->where('player_id', $bot->id)
            ->where('status', 'active')
            ->pluck('item_key')
            ->all();

        foreach ($items as $item) {
            if (in_array($item->key, $ownedKeys, true)) {
                continue;
            }

            $effects = is_array($item->effects) ? $item->effects : [];
            if (! isset($effects['set_drill_tier'])) {
                continue;
            }
            if ((int) $effects['set_drill_tier'] <= (int) $bot->drill_tier) {
                continue;
            }

            if ((int) $bot->oil_barrels < (int) $item->price_barrels) {
                continue;
            }
            if ((float) $bot->akzar_cash < (float) $item->price_cash) {
                continue;
            }
            if ((int) $bot->intel < (int) $item->price_intel) {
                continue;
            }

            return [
                'kind' => self::KIND_SHOP,
                'tile_id' => (int) $post->tile_id,
                'post_type' => 'tech',
                'want_item' => (string) $item->key,
                'urgent' => true,
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function pickDrillGoal(Player $bot): ?array
    {
        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return null;
        }

        $dailyLimit = (int) $this->config->get('drilling.daily_limit_per_field', 5);
        $dailyLimit += $this->passiveBonus->drillLimitBonus($bot);
        $today = now()->toDateString();

        $candidates = Tile::query()
            ->whereIn('id', $discovered)
            ->where('type', 'oil_field')
            ->get();

        /** @var Tile|null $nearest */
        $nearest = $candidates
            ->filter(function (Tile $t) use ($bot, $dailyLimit, $today) {
                $field = OilField::query()->where('tile_id', $t->id)->first();
                if ($field === null) {
                    return false;
                }
                $hasPoints = DrillPoint::query()
                    ->where('oil_field_id', $field->id)
                    ->whereNull('drilled_at')
                    ->exists();
                if (! $hasPoints) {
                    return false;
                }
                $dailyCount = (int) DB::table('player_drill_counts')
                    ->where('player_id', $bot->id)
                    ->where('oil_field_id', $field->id)
                    ->where('drill_date', $today)
                    ->value('drill_count');

                return $dailyCount < $dailyLimit;
            })
            ->sortBy(fn (Tile $t) => abs($t->x - $current->x) + abs($t->y - $current->y))
            ->first();

        if ($nearest === null) {
            return null;
        }

        return [
            'kind' => self::KIND_DRILL,
            'tile_id' => (int) $nearest->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     * @return array<string,mixed>|null
     */
    private function pickShopGoal(Player $bot, array $tierCfg, bool $defensiveMode): ?array
    {
        $threshold = (int) ($tierCfg['upgrade_threshold_barrels'] ?? 300);
        if ((int) $bot->oil_barrels < $threshold) {
            return null;
        }

        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        // Shop priority ordering. Defensive mode always flips to
        // fort/sec first. Otherwise risk_tolerance drives it:
        //   > 0.6 → offensive (tech → strength)
        //   < 0.4 → defensive (fort → security)
        //   mid  → balanced (tech → fort → strength → security → stealth)
        //
        // The 'general' post slot is appended at the end of every
        // ordering — sabotage gear and loot crate deployables are
        // expensive sundries and should be the LAST thing a bot
        // saves up for, not displace stat or drill upgrades. Bots
        // only consider general-store sabotage gear when they're
        // already strong enough (gated upstream) AND have nothing
        // better to spend barrels on at the priority post types.
        $risk = (float) ($tierCfg['risk_tolerance'] ?? 0.5);
        $priority = match (true) {
            $defensiveMode => ['fort', 'security', 'tech', 'strength', 'stealth', 'general'],
            $risk > 0.6 => ['tech', 'strength', 'stealth', 'fort', 'security', 'general'],
            $risk < 0.4 => ['fort', 'security', 'tech', 'stealth', 'strength', 'general'],
            default => ['tech', 'fort', 'strength', 'security', 'stealth', 'general'],
        };

        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return null;
        }

        // Walk the priority list, stop at the first post_type where
        // the bot both has a reachable post AND at least one affordable
        // upgrade it doesn't already own.
        foreach ($priority as $postType) {
            $post = $this->nearestDiscoveredPostOfType($bot, $discovered, $current, $postType);
            if ($post === null) {
                continue;
            }

            $wantItem = $this->firstAffordableUpgradeFor($bot, $postType, $defensiveMode);
            if ($wantItem === null) {
                continue;
            }

            return [
                'kind' => self::KIND_SHOP,
                'tile_id' => (int) $post->tile_id,
                'post_type' => $postType,
                'want_item' => $wantItem,
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function pickExploreGoal(Player $bot): ?array
    {
        $budget = max(1, (int) $this->config->get('bots.explore_budget_tiles', 15));
        $heading = $this->rollFreshHeading($bot);

        return [
            'kind' => self::KIND_EXPLORE,
            'heading' => $heading,
            'tiles_remaining' => $budget,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * First deployable_sabotage item in the bot's active inventory, or
     * null. PHP iteration over effects JSON (MariaDB-safe).
     */
    private function firstOwnedDeployable(int $playerId): ?string
    {
        $rows = DB::table('player_items')
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->where('player_items.player_id', $playerId)
            ->where('player_items.status', 'active')
            ->where('player_items.quantity', '>', 0)
            ->whereNotNull('items_catalog.effects')
            ->select('items_catalog.key', 'items_catalog.effects')
            ->get();

        foreach ($rows as $row) {
            $effects = is_string($row->effects) ? json_decode($row->effects, true) : (array) $row->effects;
            if (is_array($effects) && isset($effects['deployable_sabotage'])) {
                return (string) $row->key;
            }
        }

        return null;
    }

    /**
     * Nearest discovered post of a given post_type (not tile type) — we
     * resolve through the posts table so "strength" means "strength
     * post" even though the tile type is just 'post'. Casino tiles are
     * excluded via the join (posts aren't placed on casino tiles
     * anyway, but cheap defence).
     */
    private function nearestDiscoveredPostOfType(
        Player $bot,
        array $discoveredTileIds,
        Tile $current,
        string $postType,
    ): ?Post {
        /** @var Post|null $nearest */
        $nearest = Post::query()
            ->join('tiles', 'tiles.id', '=', 'posts.tile_id')
            ->whereIn('posts.tile_id', $discoveredTileIds)
            ->where('posts.post_type', $postType)
            ->where('tiles.type', '!=', 'casino')
            ->select('posts.*', 'tiles.x as tx', 'tiles.y as ty')
            ->get()
            ->sortBy(fn ($p) => abs($p->tx - $current->x) + abs($p->ty - $current->y))
            ->first();

        return $nearest;
    }

    /**
     * First item at a given post_type the bot can afford AND doesn't
     * already own AND qualifies as an upgrade (stat_add, set_drill_tier,
     * unlocks_transport, or daily_drill_limit_bonus). Sorted descending
     * by price so bots save up for the biggest upgrade they can swing.
     *
     * General-store special case: when the post type is 'general',
     * sabotage deployables (`deployable_sabotage`, `deployable_loot_crate`)
     * also count as upgrade-worthy IF the bot is strong enough for
     * sabotage AND doesn't already have a healthy stockpile. The
     * stockpile cap stops bots from converting their entire barrel
     * reserve into traps.
     */
    private function firstAffordableUpgradeFor(Player $bot, string $postType, bool $defensiveMode = false): ?string
    {
        $items = Item::query()
            ->where('post_type', $postType)
            ->orderByDesc('price_barrels')
            ->get();

        $ownedRows = DB::table('player_items')
            ->where('player_id', $bot->id)
            ->where('status', 'active')
            ->get(['item_key', 'quantity']);
        $ownedKeys = $ownedRows->pluck('item_key')->all();
        $ownedQuantities = $ownedRows->pluck('quantity', 'item_key')->all();

        // Stockpile caps for stackable consumables, sourced from
        // config so admins can tune bot hoarding without a deploy.
        // Defaults kept matching the original inline constants.
        $sabotageStockpileCap = (int) $this->config->get('bots.stockpile_caps.sabotage_deployables', 3);
        $foundationChargeStockpileCap = (int) $this->config->get('bots.stockpile_caps.foundation_charge', 2);

        $strongEnough = $this->isStrongEnoughForSabotage($bot);

        foreach ($items as $item) {
            $effects = is_array($item->effects) ? $item->effects : [];

            $isStat = isset($effects['stat_add']);
            $isDrillTier = isset($effects['set_drill_tier']);
            $isTransport = isset($effects['unlocks_transport']);
            $isDailyLimit = isset($effects['daily_drill_limit_bonus']);
            $isDeployableSabotage = isset($effects['deployable_sabotage']);
            $isDeployableLootCrate = isset($effects['deployable_loot_crate']);

            // Base teleport items. Homing Flare is a cheap,
            // always-useful tool — grab it once the bot can afford
            // it BUT kept out of $isUpgrade so the ordering sort
            // doesn't accidentally rank it above Deep Scanner or
            // other general-store passives. Deadbolt Plinth and
            // Foundation Charge are panic-buys: only considered in
            // defensive mode. Abduction Anchor is deliberately
            // excluded from v1 bot AI — the spy prerequisite +
            // target selection is noisy enough that teaching the
            // planner to use it correctly is a phase 2 job. Left
            // commented so it's obvious we skipped on purpose
            // rather than forgot.
            $isHomingFlare = ($effects['unlocks_base_teleport'] ?? false) === true;
            $isDeadboltPlinth = ($effects['grant_base_move_protection'] ?? false) === true;
            $baseMoveKind = (string) ($effects['deployable_base_move'] ?? '');
            $isFoundationCharge = $baseMoveKind === 'self';
            // $isAbductionAnchor = $baseMoveKind === 'enemy'; // skipped for v1

            $isUpgrade = $isStat || $isDrillTier || $isTransport || $isDailyLimit;
            $isSabotageGear = $isDeployableSabotage || $isDeployableLootCrate;
            $isDefensiveBuy = ($isDeadboltPlinth || $isFoundationCharge) && $defensiveMode;

            if (! $isUpgrade && ! $isSabotageGear && ! $isDefensiveBuy && ! $isHomingFlare) {
                continue;
            }

            // Sabotage gear is only purchase-eligible when the bot
            // is strong enough AND under the per-deployable stockpile
            // cap. Both checks bypass the "in_array ownedKeys" skip
            // below because deployables are stackable consumables.
            if ($isSabotageGear) {
                if (! $strongEnough) {
                    continue;
                }
                $owned = (int) ($ownedQuantities[$item->key] ?? 0);
                if ($owned >= $sabotageStockpileCap) {
                    continue;
                }
            } elseif ($isFoundationCharge) {
                // Stackable panic-buy: separate cap from sabotage
                // stockpile so a defensive bot can still grab a
                // charge even while sitting on 3 Gremlin Coils.
                $owned = (int) ($ownedQuantities[$item->key] ?? 0);
                if ($owned >= $foundationChargeStockpileCap) {
                    continue;
                }
            } elseif (in_array($item->key, $ownedKeys, true)) {
                // Non-stackable upgrades (including Homing Flare, which
                // is a single-purchase reusable tool): never re-buy.
                continue;
            }

            if ((int) $bot->oil_barrels < (int) $item->price_barrels) {
                continue;
            }
            if ((float) $bot->akzar_cash < (float) $item->price_cash) {
                continue;
            }
            if ((int) $bot->intel < (int) $item->price_intel) {
                continue;
            }

            // set_drill_tier: never let a bot "upgrade" to a lower tier
            // (can happen because orderByDesc on price_barrels doesn't
            // strictly correlate with drill_tier across overlapping
            // catalogs).
            if ($isDrillTier
                && (int) $effects['set_drill_tier'] <= (int) $bot->drill_tier) {
                continue;
            }

            return (string) $item->key;
        }

        return null;
    }

    /**
     * Explore heading selection. Rolls a direction and, if the tile
     * one step that way is off-world, rotates 90° until a valid
     * neighbour is found. Casino tiles are NOT avoided — bots walk
     * over them like any other tile. The "never gamble" rule is
     * enforced at goal-target filters, not at heading selection.
     * Falls back to the raw roll if every neighbour is off-world
     * (shouldn't be possible unless the bot is standing in the
     * middle of nowhere on a broken map).
     */
    private function rollFreshHeading(Player $bot): string
    {
        $dirs = ['n', 'e', 's', 'w'];
        $current = Tile::query()->find($bot->current_tile_id);
        if ($current === null) {
            return 'n';
        }

        // Deterministic pseudo-random ordering starting from a tile-
        // derived offset. We don't need RngService here — this is pure
        // heading selection with zero economy impact, and threading
        // RngService into the planner just to shuffle 4 entries bloats
        // the constructor with no audit value.
        $offset = ((int) $bot->id + (int) $current->x + (int) $current->y) % 4;
        $rotated = array_merge(array_slice($dirs, $offset), array_slice($dirs, 0, $offset));

        foreach ($rotated as $dir) {
            $neighbour = $this->neighbourTile($current, $dir);
            if ($neighbour === null) {
                continue;
            }

            return $dir;
        }

        return $rotated[0];
    }

    private function neighbourTile(Tile $from, string $direction): ?Tile
    {
        [$dx, $dy] = match ($direction) {
            'n' => [0, 1],
            's' => [0, -1],
            'e' => [1, 0],
            'w' => [-1, 0],
            default => [0, 0],
        };

        return Tile::query()
            ->where('x', $from->x + $dx)
            ->where('y', $from->y + $dy)
            ->first();
    }
}
