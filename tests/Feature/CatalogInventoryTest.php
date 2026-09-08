<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_token' => 'test-token']);
    }

    public function test_name_search_returns_catalog_details_and_low_stock_state(): void
    {
        $product = Product::factory()->create([
            'barcode' => '1234567890123',
            'name' => 'Barako Coffee 250g',
            'price' => 18500,
        ]);
        $product->inventory()->create(['quantity' => 3, 'reorder_level' => 5]);
        Product::factory()->create(['name' => 'Sinandomeng Rice 5kg']);

        $response = $this->getJson('/api/v1/products?search=coffee', $this->headers());

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.barcode', '1234567890123')
            ->assertJsonPath('data.items.0.name', 'Barako Coffee 250g')
            ->assertJsonPath('data.items.0.price', 18500)
            ->assertJsonPath('data.items.0.stock.quantity', 3)
            ->assertJsonPath('data.items.0.stock.reorder_level', 5)
            ->assertJsonPath('data.items.0.stock.status', 'low_stock')
            ->assertJsonPath('data.items.0.stock.low_stock', true)
            ->assertJsonPath('data.items.0.stock.out_of_stock', false);
    }

    public function test_barcode_lookup_returns_an_active_product_and_unknown_barcode_is_explicit(): void
    {
        $product = Product::factory()->create([
            'barcode' => '9876543210128',
            'name' => 'Mineral Water 1L',
        ]);
        $product->inventory()->create(['quantity' => 10, 'reorder_level' => 5]);

        $this->getJson('/api/v1/products/barcode/9876543210128', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.barcode', '9876543210128')
            ->assertJsonPath('data.stock.status', 'in_stock');

        $this->getJson('/api/v1/products/barcode/does-not-exist', $this->headers())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', 'Product not found for this barcode.');

        $this->getJson('/api/v1/products/999999', $this->headers())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_inventory_exposes_consistent_in_low_and_out_of_stock_states(): void
    {
        $inStock = Product::factory()->create(['name' => 'In stock product']);
        $inStock->inventory()->create(['quantity' => 10, 'reorder_level' => 5]);
        $lowStock = Product::factory()->create(['name' => 'Low stock product']);
        $lowStock->inventory()->create(['quantity' => 2, 'reorder_level' => 5]);
        $outOfStock = Product::factory()->create(['name' => 'Out of stock product']);
        $outOfStock->inventory()->create(['quantity' => 0, 'reorder_level' => 5]);

        $response = $this->getJson('/api/v1/inventory', $this->headers());
        $items = collect($response->json('data.items'))->keyBy('product_id');

        $response->assertOk()->assertJsonPath('data.pagination.total', 3);
        $this->assertSame('in_stock', $items[$inStock->id]['status']);
        $this->assertFalse($items[$inStock->id]['low_stock']);
        $this->assertFalse($items[$inStock->id]['out_of_stock']);
        $this->assertSame('low_stock', $items[$lowStock->id]['status']);
        $this->assertTrue($items[$lowStock->id]['low_stock']);
        $this->assertFalse($items[$lowStock->id]['out_of_stock']);
        $this->assertSame('out_of_stock', $items[$outOfStock->id]['status']);
        $this->assertTrue($items[$outOfStock->id]['low_stock']);
        $this->assertTrue($items[$outOfStock->id]['out_of_stock']);

        $this->getJson('/api/v1/inventory?low_stock=true', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.status', 'low_stock')
            ->assertJsonPath('data.items.1.status', 'out_of_stock');

        $this->getJson('/api/v1/inventory/999999', $this->headers())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_seeded_catalog_has_predictable_barcodes_and_stock_scenarios(): void
    {
        $this->seed();

        $this->getJson('/api/v1/products/barcode/4800000000010', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.sku', 'RICE-001')
            ->assertJsonPath('data.stock.status', 'in_stock');
        $this->getJson('/api/v1/products/barcode/4800000000027', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.stock.status', 'low_stock');
        $this->getJson('/api/v1/products/barcode/4800000000034', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.stock.status', 'out_of_stock');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer test-token',
            'Accept' => 'application/json',
        ];
    }
}
