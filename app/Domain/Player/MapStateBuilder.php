<?php

namespace App\Domain\Player;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Economy\TransportService;
use App\Domain\Items\StatOverflowService;
use App\Domain\Notifications\ActivityLogService;
use App\Domain\World\FogOfWarService;
use App\Models\DrillPoint;
use App\Models\Item;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Post;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the full map-view payload — player state, owned gear,
 * current tile, edge-hint neighbors, tile-specific sub-payload, and
 * feature unlocks (atlas, attack log). Consumed by both Web and
 * Api/V1 MapControllers.
 */
class MapStateBuilder
{
    public function __construct(
        private readonly MoveRegenService $moveRegen,
        private readonly FogOfWarService $fogOfWar,
        private readonly GameConfigResolver $config,
        private readonly StatOverflowService $statOverflow,
        private readonly TransportService $transport,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Player $player): array
    {
        $this->moveRegen->reconcile($player);
        $player->refresh();

        // Drain banked stat overflow any time the cap may have been
        // raised since the last time the player loaded the map.
        if ($this->statOverflow->drainBank($player)) {
            $player->save();
            $player->refresh();
        }

        /** @var Tile $current */
        $current = $player->currentTile;

        /** @var Tile $baseTile */
        $baseTile = $player->baseTile;

        $neighbors = Tile::query()
            ->where(function ($q) use ($current) {
                $q->where(function ($q2) use ($current) {
                    $q2->where('x', $current->x + 1)->where('y', $current->y);
                })->orWhere(function ($q2) use ($current) {
                    $q2->where('x', $current->x - 1)->where('y', $current->y);
                })->orWhere(function ($q2) use ($current) {
                    $q2->where('x', $current->x)->where('y', $current->y + 1);
                })->orWhere(function ($q2) use ($current) {
                    $q2->where('x', $current->x)->where('y', $current->y - 1);
                });
            })
            ->get(['id', 'x', 'y', 'type']);

        $unlocks = $this->playerUnlocks($player);

        $ownedTransports = $this->transport->ownedKeys($player);
        $transportCatalog = [];
        foreach ($this->transport->allKeys() as $key) {
            $cfg = $this->transport->configFor($key);
            if ($cfg !== null) {
                $transportCatalog[$key] = array_merge(['key' => $key], $cfg);
            }
        }

        $ownsTeleporter = DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', 'teleporter')
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->exists();

        return [
            'player' => [
                'id' => $player->id,
                'user_id' => (int) $player->user_id,
                'username' => (string) ($player->user->name ?? ''),
                'akzar_cash' => (float) $player->akzar_cash,
                'oil_barrels' => $player->oil_barrels,
                'intel' => $player->intel,
                'moves_current' => $player->moves_current,
                'strength' => $player->strength,
                'fortification' => $player->fortification,
                'stealth' => $player->stealth,
                'security' => $player->security,
                'strength_banked' => (int) $player->strength_banked,
                'fortification_banked' => (int) $player->fortification_banked,
                'stealth_banked' => (int) $player->stealth_banked,
                'security_banked' => (int) $player->security_banked,
                'drill_tier' => $player->drill_tier,
                'active_transport' => (string) ($player->active_transport ?? TransportService::DEFAULT),
                'owned_transports' => $ownedTransports,
                'owns_teleporter' => $ownsTeleporter,
                'broken_item_key' => $player->broken_item_key,
                'immunity_expires_at' => $player->immunity_expires_at?->toIso8601String(),
                'base_tile_id' => $player->base_tile_id,
                'hard_cap' => (int) $this->config->get('stats.hard_cap'),
                'base_coords' => ['x' => (int) $baseTile->x, 'y' => (int) $baseTile->y],
                'owns_atlas' => in_array('atlas', $unlocks, true),
                'owns_attack_log' => in_array('attack_log', $unlocks, true),
            ],
            'transport_catalog' => $transportCatalog,
            'immunity_hours' => (int) $this->config->get('new_player.immunity_hours'),
            'unread_activity_count' => $this->activityLog->unreadCount((int) $player->user_id),
            'owned_items' => $this->ownedItems($player),
            'current_tile' => [
                'id' => $current->id,
                'x' => $current->x,
                'y' => $current->y,
                'type' => $current->type,
                'subtype' => $current->subtype,
                'flavor_text' => $current->flavor_text,
                'is_own_base' => $current->id === $player->base_tile_id,
            ],
            'tile_detail' => $this->tileDetail($current, $player),
            'neighbors' => $neighbors->map(fn (Tile $t) => [
                'x' => $t->x,
                'y' => $t->y,
                'type' => $t->type,
                'direction' => match (true) {
                    $t->x === $current->x + 1 && $t->y === $current->y => 'e',
                    $t->x === $current->x - 1 && $t->y === $current->y => 'w',
                    $t->x === $current->x && $t->y === $current->y + 1 => 'n',
                    $t->x === $current->x && $t->y === $current->y - 1 => 's',
                    default => null,
                },
            ])->values()->all(),
            'discovered_count' => $this->fogOfWar->countDiscovered($player->id),
            'bank_cap' => $this->moveRegen->bankCap(),
        ];
    }

    /**
     * Collect every feature key unlocked by items the player owns.
     *
     * @return list<string>
     */
    private function playerUnlocks(Player $player): array
    {
        $rows = DB::table('player_items')
            ->where('player_items.player_id', $player->id)
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->whereNotNull('items_catalog.effects')
            ->pluck('items_catalog.effects')
            ->all();

        $unlocks = [];
        foreach ($rows as $json) {
            $effects = json_decode((string) $json, true);
            if (is_array($effects) && isset($effects['unlocks']) && is_array($effects['unlocks'])) {
                foreach ($effects['unlocks'] as $key) {
                    if (is_string($key) && ! in_array($key, $unlocks, true)) {
                        $unlocks[] = $key;
                    }
                }
            }
        }

        return $unlocks;
    }

    /**
     * @return list<array{key:string, name:string, description:string|null, post_type:string, quantity:int, effects:array<string,mixed>|null}>
     */
    private function ownedItems(Player $player): array
    {
        return DB::table('player_items')
            ->where('player_items.player_id', $player->id)
            ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
            ->orderBy('items_catalog.post_type')
            ->orderBy('items_catalog.sort_order')
            ->get([
                'items_catalog.key',
                'items_catalog.name',
                'items_catalog.description',
                'items_catalog.post_type',
                'items_catalog.effects',
                'player_items.quantity',
            ])
            ->map(fn ($row) => [
                'key' => $row->key,
                'name' => $row->name,
                'description' => $row->description,
                'post_type' => $row->post_type,
                'quantity' => (int) $row->quantity,
                'effects' => $row->effects ? json_decode($row->effects, true) : null,
            ])
            ->all();
    }

    private function tileDetail(Tile $tile, Player $player): ?array
    {
        return match ($tile->type) {
            'oil_field' => $this->oilFieldDetail($tile, $player),
            'post' => $this->postDetail($tile, $player),
            'base' => $tile->id === $player->base_tile_id
                ? $this->ownBaseDetail($player)
                : $this->enemyBaseDetail($tile, $player),
            default => null,
        };
    }

    /**
     * @return array{kind:string, grid: list<array{grid_x:int, grid_y:int, quality:string, drilled:bool}>, daily_count:int, daily_limit:int}
     */
    private function oilFieldDetail(Tile $tile, Player $player): array
    {
        /** @var OilField|null $field */
        $field = OilField::query()->where('tile_id', $tile->id)->first();

        $grid = [];
        $dailyCount = 0;
        // Same defensive fallback as DrillService — if the config key
        // isn't populated (stale config:cache, fresh deploy, etc.),
        // default to 5 rather than 0 which would mis-report "limit reached".
        $dailyLimitRaw = $this->config->get('drilling.daily_limit_per_field');
        $dailyLimit = $dailyLimitRaw === null ? 5 : (int) $dailyLimitRaw;
        if ($dailyLimit <= 0) {
            $dailyLimit = 5;
        }

        if ($field) {
            $points = DrillPoint::query()
                ->where('oil_field_id', $field->id)
                ->orderBy('grid_y')
                ->orderBy('grid_x')
                ->get(['grid_x', 'grid_y', 'quality', 'drilled_at']);

            foreach ($points as $p) {
                $grid[] = [
                    'grid_x' => (int) $p->grid_x,
                    'grid_y' => (int) $p->grid_y,
                    'quality' => $p->drilled_at === null ? (string) $p->quality : 'depleted',
                    'drilled' => $p->drilled_at !== null,
                ];
            }

            $countRow = DB::table('player_drill_counts')
                ->where('player_id', $player->id)
                ->where('oil_field_id', $field->id)
                ->where('drill_date', now()->toDateString())
                ->first();

            $dailyCount = $countRow ? (int) $countRow->drill_count : 0;
        }

        return [
            'kind' => 'oil_field',
            'grid' => $grid,
            'daily_count' => $dailyCount,
            'daily_limit' => $dailyLimit,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function postDetail(Tile $tile, Player $player): array
    {
        /** @var Post|null $post */
        $post = Post::query()->where('tile_id', $tile->id)->first();

        $items = [];

        if ($post) {
            $items = Item::query()
                ->where('post_type', $post->post_type)
                ->orderBy('sort_order')
                ->get()
                ->map(function (Item $item) use ($player) {
                    $canAfford = $this->canAfford($player, $item);
                    $reason = $this->purchaseBlockReason($player, $item);

                    return [
                        'key' => $item->key,
                        'name' => $item->name,
                        'description' => $item->description,
                        'price_barrels' => (int) $item->price_barrels,
                        'price_cash' => (float) $item->price_cash,
                        'price_intel' => (int) $item->price_intel,
                        'effects' => $item->effects,
                        'can_afford' => $canAfford,
                        'can_purchase' => $canAfford && $reason === null,
                        'block_reason' => $reason,
                    ];
                })
                ->all();
        }

        return [
            'kind' => 'post',
            'post_type' => $post?->post_type,
            'name' => $post?->name,
            'items' => $items,
        ];
    }

    private function canAfford(Player $player, Item $item): bool
    {
        if ($item->price_barrels > 0 && $player->oil_barrels < $item->price_barrels) {
            return false;
        }
        if ((float) $item->price_cash > 0 && (float) $player->akzar_cash < (float) $item->price_cash) {
            return false;
        }
        if ($item->price_intel > 0 && $player->intel < $item->price_intel) {
            return false;
        }

        return true;
    }

    private function purchaseBlockReason(Player $player, Item $item): ?string
    {
        $effects = $item->effects ?? [];

        if (isset($effects['set_drill_tier'])) {
            $newTier = (int) $effects['set_drill_tier'];
            $currentTier = (int) $player->drill_tier;
            if ($newTier <= $currentTier) {
                return $newTier === $currentTier ? 'Already owned' : 'Downgrade';
            }
        }

        // Stat items: overflow is banked by StatOverflowService, so never
        // block on the cap. Instead, enforce "one purchase per item key"
        // when the config flag is enabled.
        $singlePurchaseStats = (bool) $this->config->get('stats.stat_items_single_purchase');
        if ($singlePurchaseStats && isset($effects['stat_add']) && is_array($effects['stat_add'])) {
            $owned = DB::table('player_items')
                ->where('player_id', $player->id)
                ->where('item_key', $item->key)
                ->where('quantity', '>', 0)
                ->exists();
            if ($owned) {
                return 'Already owned';
            }
        }

        // Feature unlocks, transports, teleporter — all one-time.
        $singleEffects = ['unlocks', 'unlocks_transport', 'unlocks_teleport'];
        foreach ($singleEffects as $effectKey) {
            if (array_key_exists($effectKey, $effects)) {
                $owned = DB::table('player_items')
                    ->where('player_id', $player->id)
                    ->where('item_key', $item->key)
                    ->where('quantity', '>', 0)
                    ->exists();
                if ($owned) {
                    return 'Already owned';
                }
                break;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function ownBaseDetail(Player $player): array
    {
        return [
            'kind' => 'own_base',
            'stored_cash' => (float) $player->akzar_cash,
            'stored_oil_barrels' => $player->oil_barrels,
            'stored_intel' => $player->intel,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function enemyBaseDetail(Tile $tile, Player $player): array
    {
        /** @var Player|null $owner */
        $owner = Player::query()
            ->with('user:id,name')
            ->where('base_tile_id', $tile->id)
            ->first();

        if ($owner === null) {
            return [
                'kind' => 'enemy_base',
                'owner_username' => null,
                'owner_immune' => false,
                'spy_decay_hours' => (int) $this->config->get('combat.spy_decay_hours'),
                'raid_cooldown_hours' => (int) $this->config->get('combat.raid_cooldown_hours'),
                'has_active_spy' => false,
                'latest_spy_at' => null,
                'last_attack_at' => null,
                'spy_move_cost' => (int) $this->config->get('actions.spy.move_cost'),
                'attack_move_cost' => (int) $this->config->get('actions.attack.move_cost'),
            ];
        }

        $spyDecayHours = (int) $this->config->get('combat.spy_decay_hours');
        $raidCooldownHours = (int) $this->config->get('combat.raid_cooldown_hours');

        $latestSpy = SpyAttempt::query()
            ->where('spy_player_id', $player->id)
            ->where('target_player_id', $owner->id)
            ->where('success', true)
            ->where('created_at', '>=', now()->subHours($spyDecayHours))
            ->orderByDesc('created_at')
            ->first();

        $lastAttack = DB::table('attacks')
            ->where('attacker_player_id', $player->id)
            ->where('defender_player_id', $owner->id)
            ->where('created_at', '>=', now()->subHours($raidCooldownHours))
            ->orderByDesc('created_at')
            ->value('created_at');

        return [
            'kind' => 'enemy_base',
            'owner_username' => $owner->user?->name,
            'owner_immune' => $owner->immunity_expires_at !== null && $owner->immunity_expires_at->isFuture(),
            'spy_decay_hours' => $spyDecayHours,
            'raid_cooldown_hours' => $raidCooldownHours,
            'has_active_spy' => $latestSpy !== null,
            'latest_spy_at' => $latestSpy?->created_at->toIso8601String(),
            'last_attack_at' => $lastAttack,
            'spy_move_cost' => (int) $this->config->get('actions.spy.move_cost'),
            'attack_move_cost' => (int) $this->config->get('actions.attack.move_cost'),
        ];
    }
}
