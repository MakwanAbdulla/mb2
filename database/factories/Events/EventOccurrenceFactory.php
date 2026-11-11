<?php

namespace Database\Factories\Events;

use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Events\EventOccurrence>
 */
class EventOccurrenceFactory extends Factory
{
    public function definition(): array
    {
        $starts = fake()->dateTimeBetween('+1 week', '+3 months');
        $ends   = (clone $starts)->modify('+2 hours');

        return [
            'event_id'  => Event::factory(),
            'starts_at' => $starts,
            'ends_at'   => $ends,
            'is_dropin' => false,
        ];
    }

    public function dropin(): static
    {
        return $this->state(['is_dropin' => true]);
    }

    public function withCapacity(int $max): static
    {
        return $this->state(['max_participants' => $max]);
    }

    public function cancelled(): static
    {
        return $this->state(['cancelled_at' => now()]);
    }

    public function past(): static
    {
        return $this->state(function () {
            $starts = fake()->dateTimeBetween('-3 months', '-1 week');
            $ends   = (clone $starts)->modify('+2 hours');
            return ['starts_at' => $starts, 'ends_at' => $ends];
        });
    }
}
