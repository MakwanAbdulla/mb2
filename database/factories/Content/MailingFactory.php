<?php

namespace Database\Factories\Content;

use App\Models\Content\Mailing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailing>
 */
class MailingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title'               => fake()->words(4, true),
            'subject'             => fake()->sentence(),
            'body_html'           => '<p>Hej {{fornamn}}, ' . fake()->paragraph() . '</p>',
            'status'              => Mailing::STATUS_DRAFT,
            'created_by'          => User::factory(),
            'mailing_template_id' => null,
            'scheduled_at'        => null,
            'sent_at'             => null,
            'recipients_count'    => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => Mailing::STATUS_DRAFT]);
    }

    public function sending(): static
    {
        return $this->state(['status' => Mailing::STATUS_SENDING]);
    }

    public function scheduled(?string $at = null): static
    {
        return $this->state([
            'status'       => Mailing::STATUS_SCHEDULED,
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }

    public function sent(): static
    {
        return $this->state([
            'status'  => Mailing::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }
}
