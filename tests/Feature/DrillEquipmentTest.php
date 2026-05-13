<?php

use App\Domain\Config\GameConfigResolver;
use App\Domain\Drilling\DrillService;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\World\WorldService;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Tile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);

    config([
        'game.drilling.break_chance_pct' => 0.0,
        'game.drilling.yields.dry' => [0, 0],
        'game.drilling.yields.trickle' => [10, 10],
        'game.drilling.yields.standard' => [100, 100],
        'game.drilling.yields.gusher' => [500, 500],
    ]);
    app(GameConfigResolver::class)->flush();
});

function drillEquipmentPlayerOnField(string $quality, int $tier = 1, ?string $drillItemKey = null): array
{
    $user = User::factory()->create();
    $player = app(WorldService::class)->spawnPlayer($user->id);

    $fieldTile = Tile::query()->where('type', 'oil_field')->firstOrFail();
    $player->update([
        'current_tile_id' => $fieldTile->id,
        'moves_current' => 200,
        'moves_updated_at' => now(),
        'drill_tier' => $tier,
        'immunity_expires_at' => null,
    ]);

    $field = OilField::query()->where('tile_id', $fieldTile->id)->firstOrFail();
    DrillPoint::query()
        ->where('oil_field_id', $field->id)
        ->update(['quality' => $quality, 'drilled_at' => null]);

    if ($drillItemKey !== null) {
        DB::table('player_items')->insert([
            'player_id' => $player->id,
            'item_key' => $drillItemKey,
            'quantity' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [$player->fresh(), $field];
}

function grantDrillEquipmentItem(Player $player, string $itemKey, int $quantity = 1): void
{
    DB::table('player_items')->updateOrInsert(
        ['player_id' => $player->id, 'item_key' => $itemKey],
        [
            'quantity' => $quantity,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
    app(GameConfigResolver::class)->flush();
}

it('industrial rig eliminates dry drill results', function () {
    [$player] = drillEquipmentPlayerOnField('dry', tier: 5, drillItemKey: 'industrial_rig');

    $result = app(DrillService::class)->drill($player->id, 0, 0);

    expect($result['quality'])->toBe('trickle');
    expect($result['barrels'])->toBe(22); // trickle 10 * tier-5 2.2
});

it('refinery guarantees the first field pull is at least standard and still eliminates later dry pulls', function () {
    [$player] = drillEquipmentPlayerOnField('dry', tier: 6, drillItemKey: 'refinery');

    $first = app(DrillService::class)->drill($player->id, 0, 0);
    $second = app(DrillService::class)->drill($player->id, 0, 1);

    expect($first['quality'])->toBe('standard');
    expect($first['barrels'])->toBe(260); // standard 100 * tier-6 2.6
    expect($second['quality'])->toBe('trickle');
    expect($second['barrels'])->toBe(26); // trickle 10 * tier-6 2.6
});

it('applies drill tier multipliers and passive yield bonuses', function () {
    [$player] = drillEquipmentPlayerOnField('standard', tier: 3, drillItemKey: 'medium_drill');
    grantDrillEquipmentItem($player, 'lucky_charm');

    $result = app(DrillService::class)->drill($player->id, 0, 0);

    expect($result['barrels'])->toBe(168); // standard 100 * tier-3 1.6 * lucky_charm 1.05
});

it('applies passive daily drill limit bonuses', function () {
    config(['game.drilling.daily_limit_per_field' => 1]);
    app(GameConfigResolver::class)->flush();

    [$player] = drillEquipmentPlayerOnField('standard');
    grantDrillEquipmentItem($player, 'field_journal');

    app(DrillService::class)->drill($player->id, 0, 0);
    app(DrillService::class)->drill($player->id, 0, 1);

    expect(fn () => app(DrillService::class)->drill($player->id, 0, 2))
        ->toThrow(CannotDrillException::class);
});
