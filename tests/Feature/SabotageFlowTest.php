<?php

use App\Domain\Drilling\DrillService;
use App\Domain\Sabotage\SabotageService;
use App\Domain\World\WorldService;
use App\Models\DrillPoint;
use App\Models\DrillPointSabotage;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 1337);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);
    Event::fake(); // Broadcast events, keep tests synchronous.
});

/**
 * Spawn a player, walk them onto a known oil field tile, and wire the
 * drill_points row qualities so the yield path is deterministic for
 * assertions that care about barrels.
 */
function sabotagePlayerOnField(?int $immunityHours = 0): array
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    // Give them plenty of moves and barrels to work with.
    $player->update([
        'oil_barrels' => 10000,
        'moves_current' => 200,
        'immunity_expires_at' => $immunityHours > 0 ? now()->addHours($immunityHours) : null,
    ]);

    // Find any oil field tile and relocate the player there.
    $fieldTile = Tile::query()->where('type', 'oil_field')->first();
    $player->update(['current_tile_id' => $fieldTile->id]);

    /** @var OilField $field */
    $field = OilField::query()->where('tile_id', $fieldTile->id)->firstOrFail();

    // Normalise all 25 drill points to 'standard' so yields are stable.
    DrillPoint::query()
        ->where('oil_field_id', $field->id)
        ->update(['quality' => 'standard', 'drilled_at' => null]);

    return [$player->fresh(), $field];
}

/** Insert a player_items row so the toolbox path has inventory. */
function giveToolboxItem(Player $player, string $key, int $qty = 1): void
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

/** Put the player on a tier-2+ drill so it's breakable. */
function equipDrillTier(Player $player, string $key, int $tier): void
{
    giveToolboxItem($player, $key, 1);
    $player->forceFill(['drill_tier' => $tier])->save();
}

it('places a device and decrements the inventory row', function () {
    [$player, $field] = sabotagePlayerOnField();
    giveToolboxItem($player, 'gremlin_coil', 3);

    $result = app(SabotageService::class)->place($player->id, 2, 2, 'gremlin_coil');

    expect($result['device_key'])->toBe('gremlin_coil');
    expect($result['remaining_quantity'])->toBe(2);

    $row = DB::table('player_items')
        ->where('player_id', $player->id)
        ->where('item_key', 'gremlin_coil')
        ->first();
    expect((int) $row->quantity)->toBe(2);

    $trap = DrillPointSabotage::query()->where('oil_field_id', $field->id)->first();
    expect($trap)->not->toBeNull();
    expect($trap->device_key)->toBe('gremlin_coil');
    expect($trap->placed_by_player_id)->toBe($player->id);
    expect($trap->triggered_at)->toBeNull();
});

it('blocks placing a second device on the same drill point', function () {
    [$player, $field] = sabotagePlayerOnField();
    giveToolboxItem($player, 'gremlin_coil', 2);

    app(SabotageService::class)->place($player->id, 1, 1, 'gremlin_coil');

    $throwable = null;
    try {
        app(SabotageService::class)->place($player->id, 1, 1, 'gremlin_coil');
    } catch (\Throwable $e) {
        $throwable = $e;
    }

    expect($throwable)->not->toBeNull();
    expect($throwable->getMessage())->toContain('already has an active device');
});

it('deletes the inventory row when quantity would hit zero', function () {
    [$player, $field] = sabotagePlayerOnField();
    giveToolboxItem($player, 'gremlin_coil', 1);

    app(SabotageService::class)->place($player->id, 0, 0, 'gremlin_coil');

    $row = DB::table('player_items')
        ->where('player_id', $player->id)
        ->where('item_key', 'gremlin_coil')
        ->first();
    expect($row)->toBeNull();
});

it('breaks a tier-2 drill when a gremlin coil triggers on a non-planter', function () {
    // Planter has a trap. Victim has a tier-2 drill.
    [$planter, $field] = sabotagePlayerOnField();
    giveToolboxItem($planter, 'gremlin_coil', 1);
    app(SabotageService::class)->place($planter->id, 3, 3, 'gremlin_coil');

    [$victim, $victimField] = sabotagePlayerOnField();
    // Re-point victim at the same field the planter seeded.
    $victim->update(['current_tile_id' => $field->tile_id]);
    equipDrillTier($victim->fresh(), 'shovel_rig', 2);
    $victim = $victim->fresh();

    $result = app(DrillService::class)->drill($victim->id, 3, 3);

    expect($result['sabotage_outcome'])->toBe('drill_broken');
    expect($result['drill_broke'])->toBeTrue();
    expect($result['broken_item_key'])->toBe('shovel_rig');
    expect($result['barrels'])->toBe(0);

    $victim->refresh();
    expect($victim->broken_item_key)->toBe('shovel_rig');

    $trap = DrillPointSabotage::query()->where('drill_point_id', DrillPoint::where([
        'oil_field_id' => $field->id, 'grid_x' => 3, 'grid_y' => 3,
    ])->value('id'))->first();
    expect($trap->outcome)->toBe('drill_broken');
    expect($trap->triggered_by_player_id)->toBe($victim->id);
});

it('siphons half the victim oil on a siphon charge and credits the planter', function () {
    [$planter, $field] = sabotagePlayerOnField();
    giveToolboxItem($planter, 'siphon_charge', 1);
    $plantResult = app(SabotageService::class)->place($planter->id, 4, 0, 'siphon_charge');

    [$victim, $_] = sabotagePlayerOnField();
    $victim->update([
        'current_tile_id' => $field->tile_id,
        'oil_barrels' => 2000,
    ]);
    equipDrillTier($victim->fresh(), 'shovel_rig', 2);
    $victim = $victim->fresh();

    $planterBefore = Player::find($planter->id)->oil_barrels;

    $result = app(DrillService::class)->drill($victim->id, 4, 0);

    expect($result['sabotage_outcome'])->toBe('drill_broken_and_siphoned');
    expect($result['siphoned_barrels'])->toBe(1000);
    expect($result['drill_broke'])->toBeTrue();

    $victim->refresh();
    expect((int) $victim->oil_barrels)->toBe(1000);

    $planterAfter = Player::find($planter->id)->oil_barrels;
    expect((int) $planterAfter)->toBe((int) $planterBefore + 1000);
});

it('consumes a tripwire ward and leaves the rig intact when detected', function () {
    [$planter, $field] = sabotagePlayerOnField();
    giveToolboxItem($planter, 'gremlin_coil', 1);
    app(SabotageService::class)->place($planter->id, 2, 3, 'gremlin_coil');

    [$victim, $_] = sabotagePlayerOnField();
    $victim->update(['current_tile_id' => $field->tile_id]);
    equipDrillTier($victim->fresh(), 'shovel_rig', 2);
    giveToolboxItem($victim->fresh(), 'tripwire_ward', 2);
    $victim = $victim->fresh();

    $result = app(DrillService::class)->drill($victim->id, 2, 3);

    expect($result['sabotage_outcome'])->toBe('detected');
    expect($result['drill_broke'])->toBeFalse();
    expect($result['barrels'])->toBe(0);

    $victim->refresh();
    expect($victim->broken_item_key)->toBeNull();

    $wardQty = (int) (DB::table('player_items')
        ->where('player_id', $victim->id)
        ->where('item_key', 'tripwire_ward')
        ->value('quantity') ?? 0);
    expect($wardQty)->toBe(1);
});

it('fizzles on an immune player and does not steal oil', function () {
    [$planter, $field] = sabotagePlayerOnField();
    giveToolboxItem($planter, 'siphon_charge', 1);
    app(SabotageService::class)->place($planter->id, 0, 4, 'siphon_charge');

    $planterBefore = Player::find($planter->id)->oil_barrels;

    [$victim, $_] = sabotagePlayerOnField(immunityHours: 48);
    $victim->update([
        'current_tile_id' => $field->tile_id,
        'oil_barrels' => 2000,
    ]);
    equipDrillTier($victim->fresh(), 'shovel_rig', 2);
    $victim = $victim->fresh();

    $result = app(DrillService::class)->drill($victim->id, 0, 4);

    expect($result['sabotage_outcome'])->toBe('fizzled_immune');
    expect($result['drill_broke'])->toBeFalse();
    expect($result['siphoned_barrels'])->toBe(0);

    $victim->refresh();
    expect((int) $victim->oil_barrels)->toBe(2000);

    $planterAfter = Player::find($planter->id)->oil_barrels;
    expect((int) $planterAfter)->toBe((int) $planterBefore);

    $victim->refresh();
    expect($victim->broken_item_key)->toBeNull();
});

it('never breaks a tier-1 starter drill but still siphons oil', function () {
    [$planter, $field] = sabotagePlayerOnField();
    giveToolboxItem($planter, 'siphon_charge', 1);
    app(SabotageService::class)->place($planter->id, 1, 4, 'siphon_charge');

    [$victim, $_] = sabotagePlayerOnField();
    $victim->update([
        'current_tile_id' => $field->tile_id,
        'oil_barrels' => 800,
        'drill_tier' => 1, // starter — no player_items drill row
    ]);
    $victim = $victim->fresh();

    $result = app(DrillService::class)->drill($victim->id, 1, 4);

    expect($result['sabotage_outcome'])->toBe('siphoned_tier_one');
    expect($result['drill_broke'])->toBeFalse();
    expect($result['siphoned_barrels'])->toBe(400);

    $victim->refresh();
    expect($victim->broken_item_key)->toBeNull();
    expect((int) $victim->oil_barrels)->toBe(400);
});

it('ignores the planter hitting their own trap and returns a normal drill', function () {
    [$planter, $field] = sabotagePlayerOnField();
    giveToolboxItem($planter, 'gremlin_coil', 1);
    app(SabotageService::class)->place($planter->id, 3, 0, 'gremlin_coil');

    equipDrillTier($planter->fresh(), 'shovel_rig', 2);
    $planter = $planter->fresh();

    $result = app(DrillService::class)->drill($planter->id, 3, 0);

    expect($result['sabotage_outcome'])->toBe('normal');
    expect($result['drill_broke'])->toBeFalse();
    expect($result['barrels'])->toBeGreaterThan(0);
});
