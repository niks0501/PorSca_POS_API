<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_token' => 'test-token']);
    }

    public function test_authorized_user_can_create_a_product_and_inventory_together(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'House Blend Coffee',
            'barcode' => '4800000099999',
            'category' => 'Beverage',
            'price' => 18500,
            'stock' => 12,
            'reorder_level' => 3,
        ], $this->headers());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'House Blend Coffee')
            ->assertJsonPath('data.barcode', '4800000099999')
            ->assertJsonPath('data.category', 'Beverage')
            ->assertJsonPath('data.price', 18500)
            ->assertJsonPath('data.stock.quantity', 12)
            ->assertJsonPath('data.stock.reorder_level', 3)
            ->assertJsonPath('data.stock.status', 'in_stock');

        $product = Product::query()->where('barcode', '4800000099999')->firstOrFail();
        $this->assertNotSame('', $product->sku);
        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'quantity' => 12,
            'reorder_level' => 3,
        ]);
    }

    public function test_product_edit_updates_details_and_stock_in_one_authoritative_response(): void
    {
        $product = Product::factory()->create([
            'name' => 'Old Product',
            'barcode' => '4800000011111',
            'price' => 100,
        ]);
        $product->inventory()->create(['quantity' => 4, 'reorder_level' => 2]);

        $response = $this->patchJson('/api/v1/products/'.$product->id, [
            'name' => 'Updated Product',
            'barcode' => '4800000011112',
            'category' => 'Grocery',
            'price' => 225,
            'stock' => 18,
        ], $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Updated Product')
            ->assertJsonPath('data.barcode', '4800000011112')
            ->assertJsonPath('data.category', 'Grocery')
            ->assertJsonPath('data.price', 225)
            ->assertJsonPath('data.stock.quantity', 18)
            ->assertJsonPath('data.stock.reorder_level', 2);

        $this->assertSame('Updated Product', $product->fresh()->name);
        $this->assertSame(18, $product->inventory()->value('quantity'));
    }

    public function test_stock_endpoint_updates_inventory_without_detaching_the_product(): void
    {
        $product = Product::factory()->create(['barcode' => '4800000022222']);
        $product->inventory()->create(['quantity' => 1, 'reorder_level' => 2]);

        $this->patchJson('/api/v1/inventory/'.$product->id, [
            'quantity' => 0,
            'reorder_level' => 4,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.stock.quantity', 0)
            ->assertJsonPath('data.stock.reorder_level', 4)
            ->assertJsonPath('data.stock.status', 'out_of_stock');

        $this->assertSame(1, Inventory::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, $product->inventory()->value('quantity'));
    }

    public function test_duplicate_and_invalid_product_values_return_clear_validation_errors_without_writes(): void
    {
        $existing = Product::factory()->create([
            'barcode' => '4800000033333',
            'price' => 300,
        ]);
        $existing->inventory()->create(['quantity' => 7]);

        $this->postJson('/api/v1/products', [
            'name' => 'Invalid Product',
            'barcode' => '4800000033333',
            'category' => 'Grocery',
            'price' => -1,
            'stock' => -2,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => ['barcode', 'price', 'stock']]]);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(7, $existing->inventory()->value('quantity'));

        $this->postJson('/api/v1/products', [
            'name' => 'Malformed Barcode',
            'barcode' => 'not-a-barcode',
            'category' => 'Grocery',
            'price' => 100,
            'stock' => 1,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.details.barcode.0', 'The barcode field format is invalid.');
    }

    public function test_invalid_edit_and_stock_values_are_rejected(): void
    {
        $product = Product::factory()->create(['barcode' => '4800000044444', 'price' => 100]);
        $product->inventory()->create(['quantity' => 8]);

        $this->patchJson('/api/v1/products/'.$product->id, [
            'price' => 'not-a-price',
            'stock' => -1,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['price', 'stock']]]);

        $this->patchJson('/api/v1/products/'.$product->id.'/stock', [
            'quantity' => -5,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.details.stock.0', 'The stock field must be at least 0.');

        $this->assertSame(100, $product->fresh()->price);
        $this->assertSame(8, $product->inventory()->value('quantity'));
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
