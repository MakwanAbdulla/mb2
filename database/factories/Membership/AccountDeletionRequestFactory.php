<?php

namespace Database\Factories\Membership;

use App\Models\Membership\AccountDeletionRequest;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Membership\AccountDeletionRequest>
 */
class AccountDeletionRequestFactory extends Factory
{
    public function definition(): array
    {
        $user = User::factory()->create();
        $member = Member::factory()->create(['user_id' => $user->id]);

        return [
            'member_id' => $member->id,
            'user_id'   => $user->id,
            'reason'    => fake()->optional()->sentence(),
            'status'    => AccountDeletionRequest::STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => AccountDeletionRequest::STATUS_APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status'     => AccountDeletionRequest::STATUS_REJECTED,
            'admin_note' => fake()->sentence(),
        ]);
    }
}
