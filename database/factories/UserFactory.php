<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Names comply with the alphanumeric 5-15 char rule introduced in
     * Batch 1 and are pre-claimed so the RequireClaimedUsername middleware
     * doesn't bounce factory users from game routes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate a unique alphanumeric 5–15 char name. Seeded with
        // faker randomness + a short entropy tail so collisions are
        // vanishingly rare within a single test run.
        $name = $this->generateUsername();

        return [
            'name' => $name,
            'name_claimed_at' => now(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user has not yet claimed a username.
     */
    public function unclaimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'name_claimed_at' => null,
        ]);
    }

    private function generateUsername(): string
    {
        // Ensure the generated name is alphanumeric and 5–15 chars
        // (the UniqueUsername rule enforces these bounds).
        $base = preg_replace('/[^A-Za-z0-9]/', '', fake()->userName().fake()->randomNumber(4));
        if (strlen($base) < 5) {
            $base = 'User'.Str::random(4);
            $base = preg_replace('/[^A-Za-z0-9]/', '', $base);
        }

        return substr($base, 0, 15);
    }
}
