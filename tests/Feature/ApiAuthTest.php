<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Feature tests for the /api/v1/auth/* endpoints
|--------------------------------------------------------------------------
*/

it('registers a new user and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'data' => [
            'user' => ['id', 'name', 'email'],
            'token',
        ],
    ]);

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Another',
        'email' => 'taken@example.com',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
});

it('logs in with valid credentials and returns a token', function () {
    User::factory()->create([
        'email' => 'alice@example.com',
        'password' => Hash::make('correcthorse'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'alice@example.com',
        'password' => 'correcthorse',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.user.email', 'alice@example.com');
    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
});

it('rejects login with wrong password', function () {
    User::factory()->create([
        'email' => 'bob@example.com',
        'password' => Hash::make('correct'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'bob@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email']);
});

it('returns 401 for /auth/me without a token', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('returns the authenticated user for /auth/me with a valid token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');

    $response->assertOk();
    $response->assertJsonPath('data.id', $user->id);
    $response->assertJsonPath('data.email', $user->email);
});

it('revokes the current token on /auth/logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    // Token should no longer work.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
