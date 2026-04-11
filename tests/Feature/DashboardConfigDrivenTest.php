<?php

use App\Models\User;

it('dashboard shows immunity hours from config', function () {
    config(['game.new_player.immunity_hours' => 24]);
    app()->forgetInstance(\App\Domain\Config\GameConfigResolver::class);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('immunityHours', 24)
    );
});

it('dashboard shows daily regen from config and derives bank cap', function () {
    config([
        'game.moves.daily_regen' => 200,
        'game.moves.bank_cap_multiplier' => 1.75,
    ]);
    app()->forgetInstance(\App\Domain\Config\GameConfigResolver::class);

    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->where('dailyRegen', 200)
        ->where('bankCap', 350)
    );
});
