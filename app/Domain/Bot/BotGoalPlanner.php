<?php

namespace App\Domain\Bot;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Items\PassiveBonusService;
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
 *   1. raid     — we already have a valid in-window spy, target still
 *                 raid-worthy, no cooldown
 *   2. spy      — known enemy base above cash floor, no in-window spy,
 *                 tier allows it
 *   3. sabotage — bot owns a deployable, there's a rival-contested oil
 *                 field, tier allows it
 *   4. drill    — nearest drillable oil field with daily-limit headroom
 *   5. shop     — barrels above upgrade_threshold AND a reachable post
 *                 sells an upgrade we don't own
 *   6. explore  — fallback: walk a fresh heading and reveal fog
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

        // Priority 3 — Sabotage. Skipped unless the bot owns a device
        // AND the tier allows it.
        if (($tierCfg['can_sabotage'] ?? false) === true) {
            $sabotage = $this->pickSabotageGoal($bot);
            if ($sabotage !== null) {
                return $sabotage;
            }
        }

        // Priority 4 — Drill.
        $drill = $this->pickDrillGoal($bot);
        if ($drill !== null) {
            return $drill;
        }

        // Priority 5 — Shop.
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

    // ------------------------------------------------------------------
    // Goal pickers
    // ------------------------------------------------------------------

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

        $query = Player::query()
            ->whereIn('base_tile_id', $discovered)
            ->where('id', '!=', $bot->id)
            ->where('akzar_cash', '>=', $minCash)
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
        $risk = (float) ($tierCfg['risk_tolerance'] ?? 0.5);
        $priority = match (true) {
            $defensiveMode       => ['fort', 'security', 'tech', 'strength', 'stealth'],
            $risk > 0.6          => ['tech', 'strength', 'stealth', 'fort', 'security'],
            $risk < 0.4          => ['fort', 'security', 'tech', 'stealth', 'strength'],
            default              => ['tech', 'fort', 'strength', 'security', 'stealth'],
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

            $wantItem = $this->firstAffordableUpgradeFor($bot, $postType);
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
     */
    private function firstAffordableUpgradeFor(Player $bot, string $postType): ?string
    {
        $items = Item::query()
            ->where('post_type', $postType)
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
            if ((int) $bot->oil_barrels < (int) $item->price_barrels) {
                continue;
            }
            if ((float) $bot->akzar_cash < (float) $item->price_cash) {
                continue;
            }
            if ((int) $bot->intel < (int) $item->price_intel) {
                continue;
            }

            $effects = is_array($item->effects) ? $item->effects : [];
            $isUpgrade = isset($effects['stat_add'])
                || isset($effects['set_drill_tier'])
                || isset($effects['unlocks_transport'])
                || isset($effects['daily_drill_limit_bonus']);

            if (! $isUpgrade) {
                continue;
            }

            // set_drill_tier: never let a bot "upgrade" to a lower tier
            // (can happen because orderByDesc on price_barrels doesn't
            // strictly correlate with drill_tier across overlapping
            // catalogs).
            if (isset($effects['set_drill_tier'])
                && (int) $effects['set_drill_tier'] <= (int) $bot->drill_tier) {
                continue;
            }

            return (string) $item->key;
        }

        return null;
    }

    /**
     * Explore heading selection. Rolls a direction and, if the tile one
     * step that way is a casino, rotates 90° until a non-casino
     * neighbour is found. Falls back to the original roll if every
     * neighbour is a casino (shouldn't be possible on a real map).
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
            if ($neighbour->type === 'casino') {
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
