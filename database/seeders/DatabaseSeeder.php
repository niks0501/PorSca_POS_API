<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public const BASELINE_VERSION = 'qa-baseline-2026-02';

    public function run(): void
    {
        User::factory()->create([
            'name' => 'QA User',
            'email' => 'qa@example.test',
        ]);

        $products = [
            [
                'sku' => 'RICE-001',
                'barcode' => '4800000000010',
                'name' => 'Sinandomeng Rice 5kg',
                'price' => 32000,
                'quantity' => 20,
            ],
            [
                'sku' => 'COFFEE-001',
                'barcode' => '4800000000027',
                'name' => 'Barako Coffee 250g',
                'price' => 18500,
                'quantity' => 3,
            ],
            [
                'sku' => 'SOAP-001',
                'barcode' => '4800000000034',
                'name' => 'Laundry Soap 500g',
                'price' => 7500,
                'quantity' => 0,
            ],
            [
                'sku' => 'WATER-001',
                'barcode' => '4800000000041',
                'name' => 'Mineral Water 1L',
                'price' => 3500,
                'quantity' => 100,
            ],
        ];

        foreach ($products as $definition) {
            $quantity = $definition['quantity'];
            unset($definition['quantity']);
            $product = Product::create($definition + [
                'description' => null,
                'currency' => 'PHP',
                'active' => true,
            ]);
            Inventory::create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'reorder_level' => 5,
            ]);
        }
    }
}
