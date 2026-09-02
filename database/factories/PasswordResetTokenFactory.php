<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PasswordResetToken>
 */
class PasswordResetTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Same ADMIN-user convention as EmailVerificationTokenFactory.
            'email' => User::factory()->state(['role' => UserRole::ADMIN])->create()->email,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addMinutes(PasswordResetToken::EXPIRES_IN_MINUTES),
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
