<?php

namespace Database\Factories\Membership;

use App\Models\Membership\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Membership\Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->streetAddress(),
            'postal_code' => fake()->optional()->postcode(),
            'city' => fake()->optional()->city(),
            'date_of_birth' => fake()->optional()->date(),
            'membership_type' => Member::TYPE_SINGLE,
            'status' => Member::STATUS_PENDING,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => Member::STATUS_ACTIVE]);
    }

    public function family(): static
    {
        return $this->state(['membership_type' => Member::TYPE_FAMILY]);
    }

    public function student(): static
    {
        return $this->state(['membership_type' => Member::TYPE_STUDENT]);
    }

    public function honorary(): static
    {
        return $this->state(['membership_type' => Member::TYPE_HONORARY]);
    }
}
