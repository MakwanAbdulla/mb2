<?php

namespace Database\Factories\Organization;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization\OrganizationalRole>
 */
class OrganizationalRoleFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word() . ' ' . fake()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name) . '-' . fake()->unique()->randomNumber(4),
            'description' => fake()->optional()->sentence(),
            'cross_period_access' => false,
        ];
    }

    public function withCrossPeriodAccess(): static
    {
        return $this->state(['cross_period_access' => true]);
    }
}
