<?php

namespace Database\Factories\Membership;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Membership\FamilyGroup>
 */
class FamilyGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'administrator_member_id' => null,
        ];
    }
}
