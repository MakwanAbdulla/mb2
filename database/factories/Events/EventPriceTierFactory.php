<?php

namespace Database\Factories\Events;

use App\Models\Events\Event;
use App\Models\Events\EventPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Events\EventPriceTier>
 */
class EventPriceTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id'            => Event::factory(),
            'label'               => fake()->randomElement(['Standardbiljett', 'Halvpris', 'Icke-medlem', 'Medlem']),
            'price_ore'           => fake()->randomElement([0, 5000, 10000, 25000, 50000]),
            'requires_membership' => false,
            'max_count'           => null,
            'sort_order'          => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(['price_ore' => 0]);
    }

    public function withPrice(int $ore): static
    {
        return $this->state(['price_ore' => $ore]);
    }

    public function withCapacity(int $max): static
    {
        return $this->state(['max_count' => $max]);
    }

    public function requiresMembership(): static
    {
        return $this->state(['requires_membership' => true]);
    }
}
