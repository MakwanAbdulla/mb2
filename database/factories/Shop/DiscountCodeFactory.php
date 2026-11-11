<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountCode>
 */
class DiscountCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'discount_id'           => Discount::factory(),
            'code'                  => strtoupper($this->faker->bothify('??##??##')),
            'is_single_use'         => false,
            'batch_label'           => null,
            'redeemed_by_member_id' => null,
            'redeemed_at'           => null,
        ];
    }

    public function singleUse(): static
    {
        return $this->state(['is_single_use' => true]);
    }

    public function redeemed(): static
    {
        return $this->state(['redeemed_at' => now()]);
    }
}
