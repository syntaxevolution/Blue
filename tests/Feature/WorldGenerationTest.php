<?php

use App\Domain\World\WorldService;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Post;
use App\Models\Tile;

/*
|--------------------------------------------------------------------------
| Feature tests for WorldService::generateInitialWorld
|--------------------------------------------------------------------------
|
| These hit a real database (via the RefreshDatabase trait configured in
| tests/Pest.php) and only run on the server. The pure planning logic
| is covered in tests/Unit/WorldServiceTest.php and runs everywhere.
|
*/

it('persists the full planned world into the database', function () {
    $stats = app(WorldService::class)->generateInitialWorld(seed: 42);

    expect($stats['tiles'])->toBeGreaterThan(1900)->toBeLessThan(2050);
    expect($stats['oil_fields'])->toBeGreaterThan(150);
    expect($stats['drill_points'])->toBe($stats['oil_fields'] * 25);
    expect($stats['posts'])->toBeGreaterThan(20);

    expect(Tile::count())->toBe($stats['tiles']);
    expect(OilField::count())->toBe($stats['oil_fields']);
    expect(DrillPoint::count())->toBe($stats['drill_points']);
    expect(Post::count())->toBe($stats['posts']);
});

it('places the_landing landmark at origin', function () {
    app(WorldService::class)->generateInitialWorld(seed: 7);

    $origin = Tile::where(['x' => 0, 'y' => 0])->first();

    expect($origin)->not->toBeNull();
    expect($origin->type)->toBe('landmark');
    expect($origin->subtype)->toBe('the_landing');
});

it('every oil field has exactly 25 drill points', function () {
    app(WorldService::class)->generateInitialWorld(seed: 13);

    OilField::with('drillPoints')->get()->each(function (OilField $field) {
        expect($field->drillPoints)->toHaveCount(25);
    });
});

it('drill points cover every (grid_x, grid_y) 0..4 per field', function () {
    app(WorldService::class)->generateInitialWorld(seed: 13);

    $field = OilField::with('drillPoints')->first();
    $coords = $field->drillPoints
        ->map(fn (DrillPoint $p) => "{$p->grid_x}:{$p->grid_y}")
        ->sort()
        ->values()
        ->all();

    $expected = [];
    for ($y = 0; $y < 5; $y++) {
        for ($x = 0; $x < 5; $x++) {
            $expected[] = "{$x}:{$y}";
        }
    }
    sort($expected);

    expect($coords)->toEqual($expected);
});

it('every post row carries a valid enum post_type', function () {
    app(WorldService::class)->generateInitialWorld(seed: 99);

    $types = Post::query()->pluck('post_type')->unique()->values()->all();

    foreach ($types as $type) {
        expect($type)->toBeIn(['strength', 'stealth', 'fort', 'tech', 'general']);
    }
});

it('every post has a non-empty flavor name', function () {
    app(WorldService::class)->generateInitialWorld(seed: 99);

    Post::each(function (Post $post) {
        expect($post->name)->not->toBeEmpty();
    });
});

it('is deterministic: same seed reproduces the same tile distribution', function () {
    $svc = app(WorldService::class);

    $svc->generateInitialWorld(seed: 2026);
    $firstTypes = Tile::orderBy('x')->orderBy('y')->pluck('type', 'id')->values()->all();

    // Wipe and regenerate with the same seed.
    Tile::query()->delete();
    $svc->generateInitialWorld(seed: 2026);
    $secondTypes = Tile::orderBy('x')->orderBy('y')->pluck('type', 'id')->values()->all();

    expect($secondTypes)->toEqual($firstTypes);
});
