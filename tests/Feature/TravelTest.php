<?php

use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\TravelService;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Feature tests for TravelService::travel and the dual MapControllers
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
});

function spawnPlayerAt(int $x, int $y): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Teleport the player to a specific coordinate for direction tests.
    $tile = Tile::where(['x' => $x, 'y' => $y])->firstOrFail();
    $player->update(['current_tile_id' => $tile->id]);

    return $player->fresh();
}

/*
|--------------------------------------------------------------------------
| TravelService
|--------------------------------------------------------------------------
*/

it('moves a player one tile north and deducts one move', function () {
    $player = spawnPlayerAt(0, 5);
    $startMoves = $player->moves_current;

    $destination = app(TravelService::class)->travel($player->id, 'n');

    expect($destination->x)->toBe(0);
    expect($destination->y)->toBe(6);
    expect($player->fresh()->current_tile_id)->toBe($destination->id);
    expect($player->fresh()->moves_current)->toBe($startMoves - 1);
});

it('moves south, east, and west correctly', function () {
    $svc = app(TravelService::class);

    $player = spawnPlayerAt(5, 5);
    expect($svc->travel($player->id, 's')->y)->toBe(4);

    $player = spawnPlayerAt(5, 5);
    expect($svc->travel($player->id, 'e')->x)->toBe(6);

    $player = spawnPlayerAt(5, 5);
    expect($svc->travel($player->id, 'w')->x)->toBe(4);
});

it('marks the arrival tile as discovered via fog of war', function () {
    $player = spawnPlayerAt(0, 5);

    $destination = app(TravelService::class)->travel($player->id, 'n');

    expect(app(FogOfWarService::class)->hasDiscovered($player->id, $destination->id))->toBeTrue();
});

it('throws InsufficientMovesException when moves_current is below cost', function () {
    $player = spawnPlayerAt(0, 5);
    $player->update(['moves_current' => 0, 'moves_updated_at' => now()]);

    expect(fn () => app(TravelService::class)->travel($player->id, 'n'))
        ->toThrow(InsufficientMovesException::class);
});

it('throws CannotTravelException at the edge of the world', function () {
    // Place the player at the farthest tile on the east edge that actually
    // exists in the disc, then try to travel further east — no neighbor.
    $eastEdge = Tile::orderByDesc('x')->first();
    $player = spawnPlayerAt($eastEdge->x, $eastEdge->y);

    expect(fn () => app(TravelService::class)->travel($player->id, 'e'))
        ->toThrow(CannotTravelException::class);
});

it('throws CannotTravelException for an invalid direction', function () {
    $player = spawnPlayerAt(0, 5);

    expect(fn () => app(TravelService::class)->travel($player->id, 'up'))
        ->toThrow(CannotTravelException::class);
});

/*
|--------------------------------------------------------------------------
| Web\MapController (Inertia)
|--------------------------------------------------------------------------
*/

it('GET /map auto-spawns a user with no player and renders the map view', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/map');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Game/Map')
        ->has('state.player.moves_current')
        ->has('state.current_tile.x')
        ->has('state.neighbors')
    );

    expect($user->fresh()->player)->not->toBeNull();
});

it('POST /map/move travels and redirects back to /map', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map'); // auto-spawn

    $response = $this->actingAs($user)->post('/map/move', ['direction' => 'n']);

    $response->assertRedirect('/map');
    // No validation/travel error flashed.
    $response->assertSessionMissing('errors.travel');
});

it('POST /map/move flashes an error on insufficient moves', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map');
    $user->player->update(['moves_current' => 0, 'moves_updated_at' => now()]);

    $response = $this->actingAs($user)->post('/map/move', ['direction' => 'n']);

    $response->assertRedirect('/map');
    $response->assertSessionHasErrors(['travel']);
});

it('POST /map/move rejects an invalid direction via form validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map');

    $response = $this->actingAs($user)->post('/map/move', ['direction' => 'up']);

    $response->assertSessionHasErrors(['direction']);
});

/*
|--------------------------------------------------------------------------
| Api\V1\MapController (REST)
|--------------------------------------------------------------------------
*/

it('GET /api/v1/map returns 401 without a token', function () {
    $this->getJson('/api/v1/map')->assertUnauthorized();
});

it('GET /api/v1/map returns the map state with a valid token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/map');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'player' => ['id', 'moves_current', 'akzar_cash'],
            'current_tile' => ['x', 'y', 'type'],
            'neighbors',
            'discovered_count',
            'bank_cap',
        ],
    ]);
});

it('POST /api/v1/map/move travels and returns updated state', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    // First call auto-spawns the player.
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/map');

    $player = $user->fresh()->player;
    $startX = $player->currentTile->x;
    $startY = $player->currentTile->y;
    $startMoves = $player->moves_current;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/map/move', ['direction' => 'n']);

    $response->assertOk();
    $response->assertJsonPath('data.current_tile.x', $startX);
    $response->assertJsonPath('data.current_tile.y', $startY + 1);
    $response->assertJsonPath('data.player.moves_current', $startMoves - 1);
});

it('POST /api/v1/map/move returns 422 on insufficient moves', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/map');
    $user->fresh()->player->update(['moves_current' => 0, 'moves_updated_at' => now()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/map/move', ['direction' => 'n']);

    $response->assertStatus(422);
    $response->assertJsonStructure(['errors' => ['travel']]);
});

it('POST /api/v1/map/move rejects invalid direction with 422', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/map/move', ['direction' => 'up']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['direction']);
});
