<?php

namespace Database\Factories\Signing;

use App\Enums\SigningStatus;
use App\Models\Signing\SigningRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningRequest>
 */
class SigningRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Avtal '.fake()->words(2, true),
            'comment' => null,
            'status' => SigningStatus::Draft,
            'provider' => SigningRequest::PROVIDER_DOCSIGN,
            'provider_document_id' => null,
            'send_reminders' => true,
            'send_receipt' => true,
            'is_demo' => true,
            'created_by' => User::factory(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => SigningStatus::Pending,
            'provider_document_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'sent_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->sent()->state(fn () => [
            'status' => SigningStatus::Completed,
            'completed_at' => now(),
            'signed_disk' => 'local',
            'signed_path' => 'signing/'.fake()->uuid().'.pdf',
            'signed_sha256' => hash('sha256', fake()->uuid()),
        ]);
    }
}
