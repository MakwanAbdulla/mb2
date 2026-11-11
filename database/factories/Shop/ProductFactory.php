<?php

namespace Database\Factories\Shop;

use App\Models\Shop\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shop\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name'                   => ucfirst($name),
            'slug'                   => Str::slug($name) . '-' . fake()->unique()->randomNumber(4),
            'description'            => fake()->paragraphs(2, true),
            'type'                   => Product::TYPE_PHYSICAL,
            'status'                 => Product::STATUS_DRAFT,
            'price_ore'              => fake()->numberBetween(5000, 50000), // 50–500 kr
            'requires_membership'    => false,
            'show_in_event_checkout' => false,
            'track_stock'            => false,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => Product::STATUS_PUBLISHED]);
    }

    public function archived(): static
    {
        return $this->state(['status' => Product::STATUS_ARCHIVED]);
    }

    public function digital(): static
    {
        return $this->state(['type' => Product::TYPE_DIGITAL]);
    }

    public function membersOnly(): static
    {
        return $this->state(['requires_membership' => true]);
    }

    public function withStock(int $stock = 10): static
    {
        return $this->state(['track_stock' => true])
            ->afterCreating(function (Product $product) use ($stock) {
                $product->defaultVariant()?->update(['stock' => $stock]);
            });
    }

    public function showInEventCheckout(): static
    {
        return $this->state(['show_in_event_checkout' => true]);
    }

    public function withVariants(string $dimensionLabel, array $options): static
    {
        return $this->afterCreating(function (Product $product) use ($dimensionLabel, $options) {
            // Remove the auto-created default variant
            $product->variants()->where('option_value', '')->delete();

            $product->dimension()->create(['label' => $dimensionLabel]);

            foreach (array_values($options) as $index => $value) {
                $product->variants()->create([
                    'option_value' => $value,
                    'sort_order'   => $index,
                ]);
            }
        });
    }
}
