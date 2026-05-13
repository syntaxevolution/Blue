<?php

use App\Domain\Config\GameConfigResolver;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
use App\Models\ActivityLog;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Feature tests for FogOfWarService + spawnPlayer auto-discovery hook
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

it('spawnPlayer auto-discovers the spawn tile plus adjacent neighbors', function () {
    $user = User::factory()->create();

    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    // Spawn tile itself must be discovered.
    expect($fog->hasDiscovered($player->id, $player->base_tile_id))->toBeTrue();

    // Spawn tile plus up to 4 cardinal neighbors. Spawns can now land
    // anywhere in the disc (including edges), so a base on the rim of
    // the world will have fewer than 4 in-world neighbors — anything
    // from 1 (lone tile at the corner, no neighbors exist) up to 5 is
    // valid. The important invariant is that we at least marked the
    // spawn tile itself.
    $discovered = $fog->countDiscovered($player->id);
    expect($discovered)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(5);
});

it('markDiscovered is idempotent — re-marking does not duplicate rows', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    $before = $fog->countDiscovered($player->id);

    // Re-mark the spawn tile — should be a no-op.
    $fog->markDiscovered($player->id, $player->base_tile_id);
    $fog->markDiscovered($player->id, $player->base_tile_id);
    $fog->markDiscovered($player->id, $player->base_tile_id);

    expect($fog->countDiscovered($player->id))->toBe($before);
});

it('markDiscoveredMany deduplicates its input', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    $tileIds = Tile::query()->limit(10)->pluck('id')->all();
    // Feed the same list twice — second call should be a no-op.
    $fog->markDiscoveredMany($player->id, $tileIds);
    $fog->markDiscoveredMany($player->id, $tileIds);

    $discovered = $fog->getDiscoveredTileIds($player->id);

    // Spawn already discovered 5 tiles; the bulk add brings in at most 10
    // more (some may overlap with the spawn neighborhood).
    expect(count($discovered))->toBeGreaterThanOrEqual(10);
    expect(count($discovered))->toBeLessThanOrEqual(15);
});

it('revealRadius covers every tile inside the disc around the center', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    // Reveal a radius-3 disc around origin (the_landing landmark).
    $origin = Tile::where(['x' => 0, 'y' => 0])->firstOrFail();
    $fog->revealRadius($player->id, $origin->id, 3);

    // Every (x, y) with x² + y² <= 9 should now be discovered.
    $expectedTiles = Tile::query()
        ->whereRaw('(x * x + y * y) <= 9')
        ->pluck('id')
        ->all();

    foreach ($expectedTiles as $tileId) {
        expect($fog->hasDiscovered($player->id, $tileId))->toBeTrue();
    }
});

it('hasDiscovered returns false for tiles the player has not seen', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    // Find a far-edge tile that can't be in the spawn neighborhood.
    $farTile = Tile::where('x', 25)->where('y', 0)->first()
        ?? Tile::query()->orderByDesc('x')->first();

    expect($fog->hasDiscovered($player->id, $farTile->id))->toBeFalse();
});

it('getDiscoveredTileIds returns the full discovered set ordered by tile_id', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    $ids = $fog->getDiscoveredTileIds($player->id);
    $sorted = $ids;
    sort($sorted);

    expect($ids)->toEqual($sorted);
    expect($ids)->toHaveCount(5);
});

it('grants the fog completion reward once for the current world size', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    $fog->markDiscoveredMany($player->id, Tile::query()->pluck('id')->all());

    $reward = $fog->awardCompletionIfEligible($player->id);

    expect($reward)->not->toBeNull();
    expect($reward['reward_barrels'])->toBe(10_000);
    expect($player->fresh()->oil_barrels)->toBe(10_000);
    expect(ActivityLog::query()
        ->where('user_id', $user->id)
        ->where('type', 'fog.completed')
        ->count())->toBe(1);

    expect($fog->awardCompletionIfEligible($player->id))->toBeNull();
    expect($player->fresh()->oil_barrels)->toBe(10_000);
    expect(ActivityLog::query()
        ->where('user_id', $user->id)
        ->where('type', 'fog.completed')
        ->count())->toBe(1);
});

it('awards an already-complete fog map when the player opens the map', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    $tileCount = Tile::query()->count();
    $fog->markDiscoveredMany($player->id, Tile::query()->pluck('id')->all());

    $response = $this->actingAs($user)->get('/map');

    $response->assertRedirect(route('map.show'));
    $response->assertSessionHas('fog_completion_reward', [
        'reward_barrels' => 10_000,
        'tile_count' => $tileCount,
        'discovered_count' => $tileCount,
        'oil_barrels' => 10_000,
    ]);
    expect($player->fresh()->oil_barrels)->toBe(10_000);

    $this->actingAs($user)->get('/map')->assertOk();
    expect($player->fresh()->oil_barrels)->toBe(10_000);
});

it('grants the fog completion reward again after the world expands and new tiles are discovered', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $fog = app(FogOfWarService::class);

    $originalTileIds = Tile::query()->pluck('id')->all();
    $fog->markDiscoveredMany($player->id, $originalTileIds);
    expect($fog->awardCompletionIfEligible($player->id))->not->toBeNull();

    config([
        'game.world.growth.enabled' => true,
        'game.world.growth.trigger_players_per_tile' => 0.0,
        'game.world.growth.expansion_ring_width' => 1,
    ]);
    app()->forgetInstance(GameConfigResolver::class);
    app()->forgetInstance(WorldService::class);

    $added = app(WorldService::class)->expandWorld();
    expect($added)->toBeGreaterThan(0);

    expect($fog->awardCompletionIfEligible($player->id))->toBeNull();
    expect($player->fresh()->oil_barrels)->toBe(10_000);

    $newTileIds = Tile::query()
        ->whereNotIn('id', $originalTileIds)
        ->pluck('id')
        ->all();
    expect($newTileIds)->not->toBeEmpty();

    $fog->markDiscoveredMany($player->id, $newTileIds);
    $secondReward = $fog->awardCompletionIfEligible($player->id);

    expect($secondReward)->not->toBeNull();
    expect($secondReward['reward_barrels'])->toBe(10_000);
    expect($player->fresh()->oil_barrels)->toBe(20_000);
    expect(ActivityLog::query()
        ->where('user_id', $user->id)
        ->where('type', 'fog.completed')
        ->count())->toBe(2);
});

it('deleting a player cascades and clears their discoveries', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $playerId = $player->id;
    $player->delete();

    expect(DB::table('tile_discoveries')->where('player_id', $playerId)->count())->toBe(0);
});
