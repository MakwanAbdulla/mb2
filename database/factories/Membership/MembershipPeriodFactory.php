<?php

namespace Database\Factories\Membership;

use App\Models\Membership\Member;
use App\Models\Membership\MembershipPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Membership\MembershipPeriod>
 */
class MembershipPeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'year' => now()->year,
            'membership_type' => Member::TYPE_SINGLE,
            'status' => MembershipPeriod::STATUS_PENDING_PAYMENT,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status' => MembershipPeriod::STATUS_ACTIVE,
            'activated_at' => now()->startOfYear(),
            'expires_at' => now()->endOfYear(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => MembershipPeriod::STATUS_EXPIRED,
            'year' => now()->year - 1,
            'activated_at' => now()->subYear()->startOfYear(),
            'expires_at' => now()->subYear()->endOfYear(),
        ]);
    }
}
