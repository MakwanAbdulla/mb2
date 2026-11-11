<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shop\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'status'         => Order::STATUS_PENDING,
            'total_ore'      => fake()->numberBetween(10000, 50000),
            'payment_method' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status'         => Order::STATUS_PAID,
            'payment_method' => Order::METHOD_BANKGIRO,
            'paid_at'        => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => Order::STATUS_CANCELLED]);
    }
}
