<?php

namespace Database\Factories\Signing;

use App\Models\Signing\SigningRequest;
use App\Models\Signing\SigningRequestFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SigningRequestFile>
 */
class SigningRequestFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'signing_request_id' => SigningRequest::factory(),
            'name' => 'dokument.pdf',
            'disk' => 'local',
            'path' => 'signing/'.fake()->uuid().'.pdf',
            'sort_order' => 0,
        ];
    }
}
