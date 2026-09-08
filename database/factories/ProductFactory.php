<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-####'),
            'barcode' => fake()->unique()->ean13(),
            'name' => fake()->words(2, true),
            'category' => fake()->randomElement(['Grocery', 'Beverage', 'Household']),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(100, 10000),
            'currency' => 'PHP',
            'active' => true,
        ];
    }
}
