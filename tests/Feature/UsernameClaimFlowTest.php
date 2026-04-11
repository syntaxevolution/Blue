<?php

use App\Models\User;

it('registers a new user with a compliant alphanumeric username', function () {
    $response = $this->post('/register', [
        'name' => 'TestHero1',
        'email' => 'new@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);

    expect(User::where('email', 'new@example.com')->first())
        ->not->toBeNull()
        ->and(User::where('email', 'new@example.com')->first()->name)
        ->toBe('TestHero1')
        ->and(User::where('email', 'new@example.com')->first()->name_claimed_at)
        ->not->toBeNull();
});

it('rejects registration with an invalid username format', function () {
    $response = $this->post('/register', [
        'name' => 'ab', // too short
        'email' => 'new@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);

    $response->assertSessionHasErrors(['name']);
    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
});

it('rejects registration with a case-insensitive duplicate username', function () {
    User::factory()->create(['name' => 'TakenName']);

    $response = $this->post('/register', [
        'name' => 'takenname',
        'email' => 'new@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);

    $response->assertSessionHasErrors(['name']);
});

it('allows a user with no claimed name to claim one', function () {
    $user = User::factory()->unclaimed()->create(['name' => 'OldUgly123']);

    $this->actingAs($user)->post('/username/claim', ['name' => 'FreshName']);

    $user = $user->fresh();
    expect($user->name)->toBe('FreshName');
    expect($user->name_claimed_at)->not->toBeNull();
});

it('blocks re-claiming once a username has been set', function () {
    $user = User::factory()->create(['name' => 'Claimed1']);

    $response = $this->actingAs($user)->post('/username/claim', ['name' => 'Replaced1']);

    $response->assertForbidden();
    expect($user->fresh()->name)->toBe('Claimed1');
});
