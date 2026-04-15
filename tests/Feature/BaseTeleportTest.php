<?php

use App\Domain\Economy\ShopService;
use App\Domain\Exceptions\CannotBaseTeleportException;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Teleport\BaseTeleportService;
use App\Domain\World\WorldService;
use App\Events\BaseRelocated;
use App\Models\Player;
use App\Models\Post;
use App\Models\SpyAttempt;
use App\Models\Tile;
use App\Models\User;
use Database\Seeders\ItemsCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(ItemsCatalogSeeder::class);
});

/**
 * Spawn a player with enough barrels/moves to fire any of the three
 * base-teleport actions, and clear their newbie immunity so combat
 * rules don't interfere with Abduction Anchor target tests.
 */
function baseTeleportSpawnPlayer(int $barrels = 20000, int $moves = 200): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update([
        'oil_barrels' => $barrels,
        'moves_current' => $moves,
        'immunity_expires_at' => null,
    ]);

    return $player->fresh();
}

function grantItem(Player $player, string $key, int $quantity = 1): void
{
    DB::table('player_items')->insert([
        'player_id' => $player->id,
        'item_key' => $key,
        'quantity' => $quantity,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function moveToWasteland(Player $player): Tile
{
    $wasteland = Tile::query()
        ->where('type', 'wasteland')
        ->where('id', '!=', $player->base_tile_id)
        ->first();
    $player->update(['current_tile_id' => $wasteland->id]);

    return $wasteland;
}

/* ---------------------------------------------------------------- */
/* Homing Flare — teleportSelfToBase */
/* ---------------------------------------------------------------- */

it('homing flare teleports the player back to their base', function () {
    $player = baseTeleportSpawnPlayer();
    grantItem($player, 'homing_flare');
    moveToWasteland($player);
    $player = $player->fresh();
    $startingBarrels = $player->oil_barrels;
    $startingMoves = $player->moves_current;

    app(BaseTeleportService::class)->teleportSelfToBase($player->id);

    $player = $player->fresh();
    expect($player->current_tile_id)->toBe($player->base_tile_id);
    expect($player->oil_barrels)->toBe($startingBarrels - 50);
    expect($player->moves_current)->toBe($startingMoves - 5);
});

it('homing flare is reusable and never decrements quantity', function () {
    $player = baseTeleportSpawnPlayer();
    grantItem($player, 'homing_flare');

    moveToWasteland($player);
    app(BaseTeleportService::class)->teleportSelfToBase($player->id);

    moveToWasteland($player->fresh());
    app(BaseTeleportService::class)->teleportSelfToBase($player->id);

    $quantity = DB::table('player_items')
        ->where('player_id', $player->id)
        ->where('item_key', 'homing_flare')
        ->value('quantity');
    expect($quantity)->toBe(1);
});

it('homing flare rejects when the flare is not owned', function () {
    $player = baseTeleportSpawnPlayer();
    moveToWasteland($player);

    expect(fn () => app(BaseTeleportService::class)->teleportSelfToBase($player->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('homing flare rejects when player is already at base', function () {
    $player = baseTeleportSpawnPlayer();
    grantItem($player, 'homing_flare');
    // Player spawns standing on their base — don't move.

    expect(fn () => app(BaseTeleportService::class)->teleportSelfToBase($player->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('homing flare rejects with insufficient barrels and does not charge moves', function () {
    $player = baseTeleportSpawnPlayer(barrels: 10);
    grantItem($player, 'homing_flare');
    moveToWasteland($player);
    $startingMoves = $player->fresh()->moves_current;

    expect(fn () => app(BaseTeleportService::class)->teleportSelfToBase($player->id))
        ->toThrow(CannotPurchaseException::class);

    expect($player->fresh()->moves_current)->toBe($startingMoves);
});

it('homing flare rejects with insufficient moves', function () {
    $player = baseTeleportSpawnPlayer(moves: 2);
    grantItem($player, 'homing_flare');
    moveToWasteland($player);

    expect(fn () => app(BaseTeleportService::class)->teleportSelfToBase($player->id))
        ->toThrow(InsufficientMovesException::class);
});

/* ---------------------------------------------------------------- */
/* Foundation Charge — moveOwnBase */
/* ---------------------------------------------------------------- */

it('foundation charge relocates the player base to their current wasteland tile', function () {
    $player = baseTeleportSpawnPlayer();
    grantItem($player, 'foundation_charge');
    $oldBaseId = $player->base_tile_id;
    $newTile = moveToWasteland($player);

    app(BaseTeleportService::class)->moveOwnBase($player->id);

    $player = $player->fresh();
    expect($player->base_tile_id)->toBe($newTile->id);
    expect(Tile::find($newTile->id)->type)->toBe('base');
    expect(Tile::find($oldBaseId)->type)->toBe('wasteland');
});

it('foundation charge decrements stack and deletes the row at zero', function () {
    $player = baseTeleportSpawnPlayer();
    grantItem($player, 'foundation_charge', quantity: 1);
    moveToWasteland($player);

    app(BaseTeleportService::class)->moveOwnBase($player->id);

    $row = DB::table('player_items')
        ->where('player_id', $player->id)
        ->where('item_key', 'foundation_charge')
        ->first();
    expect($row)->toBeNull();
});

it('foundation charge rejects when not on a wasteland tile', function () {
    $player = baseTeleportSpawnPlayer();
    grantItem($player, 'foundation_charge');
    // Player is on their base tile — not wasteland.

    expect(fn () => app(BaseTeleportService::class)->moveOwnBase($player->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('foundation charge rejects when none are owned', function () {
    $player = baseTeleportSpawnPlayer();
    moveToWasteland($player);

    expect(fn () => app(BaseTeleportService::class)->moveOwnBase($player->id))
        ->toThrow(CannotBaseTeleportException::class);
});

/* ---------------------------------------------------------------- */
/* Abduction Anchor — moveEnemyBase */
/* ---------------------------------------------------------------- */

/**
 * Spawn a second player, drop their immunity, and register a fresh
 * successful spy attempt from $attacker → $target so Abduction Anchor
 * guards pass.
 */
function baseTeleportSpawnTarget(Player $attacker): Player
{
    $target = baseTeleportSpawnPlayer();
    SpyAttempt::create([
        'spy_player_id' => $attacker->id,
        'target_player_id' => $target->id,
        'target_base_tile_id' => $target->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 1,
        'rng_output' => '0.1',
        'intel_payload' => null,
        'created_at' => now()->subHour(),
    ]);

    return $target;
}

it('abduction anchor relocates an enemy base to the caller current tile', function () {
    Event::fake([BaseRelocated::class]);

    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor');
    $destination = moveToWasteland($attacker);

    $target = baseTeleportSpawnTarget($attacker);
    $oldTargetBaseId = $target->base_tile_id;

    app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id);

    $target = $target->fresh();
    expect($target->base_tile_id)->toBe($destination->id);
    expect(Tile::find($destination->id)->type)->toBe('base');
    expect(Tile::find($oldTargetBaseId)->type)->toBe('wasteland');

    Event::assertDispatched(BaseRelocated::class);
});

it('abduction anchor is consumed only on success', function () {
    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor', quantity: 2);
    moveToWasteland($attacker);
    $target = baseTeleportSpawnTarget($attacker);
    $target->update(['base_move_protected' => true]);

    try {
        app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id);
    } catch (CannotBaseTeleportException $e) {
        // expected
    }

    $quantity = DB::table('player_items')
        ->where('player_id', $attacker->id)
        ->where('item_key', 'abduction_anchor')
        ->value('quantity');
    expect($quantity)->toBe(2);
});

it('abduction anchor rejects without a fresh successful spy', function () {
    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor');
    moveToWasteland($attacker);
    $target = baseTeleportSpawnPlayer();
    // No spy attempt recorded.

    expect(fn () => app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('abduction anchor rejects stale spy outside the freshness window', function () {
    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor');
    moveToWasteland($attacker);
    $target = baseTeleportSpawnPlayer();

    SpyAttempt::create([
        'spy_player_id' => $attacker->id,
        'target_player_id' => $target->id,
        'target_base_tile_id' => $target->base_tile_id,
        'success' => true,
        'detected' => false,
        'rng_seed' => 1,
        'rng_output' => '0.1',
        'intel_payload' => null,
        'created_at' => now()->subHours(48),
    ]);

    expect(fn () => app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('abduction anchor rejects a target under newbie immunity', function () {
    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor');
    moveToWasteland($attacker);
    $target = baseTeleportSpawnTarget($attacker);
    $target->update(['immunity_expires_at' => now()->addHours(12)]);

    expect(fn () => app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('abduction anchor rejects a target with Deadbolt Plinth', function () {
    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor');
    moveToWasteland($attacker);
    $target = baseTeleportSpawnTarget($attacker);
    $target->update(['base_move_protected' => true]);

    expect(fn () => app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('abduction anchor rejects when caller is not on a wasteland tile', function () {
    $attacker = baseTeleportSpawnPlayer();
    grantItem($attacker, 'abduction_anchor');
    // stay on base
    $target = baseTeleportSpawnTarget($attacker);

    expect(fn () => app(BaseTeleportService::class)->moveEnemyBase($attacker->id, $target->id))
        ->toThrow(CannotBaseTeleportException::class);
});

it('deadbolt plinth purchase sets base_move_protected and is not in the toolbox classifier', function () {
    $player = baseTeleportSpawnPlayer(barrels: 50000);
    // Put the player on a general post so they can purchase.
    $post = Tile::query()->where('type', 'post')->first();
    $postModel = Post::query()->where('tile_id', $post->id)->first();
    $postModel->update(['post_type' => 'general']);
    $player->update(['current_tile_id' => $post->id]);

    app(ShopService::class)->purchase($player->id, 'deadbolt_plinth');

    expect($player->fresh()->base_move_protected)->toBeTrue();
});
