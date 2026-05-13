<?php

use App\Domain\Combat\SpyService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Economy\ShopService;
use App\Domain\World\WorldService;
use App\Models\Item;
use App\Models\Player;
use App\Models\Post;
use App\Models\User;

beforeEach(function () {
    app(WorldService::class)->generateInitialWorld(seed: 42);
    $this->seed(\Database\Seeders\ItemsCatalogSeeder::class);

    config([
        'game.combat.spy.cooldown_hours' => 0,
        'game.combat.spy.detection_chance_base' => 0.0,
        'game.combat.spy.detection_per_security_diff' => 1.0,
        'game.combat.spy.detection_chance_min' => 0.0,
        'game.combat.spy.detection_chance_max' => 1.0,
        'game.notifications.broadcast_enabled' => false,
    ]);
    app(GameConfigResolver::class)->flush();
});

function securityDetectionItemKeys(): array
{
    return [
        'trip_wire',
        'camera_net',
        'counter_intel',
        'motion_sensor_array',
        'signal_disruptor',
        'counter_intel_array',
        'watchtower_relay',
        'thermal_tripnet',
        'signal_triangulator',
        'blindspot_scrubber',
        'blackbox_counterwatch',
    ];
}

function securityPriceByBoost(): array
{
    return Item::query()
        ->whereIn('key', securityDetectionItemKeys())
        ->get()
        ->mapWithKeys(fn (Item $item): array => [
            (int) ($item->effects['stat_add']['security'] ?? 0) => (int) $item->price_barrels,
        ])
        ->all();
}

function legacySecurityDetectionItemKeys(): array
{
    return [
        'trip_wire',
        'camera_net',
        'counter_intel',
        'motion_sensor_array',
        'signal_disruptor',
        'counter_intel_array',
    ];
}

function securityBoostTotal(array $keys): int
{
    return Item::query()
        ->whereIn('key', $keys)
        ->get()
        ->sum(fn (Item $item): int => (int) ($item->effects['stat_add']['security'] ?? 0));
}

function spawnSpyDetectionPair(int $spyStealth, int $targetSecurity): array
{
    $spyUser = User::factory()->create();
    $targetUser = User::factory()->create();
    $world = app(WorldService::class);

    $spy = $world->spawnPlayer($spyUser->id);
    $target = $world->spawnPlayer($targetUser->id);

    $spy->update([
        'moves_current' => 100,
        'stealth' => $spyStealth,
        'current_tile_id' => $target->base_tile_id,
        'immunity_expires_at' => null,
    ]);

    $target->update([
        'security' => $targetSecurity,
        'immunity_expires_at' => null,
    ]);

    return [$spy->fresh(), $target->fresh()];
}

function buyAllSecurityDetectionItems(Player $player): Player
{
    $fortPost = Post::query()->where('post_type', 'fort')->firstOrFail();
    $player->update([
        'current_tile_id' => $fortPost->tile_id,
        'oil_barrels' => 1000000,
    ]);

    $shop = app(ShopService::class);
    foreach (securityDetectionItemKeys() as $key) {
        $shop->purchase($player->id, $key);
    }

    return $player->fresh();
}

it('seeds enough security gear to reach the spy-detection cap', function () {
    $hardCap = (int) app(GameConfigResolver::class)->get('stats.hard_cap');

    expect(Item::query()->whereIn('key', securityDetectionItemKeys())->count())
        ->toBe(count(securityDetectionItemKeys()));

    expect(securityBoostTotal(legacySecurityDetectionItemKeys()))->toBeLessThan($hardCap);
    expect(securityBoostTotal(securityDetectionItemKeys()))->toBeGreaterThanOrEqual($hardCap);
});

it('prices security detection gear on the stealth ladder', function () {
    expect(securityPriceByBoost())->toMatchArray([
        1 => 5,
        2 => 15,
        3 => 35,
        4 => 70,
        5 => 140,
        6 => 250,
        7 => 420,
        8 => 700,
        10 => 1400,
        12 => 6500,
        15 => 65000,
    ]);
});

it('lets a defender catch up to an advanced stealth spy through store purchases', function () {
    [$underinvestedSpy] = spawnSpyDetectionPair(spyStealth: 45, targetSecurity: securityBoostTotal(legacySecurityDetectionItemKeys()));

    $underinvestedResult = app(SpyService::class)->spy($underinvestedSpy->id);
    expect($underinvestedResult['detected'])->toBeFalse();

    [$spy, $target] = spawnSpyDetectionPair(spyStealth: 45, targetSecurity: 0);
    $target = buyAllSecurityDetectionItems($target);
    $target->update(['current_tile_id' => $target->base_tile_id]);
    $spy->update(['current_tile_id' => $target->base_tile_id]);

    expect($target->fresh()->security)->toBe((int) app(GameConfigResolver::class)->get('stats.hard_cap'));

    $caughtUpResult = app(SpyService::class)->spy($spy->id);
    expect($caughtUpResult['detected'])->toBeTrue();
});
