<?php

namespace App\Domain\Loot;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotOpenLootCrateException;
use App\Domain\Notifications\ActivityLogService;
use App\Events\LootCrateOpened;
use App\Events\SabotageLootCrateTriggered;
use App\Models\Item;
use App\Models\Player;
use App\Models\Tile;
use App\Models\TileLootCrate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Loot crate lifecycle — spawn, place, open, decline.
 *
 * Two distinct crate kinds sharing the same DB table:
 *
 *   - Real crates (placed_by_player_id NULL): spontaneously spawned
 *     by the travel hook when a player arrives on a wasteland tile.
 *     Persist until opened. Reward table rolled at open time.
 *
 *   - Sabotage crates (placed_by_player_id SET): deployed by a player
 *     from their toolbox. Look identical to real crates. Siphon a
 *     random percentage of the opener's oil or cash when triggered,
 *     credited directly to the placer. Immune openers consume the
 *     crate with no effect but the placer is still notified.
 *
 * Every branch runs inside a DB transaction with lockForUpdate on the
 * involved rows (tile bucket, crate, player, placer). Real-crate
 * spawning and open-by-another-player races are both handled this way.
 *
 * RNG audit contexts:
 *   loot.spawn            — 1% arrival roll
 *   loot.outcome          — real crate nothing/oil/cash/item pick
 *   loot.outcome.oil      — oil amount (low-weighted)
 *   loot.outcome.cash     — cash amount (low-weighted)
 *   loot.outcome.item     — inverse-price item pick
 *   loot.sabotage.pct     — siphon percentage (uniform)
 */
class LootCrateService
{
    public const OUTCOME_NOTHING = 'nothing';

    public const OUTCOME_OIL = 'oil';

    public const OUTCOME_CASH = 'cash';

    public const OUTCOME_ITEM = 'item';

    public const OUTCOME_ITEM_DUPE = 'item_dupe';

    public const OUTCOME_SABOTAGE_OIL = 'sabotage_oil';

    public const OUTCOME_SABOTAGE_CASH = 'sabotage_cash';

    public const OUTCOME_IMMUNE_NO_EFFECT = 'immune_no_effect';

    /**
     * Items_catalog effect key that marks a deployable loot crate
     * variant. Values are 'oil' or 'cash'.
     */
    public const EFFECT_KEY = 'deployable_loot_crate';

    private const TILE_COUNT_CACHE_KEY = 'loot_crate.world_tile_count';

    private const TILE_COUNT_CACHE_TTL = 300; // 5 minutes

    /**
     * Per-instance cache of device_key → display name lookups, used
     * by sabotage notification copy. Instance-scoped (not static)
     * so individual test runs and bot ticks see fresh data when the
     * items catalog is mutated mid-process.
     *
     * @var array<string, string>
     */
    private array $deviceNameCache = [];

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly LootWeightingService $weighting,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Called from the travel hook when a player finishes moving onto
     * a tile. If the tile is a wasteland with no existing unopened
     * crate, rolls spawn_chance and — on a hit — persists a new real
     * crate. If the tile already has an unopened crate (real or
     * sabotage), returns that crate so the caller can hand it to the
     * frontend modal.
     *
     * Returns null when:
     *   - tile is not wasteland
     *   - spawn roll missed and no existing crate present
     *
     * Transactional with lockForUpdate on the active-crate-for-tile
     * lookup so two players arriving on the same wasteland tile in
     * the same instant can't both see a "newly spawned" crate.
     */
    public function onArrival(Player $player, Tile $tile): ?TileLootCrate
    {
        if ($tile->type !== 'wasteland') {
            return null;
        }

        return DB::transaction(function () use ($player, $tile) {
            // Lock any existing unopened crate for this tile. The
            // lockForUpdate here is belt-and-braces — without a
            // partial unique index the safest way to enforce "one
            // slot per tile" is to lock the read before deciding
            // whether to insert.
            $existing = TileLootCrate::query()
                ->where('tile_x', $tile->x)
                ->where('tile_y', $tile->y)
                ->whereNull('opened_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $chance = (float) $this->config->get('loot.real_crate.spawn_chance', 0.01);
            if ($chance <= 0.0) {
                return null;
            }

            $eventKey = 'spawn.p'.$player->id.'.t'.$tile->x.','.$tile->y.'.'.now()->getTimestampMs();

            if (! $this->rng->rollBool('loot.spawn', $eventKey, min(1.0, $chance))) {
                return null;
            }

            return TileLootCrate::create([
                'tile_x' => (int) $tile->x,
                'tile_y' => (int) $tile->y,
                'placed_by_player_id' => null,
                'device_key' => null,
                'placed_at' => now(),
            ]);
        });
    }

    /**
     * Fetch the active crate on a tile (if any) without side effects.
     * Used by MapStateBuilder::wastelandDetail so a player re-visiting
     * the same wasteland tile sees the crate in the interaction panel
     * even though the arrival roll only fires once.
     */
    public function activeCrateForTile(int $tileX, int $tileY): ?TileLootCrate
    {
        return TileLootCrate::query()
            ->where('tile_x', $tileX)
            ->where('tile_y', $tileY)
            ->whereNull('opened_at')
            ->first();
    }

    /**
     * Deploy a sabotage loot crate from the player's toolbox onto
     * the tile they're currently standing on.
     *
     * Guards (all under transaction with locks):
     *   - Player must be on a wasteland tile
     *   - Tile must not already have an unopened crate
     *   - Player must own at least one of the deployable item (with
     *     the EFFECT_KEY effect)
     *   - Player must be under the currently-deployed cap, which
     *     scales with world tile count
     *
     * Immune placers ARE allowed to deploy (spec #7). Bots are not
     * blocked here either — the "bots never place" constraint lives
     * in config as loot.bots.place_sabotage (default false) and is
     * enforced at the bot executor, not in the service.
     *
     * @return array{crate: TileLootCrate, remaining_quantity: int}
     */
    public function place(int $playerId, string $itemKey): array
    {
        return DB::transaction(function () use ($playerId, $itemKey) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($player->current_tile_id);
            if ($tile->type !== 'wasteland') {
                throw CannotOpenLootCrateException::notOnWasteland($tile->type);
            }

            /** @var Item|null $item */
            $item = Item::query()->where('key', $itemKey)->first();
            if ($item === null) {
                throw CannotOpenLootCrateException::unknownDevice($itemKey);
            }
            $effects = $item->effects ?? [];
            if (! isset($effects[self::EFFECT_KEY])) {
                throw CannotOpenLootCrateException::unknownDevice($itemKey);
            }

            // Inventory row lock so a second concurrent place from the
            // same account can't double-deploy one crate.
            $inventoryRow = DB::table('player_items')
                ->where('player_id', $player->id)
                ->where('item_key', $itemKey)
                ->where('status', 'active')
                ->where('quantity', '>', 0)
                ->lockForUpdate()
                ->first();

            if ($inventoryRow === null) {
                throw CannotOpenLootCrateException::notOwned($itemKey);
            }

            // Per-tile uniqueness check — lockForUpdate so a simultaneous
            // onArrival spawn for the same tile can't race us.
            $existing = TileLootCrate::query()
                ->where('tile_x', $tile->x)
                ->where('tile_y', $tile->y)
                ->whereNull('opened_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw CannotOpenLootCrateException::tileAlreadyHasCrate((int) $tile->x, (int) $tile->y);
            }

            // Deployment cap check.
            $cap = $this->deploymentCap();
            $current = $this->currentlyDeployedCount($player->id);
            if ($current >= $cap) {
                throw CannotOpenLootCrateException::deploymentCapReached($current, $cap);
            }

            // Decrement inventory — delete row on zero so the toolbox
            // HUD hides empty entries automatically (matches the
            // SabotageService pattern).
            $newQty = (int) $inventoryRow->quantity - 1;
            if ($newQty <= 0) {
                DB::table('player_items')->where('id', $inventoryRow->id)->delete();
                $newQty = 0;
            } else {
                DB::table('player_items')
                    ->where('id', $inventoryRow->id)
                    ->update([
                        'quantity' => $newQty,
                        'updated_at' => now(),
                    ]);
            }

            $crate = TileLootCrate::create([
                'tile_x' => (int) $tile->x,
                'tile_y' => (int) $tile->y,
                'placed_by_player_id' => $player->id,
                'device_key' => $itemKey,
                'placed_at' => now(),
            ]);

            return [
                'crate' => $crate,
                'remaining_quantity' => $newQty,
            ];
        });
    }

    /**
     * Decline a crate. Real and sabotage both stay on the tile — the
     * next visitor gets a shot. No DB write needed; this endpoint
     * exists so the frontend has a symmetric POST to call when the
     * player clicks "Leave it" and so tests can cover the decline path.
     *
     * We still validate the player is on the tile so a stale client
     * can't spam-decline crates they aren't near.
     */
    public function decline(int $playerId, int $crateId): void
    {
        // Wrapped in a transaction so the (crate, player) pair is
        // read consistently. Even though decline() does not mutate
        // anything, an unlocked read could let the player decline a
        // crate that another player just consumed — the controller
        // would flash a stale "you declined" success when the more
        // accurate response is a 422 "already opened". The lock is
        // released as soon as the transaction returns.
        DB::transaction(function () use ($playerId, $crateId) {
            /** @var TileLootCrate|null $crate */
            $crate = TileLootCrate::query()
                ->lockForUpdate()
                ->find($crateId);
            if ($crate === null) {
                throw CannotOpenLootCrateException::notFound($crateId);
            }
            if ($crate->opened_at !== null) {
                throw CannotOpenLootCrateException::alreadyOpened($crateId);
            }

            /** @var Player $player */
            $player = Player::query()->findOrFail($playerId);
            $this->assertPlayerOnCrateTile($player, $crate);

            // No mutation — crate stays. Endpoint exists purely for
            // the UX contract ("I chose not to take it") and to keep
            // the API symmetric for mobile clients.
        });
    }

    /**
     * Open a crate. Branches on crate kind and on opener state
     * (immune, non-immune, placer). Transactional with locks on the
     * crate, the opener, and (for sabotage) the placer.
     *
     * @return array<string,mixed> Outcome payload safe to return to
     *                             the frontend modal and the bot
     *                             executor:
     *                             kind: one of OUTCOME_* constants
     *                             barrels?: int
     *                             cash?: float
     *                             item_key?: string
     *                             item_name?: string
     *                             sabotage_device_key?: string
     *                             siphoned_amount?: int|float
     *                             siphoned_currency?: 'oil'|'cash'
     */
    public function open(int $playerId, int $crateId): array
    {
        return DB::transaction(function () use ($playerId, $crateId) {
            /** @var TileLootCrate|null $crate */
            $crate = TileLootCrate::query()
                ->lockForUpdate()
                ->find($crateId);

            if ($crate === null) {
                throw CannotOpenLootCrateException::notFound($crateId);
            }
            if ($crate->opened_at !== null) {
                throw CannotOpenLootCrateException::alreadyOpened($crateId);
            }

            // Spec #8 short-circuit: the placer can see their own
            // sabotage crate but cannot open it. Reject early so we
            // don't even bother locking rows for a doomed call.
            $placerId = (int) ($crate->placed_by_player_id ?? 0);
            if ($crate->isSabotage() && $placerId === (int) $playerId) {
                throw CannotOpenLootCrateException::ownSabotage();
            }

            // Lock the opener AND (for sabotage) the placer up-front.
            // Locking order matters — without a stable order two
            // concurrent sabotage opens whose openers/placers are the
            // mirror of each other (rare but possible) could deadlock.
            // We sort the player IDs ascending so every transaction
            // acquires locks in the same direction.
            $playerIdsToLock = [$playerId];
            if ($crate->isSabotage() && $placerId > 0) {
                $playerIdsToLock[] = $placerId;
            }
            sort($playerIdsToLock);

            /** @var Collection<int, Player> $lockedPlayers */
            $lockedPlayers = Player::query()
                ->whereIn('id', $playerIdsToLock)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Player|null $opener */
            $opener = $lockedPlayers->get($playerId);
            if ($opener === null) {
                throw CannotOpenLootCrateException::notFound($crateId);
            }
            $this->assertPlayerOnCrateTile($opener, $crate);

            /** @var Player|null $placer */
            $placer = $crate->isSabotage() ? $lockedPlayers->get($placerId) : null;

            // Optional move cost (default 0). Atomic update so the
            // in-memory $opener row stays in sync with the DB even
            // when later branches read $opener->oil_barrels /
            // akzar_cash for the reward / siphon math.
            $moveCost = (int) $this->config->get('loot.open_move_cost', 0);
            if ($moveCost > 0) {
                $opener->forceFill([
                    'moves_current' => max(0, (int) $opener->moves_current - $moveCost),
                ])->save();
            }

            if ($crate->isSabotage()) {
                return $this->resolveSabotage($crate, $opener, $placer);
            }

            return $this->resolveReal($crate, $opener);
        });
    }

    /**
     * Current per-player sabotage-crate deployment cap.
     *
     * Formula: `max(base, floor(world_tile_count / tiles_per_cap_step) * base)`
     *
     * Tile count is cached for 5 minutes so the world-size query
     * doesn't hit the DB on every placement attempt. Invalidation
     * is not wired because world tile count only changes on world
     * growth (nightly at most) and the 5-minute staleness is
     * acceptable for the cap math.
     */
    public function deploymentCap(): int
    {
        $base = max(1, (int) $this->config->get('loot.sabotage.max_deployed_base', 5));
        $step = max(1, (int) $this->config->get('loot.sabotage.tiles_per_cap_step', 2000));

        $tileCount = Cache::remember(
            self::TILE_COUNT_CACHE_KEY,
            self::TILE_COUNT_CACHE_TTL,
            fn () => (int) Tile::query()->count(),
        );

        $scaled = (int) floor($tileCount / $step) * $base;

        return max($base, $scaled);
    }

    /**
     * Count this player's currently-deployed (unopened) sabotage
     * crates for the deployment cap check.
     */
    public function currentlyDeployedCount(int $playerId): int
    {
        return (int) TileLootCrate::query()
            ->where('placed_by_player_id', $playerId)
            ->whereNull('opened_at')
            ->count();
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Verify the player is physically on the crate's tile. Stale
     * clients that call open/decline after travelling away get a
     * clean 422 instead of a silent no-op.
     */
    private function assertPlayerOnCrateTile(Player $player, TileLootCrate $crate): void
    {
        /** @var Tile|null $currentTile */
        $currentTile = Tile::query()->find($player->current_tile_id);
        if ($currentTile === null) {
            throw CannotOpenLootCrateException::notOnTile();
        }
        if ((int) $currentTile->x !== (int) $crate->tile_x
            || (int) $currentTile->y !== (int) $crate->tile_y) {
            throw CannotOpenLootCrateException::notOnTile();
        }
    }

    /**
     * Roll and apply a real-crate outcome: nothing / oil / cash /
     * item. Mutates $opener in place via update() and records the
     * outcome on the crate row.
     *
     * @return array<string,mixed>
     */
    private function resolveReal(TileLootCrate $crate, Player $opener): array
    {
        $weights = (array) $this->config->get('loot.real_crate.outcomes', []);
        $category = $this->weighting->pickOutcome('loot.outcome', $crate->id, $weights);

        $outcome = match ($category) {
            self::OUTCOME_OIL => $this->resolveOilReward($crate, $opener),
            self::OUTCOME_CASH => $this->resolveCashReward($crate, $opener),
            self::OUTCOME_ITEM => $this->resolveItemReward($crate, $opener),
            default => ['kind' => self::OUTCOME_NOTHING],
        };

        $this->finalize($crate, $opener, $outcome);

        // Activity log entry for the opener — low-key informational,
        // unlike a hostility log entry. Wrapped in afterCommit so a
        // rolled-back open doesn't leak a stale toast.
        //
        // Body field naming: we deliberately store the full payload
        // under `loot_outcome` (not `outcome`). The activity log Vue
        // template treats `body.outcome` as a string enum from the
        // attacks system ('success' → 'breached', else → 'repelled')
        // and would mis-render a loot crate object as "repelled".
        // The dedicated `result_label` string is what the template
        // shows directly so the renderer never has to know about
        // crate-specific outcome shapes.
        DB::afterCommit(function () use ($opener, $crate, $outcome) {
            $this->activityLog->record(
                (int) $opener->user_id,
                'loot.opened',
                $this->realCrateToastTitle($outcome),
                [
                    'crate_id' => (int) $crate->id,
                    'loot_outcome' => $outcome,
                    'result_label' => $this->realCrateResultLabel($outcome),
                ],
            );

            LootCrateOpened::dispatch(
                (int) $opener->user_id,
                (int) $crate->id,
                'real',
                $outcome,
            );
        });

        return $outcome;
    }

    /**
     * @return array{kind:string, barrels:int}
     */
    private function resolveOilReward(TileLootCrate $crate, Player $opener): array
    {
        $cfg = (array) $this->config->get('loot.real_crate.oil', []);
        $min = (int) ($cfg['min'] ?? 100);
        $max = (int) ($cfg['max'] ?? 10000);
        $exp = (float) ($cfg['weight_exponent'] ?? 2.5);

        $barrels = (int) $this->weighting->lowWeightedRange(
            'loot.outcome.oil',
            $crate->id,
            (float) $min,
            (float) $max,
            $exp,
            asInt: true,
        );

        if ($barrels > 0) {
            // forceFill+save instead of update() so the in-memory
            // $opener model stays in sync with the DB. Any later
            // branch in the same request (move-cost decrement,
            // notifications) reading $opener->oil_barrels gets the
            // post-credit value.
            $opener->forceFill([
                'oil_barrels' => (int) $opener->oil_barrels + $barrels,
            ])->save();
        }

        return ['kind' => self::OUTCOME_OIL, 'barrels' => $barrels];
    }

    /**
     * @return array{kind:string, cash:float}
     */
    private function resolveCashReward(TileLootCrate $crate, Player $opener): array
    {
        $cfg = (array) $this->config->get('loot.real_crate.cash', []);
        $min = (float) ($cfg['min'] ?? 1.00);
        $max = (float) ($cfg['max'] ?? 10.00);
        $exp = (float) ($cfg['weight_exponent'] ?? 2.5);

        $cash = (float) $this->weighting->lowWeightedRange(
            'loot.outcome.cash',
            $crate->id,
            $min,
            $max,
            $exp,
            asInt: false,
        );

        if ($cash > 0) {
            $opener->forceFill([
                'akzar_cash' => round((float) $opener->akzar_cash + $cash, 2),
            ])->save();
        }

        return ['kind' => self::OUTCOME_CASH, 'cash' => $cash];
    }

    /**
     * Pick a store item and grant it to the opener. If the rolled
     * item is a "single purchase" effect (unlocks, transport, drill
     * tier, etc.) and the opener already owns it, the outcome
     * downgrades to OUTCOME_ITEM_DUPE (= nothing) per spec #9.
     *
     * Stackable items always grant successfully.
     *
     * @return array<string,mixed>
     */
    private function resolveItemReward(TileLootCrate $crate, Player $opener): array
    {
        $itemCfg = (array) $this->config->get('loot.real_crate.item', []);
        $excludeKeys = (array) ($itemCfg['exclude_keys'] ?? []);
        $weighting = (string) ($itemCfg['weighting'] ?? 'inverse_price');
        $cashFactor = (float) ($itemCfg['cash_to_barrels_factor'] ?? 100);
        $intelFactor = (float) ($itemCfg['intel_to_barrels_factor'] ?? 5);

        // Pool is every catalog item. Loot crates themselves are
        // always excluded from the real-crate item roll so a real
        // crate can't roll a crate_siphon_cash reward (players would
        // quickly figure out that real crates have bottomless cash
        // siphons, which breaks the surprise). Admins can still add
        // more exclusions via config.loot.real_crate.item.exclude_keys.
        $lootItemExclusions = [
            (string) $this->config->get('loot.items.siphon_oil.item_key', 'crate_siphon_oil'),
            (string) $this->config->get('loot.items.siphon_cash.item_key', 'crate_siphon_cash'),
        ];
        $excludeKeys = array_values(array_unique(array_merge($excludeKeys, $lootItemExclusions)));

        $items = Item::query()->get();

        $picked = $this->weighting->inversePriceItemPick(
            'loot.outcome.item',
            (int) $crate->id,
            $items,
            $excludeKeys,
            $cashFactor,
            $intelFactor,
            $weighting,
        );

        if ($picked === null) {
            return ['kind' => self::OUTCOME_NOTHING];
        }

        $effects = $picked->effects ?? [];
        $isSinglePurchase = $this->itemIsSinglePurchase($effects);
        $isDrillTierUpgrade = isset($effects['set_drill_tier']);

        // Drill-tier items are "upgrade or nothing" — if the player
        // already has the same or a higher tier, the reward fizzles
        // per spec #9. drill_tier is a Player column, not a
        // player_items lookup, so we read $opener->drill_tier (which
        // was loaded under lockForUpdate at the top of open()).
        if ($isDrillTierUpgrade) {
            $newTier = (int) $effects['set_drill_tier'];
            if ($newTier <= (int) $opener->drill_tier) {
                return [
                    'kind' => self::OUTCOME_ITEM_DUPE,
                    'item_key' => (string) $picked->key,
                    'item_name' => (string) $picked->name,
                    'reason' => 'already_owned',
                ];
            }
        }

        // For non-drill-tier items, take a row lock on the player_items
        // row up front (or reserve a lock-shaped read if the row
        // doesn't exist yet) so a second concurrent open() against the
        // same single-purchase item key cannot both observe
        // $alreadyOwned=false. The lock is released on transaction
        // commit. We only need this for single-purchase items —
        // stackable items can race-grant safely because grantItem()
        // increments quantity.
        if ($isSinglePurchase && ! $isDrillTierUpgrade) {
            $existingRow = DB::table('player_items')
                ->where('player_id', $opener->id)
                ->where('item_key', $picked->key)
                ->lockForUpdate()
                ->first();

            $alreadyOwned = $existingRow !== null
                && $existingRow->status === 'active'
                && (int) $existingRow->quantity > 0;

            if ($alreadyOwned) {
                return [
                    'kind' => self::OUTCOME_ITEM_DUPE,
                    'item_key' => (string) $picked->key,
                    'item_name' => (string) $picked->name,
                    'reason' => 'already_owned',
                ];
            }
        }

        // Drill-tier upgrades take a special path: bump the Player
        // column directly and DO NOT insert into player_items. The
        // shop layer keeps drill-tier items in player_items with
        // quantity 1 so the toolbox renderer can show the equipped
        // tier; for parity, we record a single-quantity row but only
        // if the player doesn't already have one (post-loot upgrade
        // from a lower tier they did own). Without this guard, a bot
        // that drills its way through three loot crates could
        // accumulate a quantity-3 row for a "single owned" upgrade.
        if ($isDrillTierUpgrade) {
            $opener->forceFill([
                'drill_tier' => (int) $effects['set_drill_tier'],
            ])->save();

            // grantItem upserts: if a row exists at any quantity it
            // increments. For drill-tier we want at most quantity 1.
            $existingDrill = DB::table('player_items')
                ->where('player_id', $opener->id)
                ->where('item_key', $picked->key)
                ->lockForUpdate()
                ->first();

            if ($existingDrill === null) {
                DB::table('player_items')->insert([
                    'player_id' => $opener->id,
                    'item_key' => (string) $picked->key,
                    'quantity' => 1,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ((int) $existingDrill->quantity < 1 || $existingDrill->status !== 'active') {
                DB::table('player_items')->where('id', $existingDrill->id)->update([
                    'quantity' => 1,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            }
            // else: row already at quantity 1 active — leave alone.
        } else {
            $this->grantItem($opener->id, $picked->key);
        }

        return [
            'kind' => self::OUTCOME_ITEM,
            'item_key' => (string) $picked->key,
            'item_name' => (string) $picked->name,
        ];
    }

    /**
     * Effect keys that make an item one-per-player. Mirrors
     * ShopService::SINGLE_PURCHASE_EFFECT_KEYS plus stat_add (which
     * the shop gates behind a config flag).
     *
     * @param  array<string,mixed>  $effects
     */
    private function itemIsSinglePurchase(array $effects): bool
    {
        if (isset($effects['stat_add']) && is_array($effects['stat_add'])
            && (bool) $this->config->get('stats.stat_items_single_purchase')) {
            return true;
        }

        $keys = [
            'unlocks',
            'unlocks_transport',
            'unlocks_teleport',
            'drill_yield_bonus_pct',
            'daily_drill_limit_bonus',
            'break_chance_reduction_pct',
        ];

        foreach ($keys as $k) {
            if (array_key_exists($k, $effects)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upsert a player_items row with quantity += 1. Mirrors
     * ShopService::recordOwnership — same shape so the toolbox and
     * shop layers see identical rows regardless of acquisition path.
     */
    private function grantItem(int $playerId, string $itemKey): void
    {
        $now = now();

        $existing = DB::table('player_items')
            ->where('player_id', $playerId)
            ->where('item_key', $itemKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            DB::table('player_items')
                ->where('id', $existing->id)
                ->update([
                    'quantity' => (int) $existing->quantity + 1,
                    'status' => 'active',
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('player_items')->insert([
            'player_id' => $playerId,
            'item_key' => $itemKey,
            'quantity' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Apply a sabotage crate trigger. Three branches:
     *
     *   - Immune opener: the crate is consumed with no effect. The
     *     placer is still notified via the Hostility Log / Pusher so
     *     they know the trap fired even though it did nothing.
     *
     *   - Non-immune opener: roll a steal_pct in the config range,
     *     deduct that fraction of the opener's oil/cash balance, and
     *     transfer it to the placer atomically (both rows locked).
     *
     *   - Placer opening own: already rejected in open() above, so
     *     we never reach this method for that case.
     *
     * @return array<string,mixed>
     */
    private function resolveSabotage(TileLootCrate $crate, Player $opener, ?Player $placer): array
    {
        $deviceKey = (string) $crate->device_key;
        $kind = $this->sabotageKindFor($deviceKey);

        // Immune branch first — no currency movement, no RNG roll.
        $immunityHeld = $opener->immunity_expires_at !== null
            && $opener->immunity_expires_at->isFuture();

        if ($immunityHeld) {
            $outcome = [
                'kind' => self::OUTCOME_IMMUNE_NO_EFFECT,
                'sabotage_device_key' => $deviceKey,
                'sabotage_kind' => $kind,
            ];
            $this->finalize($crate, $opener, $outcome);
            $this->notifySabotageOutcome($crate, $opener, $outcome);

            return $outcome;
        }

        // Non-immune — roll the steal percentage.
        $pctCfg = (array) $this->config->get('loot.sabotage.steal_pct', []);
        $minPct = (float) ($pctCfg['min'] ?? 0.05);
        $maxPct = (float) ($pctCfg['max'] ?? 0.20);
        $pct = $this->weighting->uniformFloat('loot.sabotage.pct', $crate->id, $minPct, $maxPct);
        $pct = max(0.0, min(1.0, $pct));

        // $placer is already locked (or null if the row vanished
        // between place and trigger — placer account hard-deleted).
        // Both currency-side branches read the locked row directly,
        // and writes use forceFill+save so the in-memory model stays
        // consistent with the DB for any caller that reads it later.
        if ($kind === 'oil') {
            $before = (int) $opener->oil_barrels;
            $amount = (int) floor($before * $pct);
            if ($amount > $before) {
                $amount = $before;
            }

            if ($amount > 0) {
                $opener->forceFill([
                    'oil_barrels' => $before - $amount,
                ])->save();
                if ($placer !== null) {
                    $placer->forceFill([
                        'oil_barrels' => (int) $placer->oil_barrels + $amount,
                    ])->save();
                }
            }

            $outcome = [
                'kind' => self::OUTCOME_SABOTAGE_OIL,
                'sabotage_device_key' => $deviceKey,
                'sabotage_kind' => 'oil',
                'amount' => $amount,
                'steal_pct' => $pct,
                'victim_before' => $before,
            ];
        } else {
            // Cash siphon. Round to 2 dp to match the akzar_cash
            // decimal(12,2) column and the user-facing currency
            // format. We floor the raw product before rounding so
            // tiny residual sub-cent amounts don't accidentally
            // round upward and transfer more than the configured pct.
            $before = (float) $opener->akzar_cash;
            $rawAmount = $before * $pct;
            $amount = round(floor($rawAmount * 100) / 100, 2);
            if ($amount > $before) {
                $amount = $before;
            }

            if ($amount > 0) {
                $opener->forceFill([
                    'akzar_cash' => round($before - $amount, 2),
                ])->save();
                if ($placer !== null) {
                    $placer->forceFill([
                        'akzar_cash' => round((float) $placer->akzar_cash + $amount, 2),
                    ])->save();
                }
            }

            $outcome = [
                'kind' => self::OUTCOME_SABOTAGE_CASH,
                'sabotage_device_key' => $deviceKey,
                'sabotage_kind' => 'cash',
                'amount' => $amount,
                'steal_pct' => $pct,
                'victim_before' => $before,
            ];
        }

        $this->finalize($crate, $opener, $outcome);
        $this->notifySabotageOutcome($crate, $opener, $outcome);

        return $outcome;
    }

    /**
     * Look up the variant kind ('oil' | 'cash' | 'unknown') for a
     * sabotage crate's device_key. Uses the items_catalog effects
     * JSON as the source of truth — mirrors SabotageService::deviceKind.
     */
    private function sabotageKindFor(string $deviceKey): string
    {
        /** @var Item|null $item */
        $item = Item::query()->where('key', $deviceKey)->first();
        if ($item === null) {
            return 'unknown';
        }
        $effects = $item->effects ?? [];
        $variant = $effects[self::EFFECT_KEY] ?? null;
        if (is_array($variant) && isset($variant['kind'])) {
            return (string) $variant['kind'];
        }

        return 'unknown';
    }

    /**
     * Stamp the opened_at/outcome columns. Single call site so the
     * DB columns stay in sync with the outcome shapes.
     *
     * @param  array<string,mixed>  $outcome
     */
    private function finalize(TileLootCrate $crate, Player $opener, array $outcome): void
    {
        $crate->update([
            'opened_at' => now(),
            'opened_by_player_id' => $opener->id,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Write Hostility Log + activity log entries and broadcast the
     * Pusher event for a sabotage crate outcome. Deferred via
     * afterCommit so a rolled-back open transaction never leaks a
     * notification for a write that didn't happen.
     *
     * Copy follows the SabotageService template:
     *   - Placer always notified (dossier-style name reveal for the
     *     opener's side only happens at the Hostility Log page, not
     *     in the broadcast).
     *   - Opener gets an anonymous activity-log entry so they see
     *     the trap fired without learning who did it (unless they
     *     visit /attack-log with the dossier item).
     *
     * @param  array<string,mixed>  $outcome
     */
    private function notifySabotageOutcome(TileLootCrate $crate, Player $opener, array $outcome): void
    {
        $placerUserId = $this->userIdForPlayer((int) $crate->placed_by_player_id);
        $openerUserId = (int) $opener->user_id;
        $openerName = (string) ($opener->user?->name ?? 'someone');
        $deviceKey = (string) $crate->device_key;
        $deviceName = $this->deviceDisplayName($deviceKey);
        $kind = (string) $outcome['kind'];
        $crateId = (int) $crate->id;

        // Copy for placer and opener — placer always learns, opener
        // gets anonymous copy.
        $placerTitle = match ($kind) {
            self::OUTCOME_SABOTAGE_OIL => sprintf(
                'Your %s siphoned %d barrels from %s',
                $deviceName,
                (int) ($outcome['amount'] ?? 0),
                $openerName,
            ),
            self::OUTCOME_SABOTAGE_CASH => sprintf(
                'Your %s siphoned A%s from %s',
                $deviceName,
                number_format((float) ($outcome['amount'] ?? 0), 2),
                $openerName,
            ),
            self::OUTCOME_IMMUNE_NO_EFFECT => sprintf(
                'Your %s fizzled — %s is immune',
                $deviceName,
                $openerName,
            ),
            default => sprintf('Your %s was triggered', $deviceName),
        };

        $openerTitle = match ($kind) {
            self::OUTCOME_SABOTAGE_OIL => sprintf(
                "It was a trap! %d barrels drained into someone else's stash.",
                (int) ($outcome['amount'] ?? 0),
            ),
            self::OUTCOME_SABOTAGE_CASH => sprintf(
                'It was a trap! A%s drained into an anonymous account.',
                number_format((float) ($outcome['amount'] ?? 0), 2),
            ),
            self::OUTCOME_IMMUNE_NO_EFFECT => 'That crate was a trap — but new-player immunity protected you.',
            default => 'That crate was a trap, but nothing happened.',
        };

        DB::afterCommit(function () use (
            $placerUserId, $openerUserId, $placerTitle, $openerTitle,
            $crateId, $deviceKey, $outcome, $kind
        ) {
            if ($placerUserId !== null) {
                // Body field naming: same rule as resolveReal — the
                // full payload goes under `loot_outcome`, never
                // `outcome`, so the activity log Vue template can't
                // mistake it for an attack outcome enum and render
                // "repelled". `result_label` is the human-readable
                // string the template displays directly.
                $this->activityLog->record(
                    $placerUserId,
                    'loot.sabotage.triggered',
                    $placerTitle,
                    [
                        'crate_id' => $crateId,
                        'device_key' => $deviceKey,
                        'loot_outcome' => $outcome,
                        'result_label' => $this->sabotagePlacerResultLabel($outcome),
                    ],
                );
                SabotageLootCrateTriggered::dispatch(
                    $placerUserId,
                    $crateId,
                    $deviceKey,
                    $kind,
                    (float) ($outcome['amount'] ?? 0),
                );
            }

            // Immune path: opener gets a distinct toast so they see
            // the fizzle. Non-immune: a generic "you were trapped"
            // toast without the placer's name.
            $this->activityLog->record(
                $openerUserId,
                'loot.sabotage.hit',
                $openerTitle,
                [
                    'crate_id' => $crateId,
                    'device_key' => $deviceKey,
                    'loot_outcome' => $outcome,
                    'result_label' => $this->sabotageVictimResultLabel($outcome),
                ],
            );
        });
    }

    /**
     * Friendly title for a real-crate outcome toast. Kept as a single
     * match so the copy lives next to the outcome constants.
     *
     * @param  array<string,mixed>  $outcome
     */
    private function realCrateToastTitle(array $outcome): string
    {
        return match ((string) $outcome['kind']) {
            self::OUTCOME_OIL => sprintf('Found a loot crate — +%d barrels', (int) ($outcome['barrels'] ?? 0)),
            self::OUTCOME_CASH => sprintf('Found a loot crate — +A%s', number_format((float) ($outcome['cash'] ?? 0), 2)),
            self::OUTCOME_ITEM => sprintf('Found a loot crate — %s', (string) ($outcome['item_name'] ?? 'a mystery item')),
            self::OUTCOME_ITEM_DUPE => sprintf('Found a loot crate — duplicate %s, discarded', (string) ($outcome['item_name'] ?? 'item')),
            default => 'Opened a loot crate — nothing inside',
        };
    }

    /**
     * Short outcome label rendered in the activity log "result" line
     * for real-crate openings. Stays short (one or two words) — the
     * full title at the top of the entry already carries the detail.
     *
     * @param  array<string,mixed>  $outcome
     */
    private function realCrateResultLabel(array $outcome): string
    {
        return match ((string) $outcome['kind']) {
            self::OUTCOME_OIL, self::OUTCOME_CASH, self::OUTCOME_ITEM => 'Obtained',
            self::OUTCOME_ITEM_DUPE => 'Duplicate, already have',
            self::OUTCOME_NOTHING => 'Empty',
            default => 'Opened',
        };
    }

    /**
     * Result label for the placer-side activity log entry on a
     * sabotage crate trigger (someone else opened your trap).
     *
     * @param  array<string,mixed>  $outcome
     */
    private function sabotagePlacerResultLabel(array $outcome): string
    {
        return match ((string) $outcome['kind']) {
            self::OUTCOME_SABOTAGE_OIL, self::OUTCOME_SABOTAGE_CASH => 'Sabotaged',
            self::OUTCOME_IMMUNE_NO_EFFECT => 'Fizzled (target immune)',
            default => 'Triggered',
        };
    }

    /**
     * Result label for the victim-side activity log entry on a
     * sabotage crate trigger (you opened a trap).
     *
     * @param  array<string,mixed>  $outcome
     */
    private function sabotageVictimResultLabel(array $outcome): string
    {
        return match ((string) $outcome['kind']) {
            self::OUTCOME_SABOTAGE_OIL, self::OUTCOME_SABOTAGE_CASH => 'Trapped',
            self::OUTCOME_IMMUNE_NO_EFFECT => 'Trap fizzled (immunity held)',
            default => 'Triggered',
        };
    }

    /**
     * player_id → user_id resolver for broadcast targeting. Matches
     * SabotageService::userIdForPlayer — no caching because this
     * service may be reused across bot ticks inside a Horizon worker.
     */
    private function userIdForPlayer(int $playerId): ?int
    {
        $row = DB::table('players')->where('id', $playerId)->value('user_id');

        return $row !== null ? (int) $row : null;
    }

    /**
     * Resolve the human-friendly name for a crate device key. Falls
     * back to a title-cased version of the key if the catalog row
     * has been deleted. Memoised per-method-call only.
     */
    private function deviceDisplayName(string $deviceKey): string
    {
        if (isset($this->deviceNameCache[$deviceKey])) {
            return $this->deviceNameCache[$deviceKey];
        }

        $name = DB::table('items_catalog')
            ->where('key', $deviceKey)
            ->value('name');

        if (! is_string($name) || $name === '') {
            $name = ucwords(str_replace('_', ' ', $deviceKey));
        }

        $this->deviceNameCache[$deviceKey] = $name;

        return $name;
    }
}
