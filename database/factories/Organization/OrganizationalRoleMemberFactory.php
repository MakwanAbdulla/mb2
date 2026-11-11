<?php

namespace Database\Factories\Organization;

use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRoleMember;
use App\Models\Organization\OrganizationalRolePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization\OrganizationalRoleMember>
 */
class OrganizationalRoleMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organizational_role_period_id' => OrganizationalRolePeriod::factory(),
            'member_id' => Member::factory(),
            'title' => $this->faker->jobTitle(),
            'transition_role' => OrganizationalRoleMember::TRANSITION_CURRENT,
        ];
    }

    public function incoming(): static
    {
        return $this->state(['transition_role' => OrganizationalRoleMember::TRANSITION_INCOMING]);
    }

    public function outgoing(): static
    {
        return $this->state(['transition_role' => OrganizationalRoleMember::TRANSITION_OUTGOING]);
    }
}
