<?php

namespace Database\Factories\Shop;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shop\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'option_value' => fake()->randomElement(['S', 'M', 'L', 'XL']),
            'stock'        => null,
            'sku'          => null,
            'sort_order'   => 0,
        ];
    }

    public function withStock(int $stock): static
    {
        return $this->state(['stock' => $stock]);
    }

    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }
}
