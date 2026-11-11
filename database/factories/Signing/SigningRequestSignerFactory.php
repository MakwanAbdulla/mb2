<?php

namespace Database\Factories\Signing;

use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestSigner;
use App\Models\Signing\SigningSigner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningRequestSigner>
 */
class SigningRequestSignerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'signing_request_id' => SigningRequest::factory(),
            'signing_signer_id' => SigningSigner::factory()->registered(),
            'role_label' => null,
            'sort_order' => 0,
            'signed_at' => null,
            'signed_by_name' => null,
            'sign_url' => 'https://docsign.se/sign/'.fake()->sha1(),
        ];
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'signed_at' => now(),
            'signed_by_name' => fake()->name(),
        ]);
    }
}
