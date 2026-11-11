<?php

namespace Database\Factories\Events;

use App\Models\Events\Event;
use App\Models\Events\EventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Events\EventRegistration>
 */
class EventRegistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id'        => Event::factory(),
            'user_id'         => User::factory(),
            'status'          => EventRegistration::STATUS_CONFIRMED,
            'check_in_token'  => strtolower(Str::random(32)),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => EventRegistration::STATUS_CONFIRMED]);
    }

    public function pendingPayment(): static
    {
        return $this->state(['status' => EventRegistration::STATUS_PENDING_PAYMENT]);
    }

    public function pendingApproval(): static
    {
        return $this->state(['status' => EventRegistration::STATUS_PENDING_APPROVAL]);
    }

    public function waitlisted(): static
    {
        return $this->state(['status' => EventRegistration::STATUS_WAITLISTED]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status'       => EventRegistration::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function guest(): static
    {
        return $this->state([
            'user_id'     => null,
            'guest_name'  => fake()->name(),
            'guest_email' => fake()->safeEmail(),
        ]);
    }
}
