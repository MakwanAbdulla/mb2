<?php

namespace Database\Factories\Signing;

use App\Enums\SigningEventType;
use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningRequestEvent>
 */
class SigningRequestEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'signing_request_id' => SigningRequest::factory(),
            'signing_request_signer_id' => null,
            'type' => SigningEventType::Created,
            'payload' => null,
        ];
    }
}
