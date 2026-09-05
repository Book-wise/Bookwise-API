<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailVerificationToken>
 */
class EmailVerificationTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Registration always creates a technical ADMIN user (BR1).
            'user_id' => User::factory()->state(['role' => UserRole::ADMIN]),
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addHours(48),
            'used_at' => null,
        ];
    }

    /**
     * Indicate that the token has already expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    /**
     * Indicate that the token has already been consumed.
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now(),
        ]);
    }
}
