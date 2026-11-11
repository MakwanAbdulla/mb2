<?php

namespace Database\Factories\Signing;

use App\Enums\SignMethod;
use App\Models\Signing\SigningSigner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningSigner>
 */
class SigningSignerFactory extends Factory
{
    /** Default: an external one-off person (no user). */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->name(),
            'company' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => '07'.fake()->numerify('########'),
            'sign_method' => SignMethod::BankId,
            'provider_party_id' => null,
        ];
    }

    /** A user — member or not. */
    public function forUser(?User $user = null): static
    {
        return $this->state(function () use ($user) {
            $user ??= User::factory()->create();

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        });
    }

    public function registered(): static
    {
        return $this->state(fn () => ['provider_party_id' => (string) fake()->unique()->numberBetween(1, 999999)]);
    }

    public function email(): static
    {
        return $this->state(['sign_method' => SignMethod::Email]);
    }
}
