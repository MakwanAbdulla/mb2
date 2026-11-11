<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'                => $this->faker->words(3, true),
            'type'                => Discount::TYPE_PERCENTAGE,
            'value'               => 10,
            'applies_to'          => Discount::APPLIES_ALL,
            'membership_type'     => null,
            'requires_membership' => null,
            'condition'           => null,
            'min_subtotal'        => null,
            'max_discount'        => null,
            'max_pct_of_total'    => null,
            'valid_from'          => null,
            'valid_until'         => null,
            'max_uses'            => null,
            'max_uses_per_user'   => null,
            'is_active'           => true,
        ];
    }

    public function percentage(int $pct = 10): static
    {
        return $this->state(['type' => Discount::TYPE_PERCENTAGE, 'value' => $pct]);
    }

    public function fixed(int $amountOre): static
    {
        return $this->state(['type' => Discount::TYPE_FIXED, 'value' => $amountOre]);
    }

    public function shopOnly(): static
    {
        return $this->state(['applies_to' => Discount::APPLIES_SHOP]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(['valid_until' => now()->subDay()]);
    }
}
