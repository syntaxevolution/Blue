<?php

namespace App\Domain\World;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Post;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Top-level service for world state, generation, spawn, growth, and decay.
 *
 * All world-touching game logic routes through here — controllers stay
 * thin, the service is pure PHP (no HTTP, no Inertia, no request access),
 * and every balance number comes from GameConfig rather than being
 * hardcoded.
 *
 * Current state:
 *   - getWorldInfo: live (config reads only)
 *   - planInitialWorld: live, pure, deterministic (returns tile specs
 *                      without touching the DB — fully unit-testable)
 *   - generateInitialWorld: live, persists the plan + child rows in a
 *                      DB::transaction (tested via Feature tests)
 *   - spawnPlayer / expandWorld / decayAbandoned: stubbed until later phases
 */
class WorldService
{
    /**
     * Flavor names for each post subtype. Deterministic pick via
     * RngService::rollInt on (seed, x, y). Purely cosmetic content,
     * kept in code since it isn't a balance tunable.
     */
    private const POST_NAMES = [
        'strength' => [
            "Dusty Joe's Arms Depot",
            'The Boneyard',
            'Gearhead Armory',
            'Pig Iron Works',
            'Knuckle & Co.',
        ],
        'stealth' => [
            'Shadow Traders',
            'The Blind Alley',
            'Quiet Steps',
            'Smoke & Mirror',
            'The Silent Partner',
        ],
        'fort' => [
            'The Holdfast',
            'Iron Wall Supply',
            "Builder's Stock",
            'Cinder Block Co.',
            'The Barricade',
        ],
        'tech' => [
            'The Derrick Works',
            'Scrap & Solder',
            'Wire & Bolt',
            'Rusted Cog Trading',
            'Gearbox Emporium',
        ],
        'general' => [
            "Travelers' Rest",
            'Dust Market',
            'The Barter Post',
            'Crosswinds Supply',
            'Last Chance Trading',
        ],
    ];

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly FogOfWarService $fogOfWar,
    ) {}

    /**
     * Return a read-only snapshot of the world's configured shape.
     * Safe to call before any tiles exist — it only reads config.
     *
     * @return array{
     *     initial_radius: int,
     *     density: array{oil_fields_per_tile: float, posts_per_tile: float, landmarks_per_tile: float},
     *     growth: array{trigger_players_per_tile: float, expansion_ring_width: int},
     *     abandonment: array{days_inactive: int, ruin_loot_min: float, ruin_loot_max: float},
     * }
     */
    public function getWorldInfo(): array
    {
        return [
            'initial_radius' => (int) $this->config->get('world.initial_radius'),
            'density' => [
                'oil_fields_per_tile' => (float) $this->config->get('world.density.oil_fields_per_tile'),
                'posts_per_tile' => (float) $this->config->get('world.density.posts_per_tile'),
                'landmarks_per_tile' => (float) $this->config->get('world.density.landmarks_per_tile'),
            ],
            'growth' => [
                'trigger_players_per_tile' => (float) $this->config->get('world.growth.trigger_players_per_tile'),
                'expansion_ring_width' => (int) $this->config->get('world.growth.expansion_ring_width'),
            ],
            'abandonment' => [
                'days_inactive' => (int) $this->config->get('world.abandonment.days_inactive'),
                'ruin_loot_min' => (float) $this->config->get('world.abandonment.ruin_loot_min'),
                'ruin_loot_max' => (float) $this->config->get('world.abandonment.ruin_loot_max'),
            ],
        ];
    }

    /**
     * Deterministically build the initial world as a list of tile specs.
     *
     * Enumerates every (x, y) tile inside the radius-N disc around origin,
     * reserves (0, 0) as 'the_landing' landmark, and for every other tile
     * rolls a type from the density config via RngService. Pure — no DB
     * writes, no side effects. The same seed always produces the same plan,
     * which is what makes world generation reviewable and reproducible.
     *
     * The result is the input to generateInitialWorld (which persists it)
     * and is what unit tests assert against.
     *
     * @return list<array{
     *     x: int,
     *     y: int,
     *     type: string,
     *     subtype: string|null,
     *     seed: int
     * }>
     */
    public function planInitialWorld(int $seed = 0): array
    {
        $radius = (int) $this->config->get('world.initial_radius');
        $densityPosts = (float) $this->config->get('world.density.posts_per_tile');
        $densityOilFields = (float) $this->config->get('world.density.oil_fields_per_tile');
        $densityLandmarks = (float) $this->config->get('world.density.landmarks_per_tile');

        $postCutoff = $densityPosts;
        $oilFieldCutoff = $postCutoff + $densityOilFields;
        $landmarkCutoff = $oilFieldCutoff + $densityLandmarks;

        $radiusSquared = $radius * $radius;

        $postWeights = [
            'strength' => 1,
            'stealth' => 1,
            'fort' => 1,
            'tech' => 1,
            'general' => 1,
        ];

        $tiles = [];

        for ($y = -$radius; $y <= $radius; $y++) {
            for ($x = -$radius; $x <= $radius; $x++) {
                if (($x * $x + $y * $y) > $radiusSquared) {
                    continue;
                }

                $eventKey = "{$seed}:{$x}:{$y}";
                $tileSeed = (int) sprintf('%u', crc32($eventKey));

                if ($x === 0 && $y === 0) {
                    $tiles[] = [
                        'x' => $x,
                        'y' => $y,
                        'type' => 'landmark',
                        'subtype' => 'the_landing',
                        'seed' => $tileSeed,
                    ];

                    continue;
                }

                $roll = $this->rng->rollFloat('world.tile_type', $eventKey, 0.0, 1.0);

                if ($roll < $postCutoff) {
                    $postType = $this->rng->rollWeighted('world.post_type', $eventKey, $postWeights);
                    $tiles[] = [
                        'x' => $x,
                        'y' => $y,
                        'type' => 'post',
                        'subtype' => (string) $postType,
                        'seed' => $tileSeed,
                    ];
                } elseif ($roll < $oilFieldCutoff) {
                    $tiles[] = [
                        'x' => $x,
                        'y' => $y,
                        'type' => 'oil_field',
                        'subtype' => null,
                        'seed' => $tileSeed,
                    ];
                } elseif ($roll < $landmarkCutoff) {
                    $tiles[] = [
                        'x' => $x,
                        'y' => $y,
                        'type' => 'landmark',
                        'subtype' => 'ruin',
                        'seed' => $tileSeed,
                    ];
                } else {
                    $tiles[] = [
                        'x' => $x,
                        'y' => $y,
                        'type' => 'wasteland',
                        'subtype' => null,
                        'seed' => $tileSeed,
                    ];
                }
            }
        }

        return $tiles;
    }

    /**
     * Persist the planned initial world to the database.
     *
     * Calls planInitialWorld(), then bulk-inserts tiles, oil_fields,
     * drill_points (25 per field, quality rolled from config), and posts
     * inside a single transaction. Child-table inserts need the tile IDs
     * that MySQL assigns, so we do a re-fetch pass after the tile insert
     * to build an (x,y)→id map, and another after the oil_field insert
     * to build a tile_id→oil_field_id map.
     *
     * Returns a stats array with row counts per table.
     *
     * Chunks inserts at 500 rows to stay well under MySQL's
     * max_allowed_packet for a typical radius-25 world (~2000 tiles).
     *
     * @return array{tiles:int, oil_fields:int, drill_points:int, posts:int}
     */
    public function generateInitialWorld(int $seed = 0): array
    {
        $plan = $this->planInitialWorld($seed);
        $qualityWeights = (array) $this->config->get('drilling.quality_weights');

        $stats = ['tiles' => 0, 'oil_fields' => 0, 'drill_points' => 0, 'posts' => 0];

        DB::transaction(function () use ($plan, $seed, $qualityWeights, &$stats) {
            $now = now();

            // 1. Bulk-insert all tiles.
            $tileRows = array_map(fn (array $spec) => [
                'x' => $spec['x'],
                'y' => $spec['y'],
                'type' => $spec['type'],
                'subtype' => $spec['subtype'],
                'seed' => $spec['seed'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $plan);

            foreach (array_chunk($tileRows, 500) as $chunk) {
                Tile::insert($chunk);
            }
            $stats['tiles'] = count($tileRows);

            // 2. Re-fetch tiles to map (x,y) -> id for child inserts.
            $tileIdByXy = Tile::query()
                ->get(['id', 'x', 'y'])
                ->mapWithKeys(fn (Tile $t) => ["{$t->x}:{$t->y}" => $t->id])
                ->all();

            // 3. Bulk-insert oil_fields for every planned oil_field tile.
            $oilFieldTiles = array_values(array_filter($plan, fn ($t) => $t['type'] === 'oil_field'));
            $oilFieldRows = array_map(fn (array $spec) => [
                'tile_id' => $tileIdByXy["{$spec['x']}:{$spec['y']}"],
                'drill_grid_rows' => 5,
                'drill_grid_cols' => 5,
                'last_regen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $oilFieldTiles);

            foreach (array_chunk($oilFieldRows, 500) as $chunk) {
                OilField::insert($chunk);
            }
            $stats['oil_fields'] = count($oilFieldRows);

            // 4. Re-fetch oil_fields for tile_id -> oil_field_id map.
            $oilFieldIdByTile = OilField::query()
                ->get(['id', 'tile_id'])
                ->mapWithKeys(fn (OilField $of) => [$of->tile_id => $of->id])
                ->all();

            // 5. Build + bulk-insert drill_points (5×5 per oil field, quality
            //    rolled deterministically per sub-cell via RngService).
            $pointRows = [];
            foreach ($oilFieldTiles as $spec) {
                $tileId = $tileIdByXy["{$spec['x']}:{$spec['y']}"];
                $fieldId = $oilFieldIdByTile[$tileId];

                for ($gy = 0; $gy < 5; $gy++) {
                    for ($gx = 0; $gx < 5; $gx++) {
                        $quality = (string) $this->rng->rollWeighted(
                            'drilling.initial_quality',
                            "{$seed}:{$spec['x']}:{$spec['y']}:{$gx}:{$gy}",
                            $qualityWeights,
                        );

                        $pointRows[] = [
                            'oil_field_id' => $fieldId,
                            'grid_x' => $gx,
                            'grid_y' => $gy,
                            'quality' => $quality,
                            'drilled_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            foreach (array_chunk($pointRows, 500) as $chunk) {
                DrillPoint::insert($chunk);
            }
            $stats['drill_points'] = count($pointRows);

            // 6. Bulk-insert posts for every planned post tile.
            $postRows = [];
            foreach ($plan as $spec) {
                if ($spec['type'] !== 'post') {
                    continue;
                }

                $postRows[] = [
                    'tile_id' => $tileIdByXy["{$spec['x']}:{$spec['y']}"],
                    'post_type' => $spec['subtype'],
                    'name' => $this->pickPostName((string) $spec['subtype'], $spec['x'], $spec['y'], $seed),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($postRows, 500) as $chunk) {
                Post::insert($chunk);
            }
            $stats['posts'] = count($postRows);
        });

        return $stats;
    }

    /**
     * Drop a freshly-created user onto Akzar.
     *
     * Picks a wasteland tile inside the configured spawn band, converts
     * it to a player base, creates the Player row with the full starting
     * loadout from GameConfig, and auto-discovers the spawn tile plus its
     * four cardinal neighbors via FogOfWarService so the new player can
     * see something on the map from move one.
     *
     * Tile selection is deterministic: candidate tiles are ordered by id,
     * and RngService::rollInt picks an index keyed on the user id. Same
     * user id against the same world always lands on the same tile, which
     * makes spawn reproducible in tests and auditable in disputes.
     *
     * Throws if no wasteland tiles remain inside the spawn band (world
     * too full or not yet generated).
     */
    public function spawnPlayer(int $userId): Player
    {
        $spawnRadius = (int) $this->config->get('world.spawn_band_radius');
        $spawnRadiusSq = $spawnRadius * $spawnRadius;

        return DB::transaction(function () use ($userId, $spawnRadiusSq) {
            $candidateIds = Tile::query()
                ->where('type', 'wasteland')
                ->whereRaw('(x * x + y * y) <= ?', [$spawnRadiusSq])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($candidateIds === []) {
                throw new RuntimeException('WorldService::spawnPlayer: no wasteland tiles available inside the spawn band');
            }

            $index = $this->rng->rollInt(
                'world.spawn',
                (string) $userId,
                0,
                count($candidateIds) - 1,
            );

            /** @var Tile $spawnTile */
            $spawnTile = Tile::findOrFail($candidateIds[$index]);

            $spawnTile->update([
                'type' => 'base',
                'subtype' => null,
            ]);

            $player = Player::create([
                'user_id' => $userId,
                'base_tile_id' => $spawnTile->id,
                'current_tile_id' => $spawnTile->id,
                'akzar_cash' => (float) $this->config->get('new_player.starting_cash'),
                'oil_barrels' => 0,
                'intel' => 0,
                'moves_current' => (int) $this->config->get('moves.daily_regen'),
                'moves_updated_at' => now(),
                'sponsor_moves_used_this_cycle' => 0,
                'strength' => (int) $this->config->get('stats.starting.strength'),
                'fortification' => (int) $this->config->get('stats.starting.fortification'),
                'stealth' => (int) $this->config->get('stats.starting.stealth'),
                'security' => (int) $this->config->get('stats.starting.security'),
                'drill_tier' => (int) $this->config->get('new_player.starting_drill_tier'),
                'immunity_expires_at' => now()->addHours((int) $this->config->get('new_player.immunity_hours')),
            ]);

            $this->fogOfWar->markDiscoveredMany(
                $player->id,
                $this->spawnDiscoveryTileIds($spawnTile),
            );

            return $player;
        });
    }

    /**
     * Return the list of tile IDs a new player should see on spawn:
     * the base tile itself plus the four cardinal-adjacent neighbors
     * (those that exist — edge-of-world spawns still work).
     *
     * @return list<int>
     */
    private function spawnDiscoveryTileIds(Tile $spawnTile): array
    {
        $neighborIds = Tile::query()
            ->where(function ($q) use ($spawnTile) {
                $q->where(['x' => $spawnTile->x + 1, 'y' => $spawnTile->y])
                    ->orWhere(['x' => $spawnTile->x - 1, 'y' => $spawnTile->y])
                    ->orWhere(['x' => $spawnTile->x, 'y' => $spawnTile->y + 1])
                    ->orWhere(['x' => $spawnTile->x, 'y' => $spawnTile->y - 1]);
            })
            ->pluck('id')
            ->all();

        return array_merge([$spawnTile->id], $neighborIds);
    }

    /**
     * Ring expansion — adds a new ring of tiles on the frontier when
     * population density crosses the configured threshold. Called by
     * WorldGrowthJob nightly. Implemented in Phase 5.
     */
    public function expandWorld(): int
    {
        throw new RuntimeException('WorldService::expandWorld not implemented until Phase 5');
    }

    /**
     * Convert the bases of players inactive for N days into ruin tiles
     * with a random one-time loot reward. Called by AbandonmentJob daily.
     * Implemented in Phase 5.
     */
    public function decayAbandoned(): int
    {
        throw new RuntimeException('WorldService::decayAbandoned not implemented until Phase 5');
    }

    /**
     * Pick a deterministic flavor name for a post at (x, y) under the
     * given world seed. Independent of plan-time rolls so the post name
     * is stable across plan regenerations.
     */
    private function pickPostName(string $postType, int $x, int $y, int $seed): string
    {
        $names = self::POST_NAMES[$postType] ?? [];

        if ($names === []) {
            return ucfirst($postType).' Post';
        }

        $index = $this->rng->rollInt(
            'world.post_name',
            "{$seed}:{$x}:{$y}",
            0,
            count($names) - 1,
        );

        return $names[$index];
    }
}
