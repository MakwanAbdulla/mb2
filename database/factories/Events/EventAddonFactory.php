<?php

namespace Database\Factories\Events;

use App\Models\Events\Event;
use App\Models\Events\EventAddon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventAddon>
 */
class EventAddonFactory extends Factory
{
    protected $model = EventAddon::class;

    public function definition(): array
    {
        return [
            'event_id'   => Event::factory(),
            'name'       => $this->faker->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'price_ore'  => 0,
            'options'    => null,
            'max_count'  => null,
            'sort_order' => 0,
        ];
    }

    public function withPrice(int $ore): static
    {
        return $this->state(['price_ore' => $ore]);
    }

    public function withOptions(array $options): static
    {
        return $this->state(['options' => $options]);
    }

    public function withCapacity(int $max): static
    {
        return $this->state(['max_count' => $max]);
    }
}
