<?php

use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
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

    // At least 4 more tiles should be discovered — the cardinal neighbors.
    // (May be fewer if the spawn happens at the very edge of the disc, but
    // with spawn_band_radius = 12 and initial_radius = 25, there's plenty
    // of buffer so all 4 always exist.)
    expect($fog->countDiscovered($player->id))->toBe(5);
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

it('deleting a player cascades and clears their discoveries', function () {
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $playerId = $player->id;
    $player->delete();

    expect(DB::table('tile_discoveries')->where('player_id', $playerId)->count())->toBe(0);
});
