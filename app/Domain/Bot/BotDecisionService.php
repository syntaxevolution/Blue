<?php

namespace App\Domain\Bot;

use App\Domain\Combat\AttackService;
use App\Domain\Combat\SpyService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Drilling\DrillService;
use App\Domain\Economy\ShopService;
use App\Domain\Player\MoveRegenService;
use App\Domain\Player\TravelService;
use App\Domain\World\FogOfWarService;
use App\Models\Item;
use App\Models\Player;
use App\Models\Post;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Throwable;

/**
 * Autonomous decision loop for a single bot player.
 *
 * Contract:
 *   - Bots call the exact same domain services a human calls. No
 *     rule-bending, no private APIs.
 *   - Primary objective across all difficulty tiers: maximise Akzar Cash.
 *   - Difficulty tunes the action mix + target-selection thresholds via
 *     config/game.php `bots.difficulty.<tier>`. Retuning is a config
 *     change, never a code change.
 *   - Every random pick goes through RngService so replays/audits still
 *     work for bots.
 *   - Exceptions from action services are caught and treated as "this
 *     action wasn't viable, try something else." The tick never bubbles
 *     a runtime failure.
 *
 * Per-tick behaviour: the bot picks up to
 * `bots.actions_per_tick_max` weighted actions. For each, it tries to
 * reach a suitable tile (one travel step) then executes the action.
 * Unreachable or blocked actions fall through to drilling the current
 * tile if possible, otherwise a single travel step in a random direction.
 */
class BotDecisionService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly MoveRegenService $moveRegen,
        private readonly TravelService $travel,
        private readonly DrillService $drillSvc,
        private readonly ShopService $shop,
        private readonly SpyService $spySvc,
        private readonly AttackService $attackSvc,
        private readonly FogOfWarService $fogOfWar,
    ) {}

    /**
     * Run one tick for the given bot. Returns a summary of actions
     * performed for logging.
     *
     * @return array{actions: list<array{kind:string, detail?:string, error?:string}>, ended_with: string}
     */
    public function tick(Player $bot): array
    {
        $actions = [];
        $maxActions = (int) $this->config->get('bots.actions_per_tick_max', 3);

        // Reconcile regen so we're making decisions with the current pool.
        $this->moveRegen->reconcile($bot);
        $bot->refresh();

        $tier = (string) ($bot->bot_difficulty ?? 'normal');
        $tierCfg = $this->config->get('bots.difficulty.'.$tier, null);
        if (! is_array($tierCfg)) {
            return ['actions' => [], 'ended_with' => 'unknown_difficulty'];
        }

        $tickSalt = ($bot->bot_last_tick_at?->timestamp ?? 0).'-'.now()->timestamp;

        for ($i = 0; $i < $maxActions; $i++) {
            if ($bot->moves_current <= 0) {
                $actions[] = ['kind' => 'noop', 'detail' => 'no_moves'];
                break;
            }

            $kind = $this->rollAction($tierCfg, $bot, $tickSalt.'-'.$i);

            try {
                $result = $this->executeAction($kind, $bot, $tierCfg, $tickSalt.'-'.$i);
                $actions[] = $result;
            } catch (Throwable $e) {
                $actions[] = ['kind' => $kind, 'error' => $e->getMessage()];
                // On failure, try a safe drill→travel fallback once.
                try {
                    $fallback = $this->safeFallback($bot);
                    if ($fallback !== null) {
                        $actions[] = $fallback;
                    }
                } catch (Throwable $e2) {
                    $actions[] = ['kind' => 'fallback', 'error' => $e2->getMessage()];
                }
            }

            $bot->refresh();
        }

        $bot->update(['bot_last_tick_at' => now()]);

        return ['actions' => $actions, 'ended_with' => $bot->moves_current > 0 ? 'ok' : 'no_moves'];
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     */
    private function rollAction(array $tierCfg, Player $bot, string $salt): string
    {
        $weights = (array) ($tierCfg['action_weights'] ?? []);
        if ($weights === []) {
            return 'drill';
        }

        return (string) $this->rng->rollWeighted(
            'bot.action',
            'bot.'.$bot->id.'.'.$salt,
            $weights,
        );
    }

    /**
     * Dispatch to the right handler for the chosen action kind.
     *
     * @param  array<string,mixed>  $tierCfg
     * @return array{kind:string, detail?:string}
     */
    private function executeAction(string $kind, Player $bot, array $tierCfg, string $salt): array
    {
        return match ($kind) {
            'drill' => $this->doDrill($bot),
            'shop' => $this->doShop($bot, $tierCfg),
            'spy' => $this->doSpy($bot, $tierCfg, $salt),
            'attack' => $this->doAttack($bot, $tierCfg),
            default => $this->doDrill($bot),
        };
    }

    private function doDrill(Player $bot): array
    {
        $tile = Tile::find($bot->current_tile_id);
        if ($tile?->type !== 'oil_field') {
            // Walk one step toward the nearest known oil field.
            $target = $this->nearestDiscoveredOfType($bot, 'oil_field');
            if ($target !== null) {
                $dir = $this->directionToward($tile, $target);
                if ($dir !== null) {
                    $this->travel->travel($bot->id, $dir);
                    return ['kind' => 'travel', 'detail' => "toward_oil_field({$dir})"];
                }
            }
            // Fallback: random walk.
            $this->randomWalk($bot);
            return ['kind' => 'travel', 'detail' => 'random_walk'];
        }

        // Already on oil field — pick first un-drilled 5×5 grid cell.
        [$gx, $gy] = $this->pickDrillCell($tile, $bot);
        $this->drillSvc->drill($bot->id, $gx, $gy);
        return ['kind' => 'drill', 'detail' => "{$gx},{$gy}"];
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     */
    private function doShop(Player $bot, array $tierCfg): array
    {
        $tile = Tile::find($bot->current_tile_id);
        if ($tile?->type !== 'post') {
            $target = $this->nearestDiscoveredOfType($bot, 'post');
            if ($target !== null) {
                $dir = $this->directionToward($tile, $target);
                if ($dir !== null) {
                    $this->travel->travel($bot->id, $dir);
                    return ['kind' => 'travel', 'detail' => "toward_post({$dir})"];
                }
            }
            return $this->doDrill($bot);
        }

        /** @var Post|null $post */
        $post = Post::query()->where('tile_id', $tile->id)->first();
        if ($post === null) {
            return $this->doDrill($bot);
        }

        // Compare against oil_barrels — all shop items cost barrels, not
        // cash. The original code compared against akzar_cash, which bots
        // almost never accumulate (cash only comes from raids), so the
        // gate was permanently false and bots never bought upgrades.
        $upgradeThreshold = (int) ($tierCfg['upgrade_threshold_barrels'] ?? $tierCfg['upgrade_threshold_cash'] ?? 500);
        $canAffordUpgrades = (int) $bot->oil_barrels >= $upgradeThreshold;

        $items = Item::query()
            ->where('post_type', $post->post_type)
            ->orderByDesc('price_barrels')
            ->get();

        foreach ($items as $item) {
            if ($bot->oil_barrels < (int) $item->price_barrels) {
                continue;
            }
            if ((float) $bot->akzar_cash < (float) $item->price_cash) {
                continue;
            }
            if ($bot->intel < (int) $item->price_intel) {
                continue;
            }
            // Hard tier: prioritize stat/unlock items. Easy/normal:
            // buy whatever they can afford. The threshold gate blocks
            // frivolous upgrades when cash is scarce.
            $effects = is_array($item->effects) ? $item->effects : [];
            $isUpgrade = isset($effects['stat_add']) || isset($effects['set_drill_tier']) || isset($effects['unlocks_transport']);
            if ($isUpgrade && ! $canAffordUpgrades) {
                continue;
            }

            try {
                $this->shop->purchase($bot->id, $item->key);
                return ['kind' => 'shop', 'detail' => $item->key];
            } catch (Throwable) {
                // Already owned / downgrade / etc — try next item.
                continue;
            }
        }

        // Nothing to buy here — drill instead if possible.
        return $this->doDrill($bot);
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     */
    private function doSpy(Player $bot, array $tierCfg, string $salt): array
    {
        // Need an enemy base with a high-enough cash pool + that isn't
        // a fellow MDN member.
        $target = $this->findRaidTarget($bot, $tierCfg);
        if ($target === null) {
            return $this->doDrill($bot);
        }

        $tile = Tile::find($bot->current_tile_id);
        if ((int) $tile->id !== (int) $target->base_tile_id) {
            $targetTile = Tile::find($target->base_tile_id);
            $dir = $this->directionToward($tile, $targetTile);
            if ($dir === null) {
                return $this->doDrill($bot);
            }
            $this->travel->travel($bot->id, $dir);
            return ['kind' => 'travel', 'detail' => "toward_spy_target({$dir})"];
        }

        $this->spySvc->spy($bot->id);
        return ['kind' => 'spy', 'detail' => (string) $target->id];
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     */
    private function doAttack(Player $bot, array $tierCfg): array
    {
        // Attack requires a valid spy in window. Find targets we've
        // spied and can still hit.
        $spyDecayHours = (int) $this->config->get('combat.spy_decay_hours', 24);
        $target = SpyAttempt::query()
            ->where('spy_player_id', $bot->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($spyDecayHours))
            ->orderByDesc('created_at')
            ->first();

        if ($target === null) {
            // No one worth attacking — fall through to spy path.
            return $this->doSpy($bot, $tierCfg, 'attack-fallback');
        }

        $tile = Tile::find($bot->current_tile_id);
        if ((int) $tile?->id !== (int) $target->target_base_tile_id) {
            $targetTile = Tile::find($target->target_base_tile_id);
            $dir = $this->directionToward($tile, $targetTile);
            if ($dir === null) {
                return $this->doDrill($bot);
            }
            $this->travel->travel($bot->id, $dir);
            return ['kind' => 'travel', 'detail' => "toward_raid_target({$dir})"];
        }

        $this->attackSvc->attack($bot->id);
        return ['kind' => 'attack', 'detail' => (string) $target->target_player_id];
    }

    private function safeFallback(Player $bot): ?array
    {
        if ($bot->moves_current <= 0) {
            return null;
        }
        $tile = Tile::find($bot->current_tile_id);
        if ($tile?->type === 'oil_field') {
            try {
                [$gx, $gy] = $this->pickDrillCell($tile, $bot);
                $this->drillSvc->drill($bot->id, $gx, $gy);
                return ['kind' => 'drill', 'detail' => "fallback({$gx},{$gy})"];
            } catch (Throwable) {
                // continue to random walk
            }
        }
        try {
            $this->randomWalk($bot);
            return ['kind' => 'travel', 'detail' => 'fallback_random'];
        } catch (Throwable $e) {
            return ['kind' => 'noop', 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function pickDrillCell(Tile $tile, Player $bot): array
    {
        /** @var \App\Models\OilField|null $field */
        $field = \App\Models\OilField::query()->where('tile_id', $tile->id)->first();
        if ($field === null) {
            return [0, 0];
        }

        // First available non-depleted point, preferring gusher > standard
        // > trickle > dry. DrillService will reject drilled_at !== null.
        $qualityOrder = ['gusher' => 0, 'standard' => 1, 'trickle' => 2, 'dry' => 3];
        $point = \App\Models\DrillPoint::query()
            ->where('oil_field_id', $field->id)
            ->whereNull('drilled_at')
            ->get()
            ->sortBy(fn ($p) => $qualityOrder[$p->quality] ?? 9)
            ->first();

        if ($point === null) {
            return [0, 0];
        }

        return [(int) $point->grid_x, (int) $point->grid_y];
    }

    /**
     * Find the nearest discovered tile of a given type. Bots only see
     * tiles they've walked through, same as a real player.
     */
    private function nearestDiscoveredOfType(Player $bot, string $type): ?Tile
    {
        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        $current = Tile::find($bot->current_tile_id);
        if ($current === null) {
            return null;
        }

        return Tile::query()
            ->whereIn('id', $discovered)
            ->where('type', $type)
            ->get()
            ->sortBy(fn (Tile $t) => abs($t->x - $current->x) + abs($t->y - $current->y))
            ->first();
    }

    /**
     * @param  array<string,mixed>  $tierCfg
     */
    private function findRaidTarget(Player $bot, array $tierCfg): ?Player
    {
        $discovered = $this->fogOfWar->getDiscoveredTileIds($bot->id);
        if ($discovered === []) {
            return null;
        }

        $minCash = (float) ($tierCfg['min_target_cash'] ?? 5.0);
        $immunityBuffer = now();

        return Player::query()
            ->whereIn('base_tile_id', $discovered)
            ->where('id', '!=', $bot->id)
            ->where('akzar_cash', '>=', $minCash)
            ->where(function ($q) use ($immunityBuffer) {
                $q->whereNull('immunity_expires_at')
                    ->orWhere('immunity_expires_at', '<', $immunityBuffer);
            })
            ->when($bot->mdn_id !== null, fn ($q) => $q->where(function ($q2) use ($bot) {
                $q2->whereNull('mdn_id')->orWhere('mdn_id', '!=', $bot->mdn_id);
            }))
            ->orderByDesc('akzar_cash')
            ->first();
    }

    /**
     * One-step direction from $from toward $to. Prioritizes the axis
     * with the larger delta; returns null if already there or either
     * tile is missing.
     */
    private function directionToward(?Tile $from, ?Tile $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }
        $dx = (int) $to->x - (int) $from->x;
        $dy = (int) $to->y - (int) $from->y;
        if ($dx === 0 && $dy === 0) {
            return null;
        }
        if (abs($dx) >= abs($dy)) {
            return $dx > 0 ? 'e' : 'w';
        }
        return $dy > 0 ? 'n' : 's';
    }

    private function randomWalk(Player $bot): void
    {
        $dirs = ['n', 's', 'e', 'w'];
        // Microtime salt ensures multiple fallback walks in the same tick
        // get different rolls; otherwise a bot wedged against the world
        // edge would pick the same direction every attempt.
        $idx = $this->rng->rollInt(
            'bot.walk',
            'bot.'.$bot->id.'.'.microtime(true),
            0,
            3,
        );
        $this->travel->travel($bot->id, $dirs[$idx]);
    }
}
