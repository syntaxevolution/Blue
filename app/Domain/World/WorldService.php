<?php

namespace App\Domain\World;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Notifications\ActivityLogService;
use App\Models\Casino;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Post;
use App\Models\Tile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    private const CASINO_NAMES = [
        "Roughneck's Saloon",
        'The Lucky Derrick',
        "Gusher's Den",
        'The Pipeline Lounge',
        'Barrel & Bone Casino',
    ];

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly FogOfWarService $fogOfWar,
        private readonly ActivityLogService $activityLog,
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
                'casinos_per_tile' => (float) $this->config->get('world.density.casinos_per_tile'),
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
        $radiusSquared = $radius * $radius;

        $tiles = [];

        for ($y = -$radius; $y <= $radius; $y++) {
            for ($x = -$radius; $x <= $radius; $x++) {
                if (($x * $x + $y * $y) > $radiusSquared) {
                    continue;
                }

                $tiles[] = $this->rollTileSpec($x, $y, $seed);
            }
        }

        return $tiles;
    }

    /**
     * Plan tile specs for all integer (x,y) in the annulus
     * innerRSqExclusive < x²+y² <= outerRSqInclusive. Used by growth
     * to generate the next frontier ring without re-emitting tiles
     * that already exist. (0,0) is always inside the initial world so
     * the_landing is never re-rolled here.
     *
     * @return list<array{x:int,y:int,type:string,subtype:string|null,seed:int}>
     */
    public function planAnnulus(int $innerRSqExclusive, int $outerRSqInclusive, int $seed): array
    {
        $outerR = (int) ceil(sqrt((float) $outerRSqInclusive));

        $tiles = [];
        for ($y = -$outerR; $y <= $outerR; $y++) {
            for ($x = -$outerR; $x <= $outerR; $x++) {
                $rSq = $x * $x + $y * $y;
                if ($rSq <= $innerRSqExclusive || $rSq > $outerRSqInclusive) {
                    continue;
                }

                $tiles[] = $this->rollTileSpec($x, $y, $seed);
            }
        }

        return $tiles;
    }

    /**
     * Roll a single deterministic tile spec for (x,y) under $seed.
     * Shared by planInitialWorld and planAnnulus so the two paths
     * use identical density cutoffs and post-type weighting. (0,0)
     * is reserved for the_landing landmark and returns directly.
     *
     * @return array{x:int,y:int,type:string,subtype:string|null,seed:int}
     */
    private function rollTileSpec(int $x, int $y, int $seed): array
    {
        $eventKey = "{$seed}:{$x}:{$y}";
        $tileSeed = (int) sprintf('%u', crc32($eventKey));

        if ($x === 0 && $y === 0) {
            return [
                'x' => $x,
                'y' => $y,
                'type' => 'landmark',
                'subtype' => 'the_landing',
                'seed' => $tileSeed,
            ];
        }

        $densityPosts = (float) $this->config->get('world.density.posts_per_tile');
        $densityOilFields = (float) $this->config->get('world.density.oil_fields_per_tile');
        $densityLandmarks = (float) $this->config->get('world.density.landmarks_per_tile');
        $densityCasinos = (float) $this->config->get('world.density.casinos_per_tile');

        $postCutoff = $densityPosts;
        $oilFieldCutoff = $postCutoff + $densityOilFields;
        $landmarkCutoff = $oilFieldCutoff + $densityLandmarks;
        $casinoCutoff = $landmarkCutoff + $densityCasinos;

        $postWeights = [
            'strength' => 1,
            'stealth' => 1,
            'fort' => 1,
            'tech' => 1,
            'general' => 1,
        ];

        $roll = $this->rng->rollFloat('world.tile_type', $eventKey, 0.0, 1.0);

        if ($roll < $postCutoff) {
            $postType = $this->rng->rollWeighted('world.post_type', $eventKey, $postWeights);

            return [
                'x' => $x,
                'y' => $y,
                'type' => 'post',
                'subtype' => (string) $postType,
                'seed' => $tileSeed,
            ];
        }

        if ($roll < $oilFieldCutoff) {
            return [
                'x' => $x,
                'y' => $y,
                'type' => 'oil_field',
                'subtype' => null,
                'seed' => $tileSeed,
            ];
        }

        if ($roll < $landmarkCutoff) {
            return [
                'x' => $x,
                'y' => $y,
                'type' => 'landmark',
                'subtype' => 'ruin',
                'seed' => $tileSeed,
            ];
        }

        if ($roll < $casinoCutoff) {
            return [
                'x' => $x,
                'y' => $y,
                'type' => 'casino',
                'subtype' => null,
                'seed' => $tileSeed,
            ];
        }

        return [
            'x' => $x,
            'y' => $y,
            'type' => 'wasteland',
            'subtype' => null,
            'seed' => $tileSeed,
        ];
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
     * @return array{tiles:int, oil_fields:int, drill_points:int, posts:int, casinos:int}
     */
    public function generateInitialWorld(int $seed = 0): array
    {
        $plan = $this->planInitialWorld($seed);

        return $this->persistTilePlan($plan, $seed);
    }

    /**
     * Persist a list of tile specs to the DB, wiring up oil_fields,
     * drill_points, and posts in a single transaction. Shared by
     * generateInitialWorld and expandWorld so both growth paths use
     * identical insertion logic.
     *
     * Idempotency-ish: this method assumes none of the planned (x,y)
     * tiles already exist. Callers MUST enforce that (initial world:
     * always empty when called; growth: coordinate filter excludes the
     * existing disc).
     *
     * @param  list<array{x:int,y:int,type:string,subtype:string|null,seed:int}>  $plan
     * @return array{tiles:int, oil_fields:int, drill_points:int, posts:int, casinos:int}
     */
    private function persistTilePlan(array $plan, int $seed): array
    {
        $qualityWeights = (array) $this->config->get('drilling.quality_weights');
        $stats = ['tiles' => 0, 'oil_fields' => 0, 'drill_points' => 0, 'posts' => 0, 'casinos' => 0];

        if ($plan === []) {
            return $stats;
        }

        DB::transaction(function () use ($plan, $seed, $qualityWeights, &$stats) {
            $now = now();

            // 1. Bulk-insert planned tiles.
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

            // 2. Map (x,y) -> id for just the newly-inserted tiles.
            //    Scoping the re-fetch to the planned coordinates keeps
            //    growth runs cheap — there's no need to pull every tile
            //    that was already in the world.
            $planCoords = array_map(fn ($t) => [$t['x'], $t['y']], $plan);
            $tileIdByXy = Tile::query()
                ->where(function ($q) use ($planCoords) {
                    foreach ($planCoords as [$x, $y]) {
                        $q->orWhere(fn ($q2) => $q2->where('x', $x)->where('y', $y));
                    }
                })
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

            // 4. Re-fetch oil_fields restricted to the new tile IDs.
            $newTileIds = array_values($tileIdByXy);
            $oilFieldIdByTile = OilField::query()
                ->whereIn('tile_id', $newTileIds)
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

            // 7. Bulk-insert casinos for every planned casino tile.
            $casinoRows = [];
            foreach ($plan as $spec) {
                if ($spec['type'] !== 'casino') {
                    continue;
                }

                $casinoRows[] = [
                    'tile_id' => $tileIdByXy["{$spec['x']}:{$spec['y']}"],
                    'name' => $this->pickCasinoName($spec['x'], $spec['y'], $seed),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($casinoRows, 500) as $chunk) {
                Casino::insert($chunk);
            }
            $stats['casinos'] = count($casinoRows);
        });

        return $stats;
    }

    /**
     * Drop a freshly-created user onto Akzar.
     *
     * Picks a wasteland tile anywhere in the current world, converts it
     * to a player base, creates the Player row with the full starting
     * loadout from GameConfig, and auto-discovers the spawn tile plus its
     * four cardinal neighbors via FogOfWarService so the new player can
     * see something on the map from move one.
     *
     * Spawns are uniformly distributed across ALL wasteland tiles that
     * exist — no central-band constraint — so new bases get scattered
     * the same way posts, oil fields and casinos already are. The
     * `world.spawn_band_radius` config key is still used by other
     * systems (casino placement via CasinoRetrofit) and is intentionally
     * left in place.
     *
     * Tile selection is deterministic: candidate tiles are ordered by id,
     * and RngService::rollInt picks an index keyed on the user id. Same
     * user id against the same world always lands on the same tile, which
     * makes spawn reproducible in tests and auditable in disputes.
     *
     * Throws if no wasteland tiles remain anywhere in the world (world
     * too full or not yet generated).
     */
    public function spawnPlayer(int $userId): Player
    {
        return DB::transaction(function () use ($userId) {
            $candidateIds = Tile::query()
                ->where('type', 'wasteland')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($candidateIds === []) {
                throw new RuntimeException('WorldService::spawnPlayer: no wasteland tiles available anywhere in the world');
            }

            $index = $this->rng->rollInt(
                'world.spawn',
                (string) $userId,
                0,
                count($candidateIds) - 1,
            );

            // Lock the candidate row so a concurrent spawnPlayer for a
            // different user cannot pick the same tile between our read
            // and our base-conversion write. If another process beat us,
            // its conversion will have cleared 'wasteland' — walk the
            // RNG-ordered candidate list until we find one still usable.
            $spawnTile = null;
            $probeCount = min(count($candidateIds), 32);
            for ($i = 0; $i < $probeCount; $i++) {
                $probeIndex = ($index + $i) % count($candidateIds);
                /** @var Tile|null $probe */
                $probe = Tile::query()
                    ->whereKey($candidateIds[$probeIndex])
                    ->lockForUpdate()
                    ->first();
                if ($probe !== null && $probe->type === 'wasteland') {
                    $spawnTile = $probe;
                    break;
                }
            }

            if ($spawnTile === null) {
                throw new RuntimeException('WorldService::spawnPlayer: could not claim a wasteland tile after concurrent race');
            }

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
     * Ring expansion — adds a one-tile-thick integer ring to the world
     * frontier when population density crosses the configured threshold.
     *
     * Decision flow:
     *   1. If world.growth.enabled = false, return 0 (kill-switch).
     *   2. Count human players (bots excluded by email domain) and total
     *      tiles. If density <= world.growth.trigger_players_per_tile,
     *      return 0 — the world is still roomy enough.
     *   3. Otherwise compute the next integer frontier radius:
     *        current_r = ceil(sqrt(max(x²+y²)))
     *        new_r = current_r + 1
     *      and plan every integer (x,y) in the annulus
     *        (current_max_r_sq, new_r²]
     *      using planAnnulus(). The density config drives the type mix
     *      exactly as it did for the initial disc.
     *   4. Persist the plan via the shared persistTilePlan() helper.
     *
     * Returns the number of new tiles added (0 if nothing happened).
     * Safe to call concurrently — the whole thing runs inside a single
     * DB transaction and the coordinate filter guarantees we never
     * duplicate tiles that already exist.
     */
    public function expandWorld(): int
    {
        if (! (bool) $this->config->get('world.growth.enabled')) {
            return 0;
        }

        // Application-level mutex. Two concurrent callers (scheduler +
        // a manual `php artisan world:grow`, or any future admin API
        // hook) would otherwise both read the same density, both compute
        // the same target ring, and the second transaction would
        // explode on the tiles.(x,y) unique index mid-insertion. The
        // lock wraps the density check AND the insert so those steps
        // are effectively atomic from any other growth caller's point
        // of view. `withoutOverlapping` on the scheduler protects
        // schedule-vs-schedule only — this covers all other paths too.
        //
        // `->get()` acquires non-blockingly: returns true if this caller
        // owns the lock, false if another process is already mid-growth.
        // 120-second owner TTL matches the scheduler's 30-minute window
        // with headroom for a slow DB — a stuck PHP worker can't wedge
        // growth forever.
        $lock = Cache::lock('world.growth.expand', 120);

        if (! $lock->get()) {
            Log::info('world.growth.skipped_lock_contended');
            return 0;
        }

        try {
            $trigger = (float) $this->config->get('world.growth.trigger_players_per_tile');
            $density = $this->currentHumanPlayerDensity();

            if ($density <= $trigger) {
                return 0;
            }

            // Current max radius squared — the outer edge of the existing disc.
            $currentMaxRSq = (int) Tile::query()->selectRaw('COALESCE(MAX(x * x + y * y), 0) as max_sq')->value('max_sq');

            if ($currentMaxRSq <= 0) {
                // No world yet — refuse to grow an empty map. The caller
                // should run the initial world generation first.
                return 0;
            }

            $currentR = (int) ceil(sqrt((float) $currentMaxRSq));
            $ringWidth = max(1, (int) $this->config->get('world.growth.expansion_ring_width'));
            $newR = $currentR + $ringWidth;
            $newRSq = $newR * $newR;

            // Seed derived from the target radius — unique per growth pass,
            // deterministic, and orthogonal to whatever seed the initial
            // world used (event keys embed the seed + coordinates).
            $growthSeed = $newRSq;

            $plan = $this->planAnnulus($currentMaxRSq, $newRSq, $growthSeed);
            if ($plan === []) {
                return 0;
            }

            $stats = $this->persistTilePlan($plan, $growthSeed);

            Log::info('world.growth.ring_added', [
                'from_max_r_sq' => $currentMaxRSq,
                'to_max_r_sq' => $newRSq,
                'new_r' => $newR,
                'tiles_added' => $stats['tiles'],
                'oil_fields_added' => $stats['oil_fields'],
                'posts_added' => $stats['posts'],
                'casinos_added' => $stats['casinos'],
                'density_before' => $density,
                'trigger' => $trigger,
            ]);

            // Surface the growth event to players so the frontier appearing
            // isn't silent. Uses the system-wide activity log — any player
            // whose map state gets rebuilt after this will see the entry
            // in their activity feed.
            try {
                $this->activityLog->systemBroadcast(
                    'world_growth',
                    sprintf(
                        'The frontier has expanded. %d new tiles lie beyond the old edge — explore to find them.',
                        (int) $stats['tiles'],
                    ),
                    [
                        'tiles_added' => (int) $stats['tiles'],
                        'new_radius' => $newR,
                    ],
                );
            } catch (\Throwable $e) {
                // Non-critical — don't roll back the ring over a broadcast failure.
                Log::warning('world.growth.broadcast_failed', ['error' => $e->getMessage()]);
            }

            return (int) $stats['tiles'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Density used for the growth trigger: (human player count) / (tile count).
     * Bots are excluded because they're spawned en masse via bots:spawn and
     * would otherwise force a ring expansion the moment you seed a test
     * population. Exposed for the world:grow command's dry-run output.
     */
    public function currentHumanPlayerDensity(): float
    {
        $botDomain = (string) $this->config->get('bots.email_domain');

        // Humans = users whose email does NOT have an exact '@<bot_domain>'
        // suffix. LIKE without a trailing '%' anchors to the end of the
        // string, so `%@bots.cashclash.local` only matches emails that END
        // with that domain — `attacker@bots.cashclash.local.evil.com` is
        // correctly counted as human because `.evil.com` follows the
        // would-be match. A DB-level CASE-sensitive collation is assumed
        // (MariaDB default `utf8mb4_unicode_ci` is case-insensitive for
        // LIKE, which is fine here since email addresses are lowercased
        // at registration by Laravel's validation).
        $suffix = '%@'.$botDomain;

        $humanCount = (int) Player::query()
            ->whereHas('user', function ($q) use ($suffix) {
                $q->where('email', 'not like', $suffix);
            })
            ->count();

        $tileCount = (int) Tile::query()->count();
        if ($tileCount === 0) {
            return 0.0;
        }

        return $humanCount / $tileCount;
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

    private function pickCasinoName(int $x, int $y, int $seed): string
    {
        $names = (array) $this->config->get('casino.names', self::CASINO_NAMES);

        if ($names === []) {
            return "Roughneck's Saloon";
        }

        $index = $this->rng->rollInt(
            'world.casino_name',
            "{$seed}:{$x}:{$y}",
            0,
            count($names) - 1,
        );

        return $names[$index];
    }
}
