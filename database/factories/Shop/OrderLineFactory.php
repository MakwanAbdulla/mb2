<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shop\OrderLine>
 */
class OrderLineFactory extends Factory
{
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(5000, 30000);

        return [
            'order_id'       => Order::factory(),
            'category'       => OrderLine::CATEGORY_MEMBERSHIP,
            'description'    => 'Enkelmedlemskap ' . now()->year,
            'quantity'       => 1,
            'unit_price_ore' => $unitPrice,
            'discount_ore'   => 0,
            'line_total_ore' => $unitPrice,
        ];
    }
}
