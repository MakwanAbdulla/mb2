<?php

namespace Database\Factories\Events;

use App\Models\Events\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Events\Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'title'                            => $title,
            'slug'                             => Str::slug($title) . '-' . fake()->unique()->randomNumber(4),
            'description'                      => fake()->paragraphs(2, true),
            'status'                           => Event::STATUS_DRAFT,
            'visibility'                       => Event::VISIBILITY_PUBLIC,
            'location_type'                    => Event::LOCATION_EXTERNAL,
            'location_name'                    => fake()->streetAddress(),
            'registration_mode'                => Event::REGISTRATION_STANDARD,
            'waitlist_mode'                    => Event::WAITLIST_NONE,
            'requires_membership'              => false,
            'allow_ticket_transfer'            => false,
            'notify_organizer_on_registration' => true,
            'notify_waitlist_on_opening'       => true,
            'external_revenue_type'            => Event::REVENUE_NONE,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => Event::STATUS_PUBLISHED]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => Event::STATUS_CANCELLED]);
    }

    public function completed(): static
    {
        return $this->state(['status' => Event::STATUS_COMPLETED]);
    }

    public function membersOnly(): static
    {
        return $this->state([
            'visibility'          => Event::VISIBILITY_MEMBERS_ONLY,
            'requires_membership' => true,
        ]);
    }

    public function digital(): static
    {
        return $this->state([
            'location_type' => Event::LOCATION_DIGITAL,
            'location_name' => null,
            'location_url'  => 'https://discord.gg/example',
        ]);
    }

    public function withExternalOrganizer(): static
    {
        return $this->state([
            'organizer_external_name' => fake()->company(),
            'external_organizer_name' => fake()->company(),
            'external_fee_ore'        => 50000,
        ]);
    }

    public function withRegistrationWindow(): static
    {
        return $this->state([
            'registration_opens_at'  => now()->subDay(),
            'registration_closes_at' => now()->addDays(14),
        ]);
    }

    public function admissionOnPayment(): static
    {
        return $this->state(['admission_type' => \App\Models\Events\Event::ADMISSION_ON_PAYMENT]);
    }

    public function admissionAutomatic(): static
    {
        return $this->state(['admission_type' => \App\Models\Events\Event::ADMISSION_AUTOMATIC]);
    }

    public function admissionManual(): static
    {
        return $this->state(['admission_type' => \App\Models\Events\Event::ADMISSION_MANUAL]);
    }

    public function withWaitlist(): static
    {
        return $this->state(['waitlist_mode' => \App\Models\Events\Event::WAITLIST_MANUAL]);
    }
}
