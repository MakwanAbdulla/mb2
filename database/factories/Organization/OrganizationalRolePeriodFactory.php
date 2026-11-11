<?php

namespace Database\Factories\Organization;

use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRolePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization\OrganizationalRolePeriod>
 */
class OrganizationalRolePeriodFactory extends Factory
{
    public function definition(): array
    {
        $year = now()->year;

        return [
            'organizational_role_id' => OrganizationalRole::factory(),
            'name' => (string) $year,
            'period_type' => OrganizationalRolePeriod::TYPE_CALENDAR_YEAR,
            'year' => $year,
            'active_start' => "{$year}-01-01",
            'active_end' => "{$year}-12-31",
            'status' => OrganizationalRolePeriod::STATUS_ACTIVE,
        ];
    }

    public function forming(): static
    {
        $year = now()->year + 1;

        return $this->state([
            'year' => $year,
            'name' => (string) $year,
            'forming_start' => now()->toDateString(),
            'active_start' => "{$year}-01-01",
            'active_end' => "{$year}-12-31",
            'status' => OrganizationalRolePeriod::STATUS_FORMING,
        ]);
    }

    public function closed(): static
    {
        $year = now()->year - 1;

        return $this->state([
            'year' => $year,
            'name' => (string) $year,
            'active_start' => "{$year}-01-01",
            'active_end' => "{$year}-12-31",
            'status' => OrganizationalRolePeriod::STATUS_CLOSED,
        ]);
    }

    public function custom(): static
    {
        return $this->state([
            'period_type' => OrganizationalRolePeriod::TYPE_CUSTOM,
            'year' => null,
            'custom_start' => now()->toDateString(),
            'custom_end' => now()->addYear()->toDateString(),
        ]);
    }
}
