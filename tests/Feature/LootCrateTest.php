<?php

use App\Domain\Combat\AttackLogService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotOpenLootCrateException;
use App\Domain\Loot\LootCrateService;
use App\Domain\World\WorldService;
use App\Events\LootCrateOpened;
use App\Events\SabotageLootCrateTriggered;
use App\Models\Player;
use App\Models\Tile;
use App\Models\TileLootCrate;
use App\Models\User;
use Database\Seeders\ItemsCatalogSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(ItemsCatalogSeeder::class);
    Event::fake();
    // Loot cap math reads world tile count via cache — flush so
    // individual tests can't leak a count into each other.
    Cache::flush();
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function lootMakePlayer(int $immunityHours = 0, int $oilBarrels = 5000, float $cash = 25.00): Player
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);
    $player->update([
        'oil_barrels' => $oilBarrels,
        'akzar_cash' => $cash,
        'moves_current' => 200,
        'immunity_expires_at' => $immunityHours > 0 ? now()->addHours($immunityHours) : null,
    ]);

    return $player->fresh();
}

function lootPutOnWasteland(Player $player): Tile
{
    /** @var Tile|null $wasteland */
    $wasteland = Tile::query()->where('type', 'wasteland')->first();
    if ($wasteland === null) {
        test()->markTestSkipped('No wasteland tile in the test world.');
    }
    $player->update(['current_tile_id' => $wasteland->id]);

    return $wasteland;
}

function lootGiveItem(Player $player, string $key, int $qty = 1): void
{
    DB::table('player_items')->updateOrInsert(
        ['player_id' => $player->id, 'item_key' => $key],
        [
            'quantity' => $qty,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
}

/**
 * Force the RngService into replay mode with a queue of pre-picked
 * values so tests can steer loot rolls deterministically. Maps keys
 * under the form "{category}:{eventKey}" → list<mixed>.
 *
 * @param  array<string, list<mixed>>  $queue
 */
function lootForceRng(array $queue): void
{
    app(RngService::class)->enableReplayMode($queue);
}

function lootResetRng(): void
{
    app(RngService::class)->disableReplayMode();
}

/*
|--------------------------------------------------------------------------
| onArrival: real crate spawning
|--------------------------------------------------------------------------
*/

it('does not spawn a crate when arriving on a non-wasteland tile', function () {
    $player = lootMakePlayer();
    /** @var Tile|null $base */
    $base = Tile::query()->where('type', 'base')->first();
    if ($base === null) {
        test()->markTestSkipped('No base tile in the test world.');
    }
    $player->update(['current_tile_id' => $base->id]);

    $crate = app(LootCrateService::class)->onArrival($player->fresh(), $base);

    expect($crate)->toBeNull();
    expect(TileLootCrate::query()->count())->toBe(0);
});

it('spawns a real crate on a forced RNG hit', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);

    // Force the spawn-chance rollBool to return true by queueing
    // a true for the matching category+eventKey. The eventKey
    // includes a millisecond timestamp so we instead monkey-patch
    // the spawn_chance to 1.0 for this test to bypass replay.
    app(GameConfigResolver::class)->set('loot.real_crate.spawn_chance', 1.0);

    $crate = app(LootCrateService::class)->onArrival($player->fresh(), $tile);

    expect($crate)->not->toBeNull();
    expect($crate->placed_by_player_id)->toBeNull();
    expect($crate->device_key)->toBeNull();
    expect($crate->opened_at)->toBeNull();
    expect((int) $crate->tile_x)->toBe((int) $tile->x);
});

it('never spawns when the spawn chance is zero', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);

    app(GameConfigResolver::class)->set('loot.real_crate.spawn_chance', 0.0);

    $crate = app(LootCrateService::class)->onArrival($player->fresh(), $tile);

    expect($crate)->toBeNull();
    expect(TileLootCrate::query()->count())->toBe(0);
});

it('returns the existing crate if one is already on the tile', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);

    $existing = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    // Spawn chance of 0 so the only way to get a crate back is
    // via the existing-crate lookup.
    app(GameConfigResolver::class)->set('loot.real_crate.spawn_chance', 0.0);

    $crate = app(LootCrateService::class)->onArrival($player->fresh(), $tile);

    expect($crate)->not->toBeNull();
    expect((int) $crate->id)->toBe((int) $existing->id);
});

/*
|--------------------------------------------------------------------------
| open: real crate outcomes
|--------------------------------------------------------------------------
*/

it('opens a real crate to nothing when forced', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);
    $crate = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    // Make 'nothing' the only weight to force the outcome.
    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 1, 'oil' => 0, 'cash' => 0, 'item' => 0,
    ]);

    $outcome = app(LootCrateService::class)->open($player->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('nothing');
    expect($crate->fresh()->opened_at)->not->toBeNull();
});

it('opens a real crate for oil', function () {
    $player = lootMakePlayer(oilBarrels: 0);
    $tile = lootPutOnWasteland($player);
    $crate = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 0, 'oil' => 1, 'cash' => 0, 'item' => 0,
    ]);
    // Narrow the oil range so we can assert an exact floor.
    app(GameConfigResolver::class)->set('loot.real_crate.oil', [
        'min' => 500, 'max' => 500, 'weight_exponent' => 1.0,
    ]);

    $outcome = app(LootCrateService::class)->open($player->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('oil');
    expect((int) $outcome['barrels'])->toBe(500);

    $player->refresh();
    expect((int) $player->oil_barrels)->toBe(500);
});

it('opens a real crate for cash', function () {
    $player = lootMakePlayer(cash: 0);
    $tile = lootPutOnWasteland($player);
    $crate = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 0, 'oil' => 0, 'cash' => 1, 'item' => 0,
    ]);
    app(GameConfigResolver::class)->set('loot.real_crate.cash', [
        'min' => 3.50, 'max' => 3.50, 'weight_exponent' => 1.0,
    ]);

    $outcome = app(LootCrateService::class)->open($player->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('cash');
    expect((float) $outcome['cash'])->toBe(3.50);

    $player->refresh();
    expect((float) $player->akzar_cash)->toBe(3.50);
});

it('opens a real crate for an item and grants it to the player', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);
    $crate = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 0, 'oil' => 0, 'cash' => 0, 'item' => 1,
    ]);

    $outcome = app(LootCrateService::class)->open($player->id, (int) $crate->id);

    expect($outcome['kind'])->toBeIn(['item', 'item_dupe']);

    if ($outcome['kind'] === 'item') {
        $owned = DB::table('player_items')
            ->where('player_id', $player->id)
            ->where('item_key', $outcome['item_key'])
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->exists();
        expect($owned)->toBeTrue();
    }
});

it('opens a real crate and treats an already-owned single-purchase item as a dupe', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);

    // Pre-grant the atlas so if it rolls, it's a dupe. Then pin
    // the item pool to atlas only by excluding everything else
    // via a huge exclude list computed at runtime... simpler: use
    // an exclude-all-but-atlas filter by seeding just two items.
    lootGiveItem($player, 'explorers_atlas', 1);

    $crate = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    // Force the item branch.
    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 0, 'oil' => 0, 'cash' => 0, 'item' => 1,
    ]);

    // Exclude every non-atlas item so the pool collapses to atlas
    // only — guaranteed dupe branch.
    $allKeys = DB::table('items_catalog')->pluck('key')->all();
    $exclude = array_values(array_filter($allKeys, fn ($k) => $k !== 'explorers_atlas'));
    app(GameConfigResolver::class)->set('loot.real_crate.item', [
        'weighting' => 'uniform',
        'exclude_keys' => $exclude,
        'cash_to_barrels_factor' => 100,
        'intel_to_barrels_factor' => 5,
    ]);

    $outcome = app(LootCrateService::class)->open($player->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('item_dupe');
    expect($outcome['item_key'])->toBe('explorers_atlas');
});

it('rejects opening a real crate the player is not on', function () {
    $player = lootMakePlayer();
    lootPutOnWasteland($player);
    /** @var Tile $other */
    $other = Tile::query()->where('type', 'wasteland')
        ->where('id', '!=', $player->fresh()->current_tile_id)->first();
    if ($other === null) {
        test()->markTestSkipped('Need two wasteland tiles.');
    }

    $crate = TileLootCrate::create([
        'tile_x' => $other->x,
        'tile_y' => $other->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    expect(fn () => app(LootCrateService::class)->open($player->id, (int) $crate->id))
        ->toThrow(CannotOpenLootCrateException::class);
});

it('rejects opening the same crate twice', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);
    $crate = TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);
    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 1, 'oil' => 0, 'cash' => 0, 'item' => 0,
    ]);

    app(LootCrateService::class)->open($player->id, (int) $crate->id);

    expect(fn () => app(LootCrateService::class)->open($player->id, (int) $crate->id))
        ->toThrow(CannotOpenLootCrateException::class);
});

/*
|--------------------------------------------------------------------------
| place: sabotage crate deployment
|--------------------------------------------------------------------------
*/

it('places a sabotage crate and decrements the inventory', function () {
    $player = lootMakePlayer();
    lootPutOnWasteland($player);
    lootGiveItem($player, 'crate_siphon_oil', 3);

    $result = app(LootCrateService::class)->place($player->id, 'crate_siphon_oil');

    expect($result['crate']->placed_by_player_id)->toBe($player->id);
    expect($result['crate']->device_key)->toBe('crate_siphon_oil');
    expect($result['remaining_quantity'])->toBe(2);
    expect(DB::table('player_items')
        ->where('player_id', $player->id)
        ->where('item_key', 'crate_siphon_oil')
        ->value('quantity'))->toBe(2);
});

it('deletes the player_items row when the last crate is deployed', function () {
    $player = lootMakePlayer();
    lootPutOnWasteland($player);
    lootGiveItem($player, 'crate_siphon_cash', 1);

    app(LootCrateService::class)->place($player->id, 'crate_siphon_cash');

    $remaining = DB::table('player_items')
        ->where('player_id', $player->id)
        ->where('item_key', 'crate_siphon_cash')
        ->first();

    expect($remaining)->toBeNull();
});

it('rejects placing on a non-wasteland tile', function () {
    $player = lootMakePlayer();
    /** @var Tile $base */
    $base = Tile::query()->where('type', 'base')->first();
    $player->update(['current_tile_id' => $base->id]);
    lootGiveItem($player, 'crate_siphon_oil', 1);

    expect(fn () => app(LootCrateService::class)->place($player->id, 'crate_siphon_oil'))
        ->toThrow(CannotOpenLootCrateException::class);
});

it('rejects placing without owning the item', function () {
    $player = lootMakePlayer();
    lootPutOnWasteland($player);

    expect(fn () => app(LootCrateService::class)->place($player->id, 'crate_siphon_oil'))
        ->toThrow(CannotOpenLootCrateException::class);
});

it('rejects placing when the tile already has an unopened crate', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);
    lootGiveItem($player, 'crate_siphon_oil', 2);

    TileLootCrate::create([
        'tile_x' => $tile->x,
        'tile_y' => $tile->y,
        'placed_by_player_id' => null,
        'device_key' => null,
        'placed_at' => now(),
    ]);

    expect(fn () => app(LootCrateService::class)->place($player->id, 'crate_siphon_oil'))
        ->toThrow(CannotOpenLootCrateException::class);
});

it('rejects placing when the deployment cap is reached', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);
    lootGiveItem($player, 'crate_siphon_oil', 20);

    // Force a base cap of 1 and a huge step so even the scaled
    // formula collapses to 1.
    app(GameConfigResolver::class)->set('loot.sabotage.max_deployed_base', 1);
    app(GameConfigResolver::class)->set('loot.sabotage.tiles_per_cap_step', 1_000_000);
    Cache::flush();

    // First placement succeeds.
    app(LootCrateService::class)->place($player->id, 'crate_siphon_oil');

    // Move to another wasteland so the second placement hits a
    // different tile (otherwise we'd trigger the per-tile guard).
    /** @var Tile|null $other */
    $other = Tile::query()->where('type', 'wasteland')
        ->where('id', '!=', $tile->id)->first();
    if ($other === null) {
        test()->markTestSkipped('Need two wasteland tiles for cap test.');
    }
    $player->update(['current_tile_id' => $other->id]);

    expect(fn () => app(LootCrateService::class)->place($player->id, 'crate_siphon_oil'))
        ->toThrow(CannotOpenLootCrateException::class);
});

/*
|--------------------------------------------------------------------------
| open: sabotage crate outcomes
|--------------------------------------------------------------------------
*/

it('siphons oil from a non-immune opener and credits the placer', function () {
    $placer = lootMakePlayer(oilBarrels: 0);
    $tile = lootPutOnWasteland($placer);
    lootGiveItem($placer, 'crate_siphon_oil', 1);
    app(LootCrateService::class)->place($placer->id, 'crate_siphon_oil');

    /** @var TileLootCrate $crate */
    $crate = TileLootCrate::query()->where('placed_by_player_id', $placer->id)->firstOrFail();

    $victim = lootMakePlayer(oilBarrels: 1000);
    $victim->update(['current_tile_id' => $tile->id]);

    // Force a 10% steal by pinning min=max=0.10 in the config.
    app(GameConfigResolver::class)->set('loot.sabotage.steal_pct', [
        'min' => 0.10, 'max' => 0.10,
    ]);

    $outcome = app(LootCrateService::class)->open($victim->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('sabotage_oil');
    expect((int) $outcome['amount'])->toBe(100);

    expect((int) $victim->fresh()->oil_barrels)->toBe(900);
    expect((int) $placer->fresh()->oil_barrels)->toBe(100);
});

it('siphons cash from a non-immune opener and credits the placer', function () {
    $placer = lootMakePlayer(cash: 0);
    $tile = lootPutOnWasteland($placer);
    lootGiveItem($placer, 'crate_siphon_cash', 1);
    app(LootCrateService::class)->place($placer->id, 'crate_siphon_cash');

    /** @var TileLootCrate $crate */
    $crate = TileLootCrate::query()->where('placed_by_player_id', $placer->id)->firstOrFail();

    $victim = lootMakePlayer(cash: 200.00);
    $victim->update(['current_tile_id' => $tile->id]);

    app(GameConfigResolver::class)->set('loot.sabotage.steal_pct', [
        'min' => 0.10, 'max' => 0.10,
    ]);

    $outcome = app(LootCrateService::class)->open($victim->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('sabotage_cash');
    expect((float) $outcome['amount'])->toBe(20.00);

    expect((float) $victim->fresh()->akzar_cash)->toBe(180.00);
    expect((float) $placer->fresh()->akzar_cash)->toBe(20.00);
});

it('fizzles on an immune victim without crediting the placer', function () {
    $placer = lootMakePlayer(oilBarrels: 500);
    $tile = lootPutOnWasteland($placer);
    lootGiveItem($placer, 'crate_siphon_oil', 1);
    app(LootCrateService::class)->place($placer->id, 'crate_siphon_oil');

    /** @var TileLootCrate $crate */
    $crate = TileLootCrate::query()->where('placed_by_player_id', $placer->id)->firstOrFail();

    $victim = lootMakePlayer(immunityHours: 48, oilBarrels: 1000);
    $victim->update(['current_tile_id' => $tile->id]);

    $outcome = app(LootCrateService::class)->open($victim->id, (int) $crate->id);

    expect($outcome['kind'])->toBe('immune_no_effect');

    // Nothing transferred either direction.
    expect((int) $victim->fresh()->oil_barrels)->toBe(1000);
    expect((int) $placer->fresh()->oil_barrels)->toBe(500);

    // Crate still consumed so the placer can't re-trap an immune
    // player.
    expect($crate->fresh()->opened_at)->not->toBeNull();
});

it('rejects the placer trying to open their own sabotage crate', function () {
    $placer = lootMakePlayer();
    lootPutOnWasteland($placer);
    lootGiveItem($placer, 'crate_siphon_oil', 1);
    app(LootCrateService::class)->place($placer->id, 'crate_siphon_oil');

    /** @var TileLootCrate $crate */
    $crate = TileLootCrate::query()->where('placed_by_player_id', $placer->id)->firstOrFail();

    expect(fn () => app(LootCrateService::class)->open($placer->id, (int) $crate->id))
        ->toThrow(CannotOpenLootCrateException::class);

    // Crate still unopened — rejection must not consume it.
    expect($crate->fresh()->opened_at)->toBeNull();
});

it('broadcasts LootCrateOpened for a real crate open', function () {
    $player = lootMakePlayer();
    $tile = lootPutOnWasteland($player);
    $crate = TileLootCrate::create([
        'tile_x' => $tile->x, 'tile_y' => $tile->y,
        'placed_by_player_id' => null, 'device_key' => null,
        'placed_at' => now(),
    ]);
    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 1, 'oil' => 0, 'cash' => 0, 'item' => 0,
    ]);

    app(LootCrateService::class)->open($player->id, (int) $crate->id);

    Event::assertDispatched(LootCrateOpened::class);
});

it('broadcasts SabotageLootCrateTriggered on a sabotage open', function () {
    $placer = lootMakePlayer();
    $tile = lootPutOnWasteland($placer);
    lootGiveItem($placer, 'crate_siphon_oil', 1);
    app(LootCrateService::class)->place($placer->id, 'crate_siphon_oil');
    /** @var TileLootCrate $crate */
    $crate = TileLootCrate::query()->where('placed_by_player_id', $placer->id)->firstOrFail();

    $victim = lootMakePlayer(oilBarrels: 1000);
    $victim->update(['current_tile_id' => $tile->id]);
    app(GameConfigResolver::class)->set('loot.sabotage.steal_pct', [
        'min' => 0.10, 'max' => 0.10,
    ]);

    app(LootCrateService::class)->open($victim->id, (int) $crate->id);

    Event::assertDispatched(SabotageLootCrateTriggered::class);
});

/*
|--------------------------------------------------------------------------
| HTTP endpoints — Web
|--------------------------------------------------------------------------
*/

it('POST /map/loot-crates/{crate}/open succeeds and flashes loot_result', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map');
    $player = $user->fresh()->player;
    $tile = lootPutOnWasteland($player);

    $crate = TileLootCrate::create([
        'tile_x' => $tile->x, 'tile_y' => $tile->y,
        'placed_by_player_id' => null, 'device_key' => null,
        'placed_at' => now(),
    ]);
    app(GameConfigResolver::class)->set('loot.real_crate.outcomes', [
        'nothing' => 1, 'oil' => 0, 'cash' => 0, 'item' => 0,
    ]);

    $response = $this->actingAs($user)
        ->post(route('map.loot_crates.open', ['crate' => $crate->id]));

    $response->assertRedirect(route('map.show'));
    $response->assertSessionHas('loot_result');
    expect($crate->fresh()->opened_at)->not->toBeNull();
});

it('POST /map/loot-crates/{crate}/decline leaves the crate alone', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map');
    $player = $user->fresh()->player;
    $tile = lootPutOnWasteland($player);

    $crate = TileLootCrate::create([
        'tile_x' => $tile->x, 'tile_y' => $tile->y,
        'placed_by_player_id' => null, 'device_key' => null,
        'placed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->post(route('map.loot_crates.decline', ['crate' => $crate->id]));

    $response->assertRedirect(route('map.show'));
    expect($crate->fresh()->opened_at)->toBeNull();
});

it('POST /map/loot-crates/deploy places a sabotage crate and flashes the receipt', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/map');
    $player = $user->fresh()->player;
    lootPutOnWasteland($player);
    lootGiveItem($player, 'crate_siphon_oil', 2);

    $response = $this->actingAs($user)
        ->post(route('map.loot_crates.deploy'), ['item_key' => 'crate_siphon_oil']);

    $response->assertRedirect(route('map.show'));
    $response->assertSessionHas('loot_deploy_result');
    expect(TileLootCrate::query()->where('placed_by_player_id', $player->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AttackLog / Hostility Log integration
|--------------------------------------------------------------------------
*/

it('includes sabotage loot crate hits in the Hostility Log feed', function () {
    $placer = lootMakePlayer();
    $tile = lootPutOnWasteland($placer);
    lootGiveItem($placer, 'crate_siphon_oil', 1);
    app(LootCrateService::class)->place($placer->id, 'crate_siphon_oil');
    /** @var TileLootCrate $crate */
    $crate = TileLootCrate::query()->where('placed_by_player_id', $placer->id)->firstOrFail();

    $victim = lootMakePlayer(oilBarrels: 1000);
    $victim->update(['current_tile_id' => $tile->id]);
    app(GameConfigResolver::class)->set('loot.sabotage.steal_pct', [
        'min' => 0.10, 'max' => 0.10,
    ]);
    app(LootCrateService::class)->open($victim->id, (int) $crate->id);

    $feed = app(AttackLogService::class)->recentAttacks($victim->fresh());
    $kinds = array_column($feed, 'kind');

    expect($kinds)->toContain('loot_crate_victim');

    // Placer side sees the same crate as a placer entry.
    $placerFeed = app(AttackLogService::class)->recentAttacks($placer->fresh());
    $placerKinds = array_column($placerFeed, 'kind');
    expect($placerKinds)->toContain('loot_crate_placer');
});

/*
|--------------------------------------------------------------------------
| deploymentCap math
|--------------------------------------------------------------------------
*/

it('scales deployment cap with world tile count', function () {
    $svc = app(LootCrateService::class);

    // Baseline: whatever the test world is, base 5 at step 2000.
    app(GameConfigResolver::class)->set('loot.sabotage.max_deployed_base', 5);
    app(GameConfigResolver::class)->set('loot.sabotage.tiles_per_cap_step', 2000);
    Cache::flush();
    $capBase = $svc->deploymentCap();
    expect($capBase)->toBeGreaterThanOrEqual(5);

    // Crank the step so it always collapses to the base.
    app(GameConfigResolver::class)->set('loot.sabotage.tiles_per_cap_step', 10_000_000);
    Cache::flush();
    expect($svc->deploymentCap())->toBe(5);

    // Shrink the step so it inflates well past the base.
    app(GameConfigResolver::class)->set('loot.sabotage.tiles_per_cap_step', 1);
    Cache::flush();
    expect($svc->deploymentCap())->toBeGreaterThan(5);
});
