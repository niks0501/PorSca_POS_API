<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('test')->plainTextToken;
    }

    /** @return array<string, array{string}> */
    public static function unsignedProductFields(): array
    {
        return [
            'price' => ['price'],
            'stock' => ['stock'],
            'reorder_level' => ['reorder_level'],
        ];
    }

    #[DataProvider('unsignedProductFields')]
    public function test_product_create_rejects_values_above_the_unsigned_integer_max(string $field): void
    {
        $payload = [
            'name' => 'Bounded Product',
            'barcode' => '4800000001234',
            'price' => 100,
            'stock' => 5,
            'reorder_level' => 2,
        ];

        $this->postJson('/api/v1/products', [...$payload, $field => 4294967296], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['details' => [$field]]]);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventories', 0);
    }

    #[DataProvider('unsignedProductFields')]
    public function test_product_edit_rejects_values_above_the_unsigned_integer_max(string $field): void
    {
        $product = Product::factory()->create(['barcode' => '4800000001234', 'price' => 100]);
        $product->inventory()->create(['quantity' => 5, 'reorder_level' => 2]);

        $this->patchJson('/api/v1/products/'.$product->id, [$field => 4294967296], $this->headers())
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [$field]]]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'price' => 100]);
        $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 5, 'reorder_level' => 2]);
    }

    /** @return array<string, array{string, string}> */
    public static function unsignedStockFields(): array
    {
        return [
            'stock' => ['stock', 'stock'],
            'quantity alias' => ['quantity', 'stock'],
            'reorder level' => ['reorder_level', 'reorder_level'],
        ];
    }

    #[DataProvider('unsignedStockFields')]
    public function test_stock_endpoint_rejects_values_above_the_unsigned_integer_max(string $field, string $errorField): void
    {
        $product = Product::factory()->create();
        $product->inventory()->create(['quantity' => 5, 'reorder_level' => 2]);

        $payload = $field === 'quantity' ? ['quantity' => 4294967296] : ['stock' => 5, $field => 4294967296];

        $this->patchJson('/api/v1/inventory/'.$product->id, $payload, $this->headers())
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [$errorField]]]);

        $this->assertDatabaseHas('inventories', ['product_id' => $product->id, 'quantity' => 5, 'reorder_level' => 2]);
    }

    public function test_unsigned_integer_maximum_is_accepted_for_product_and_stock_writes(): void
    {
        $maximum = 4294967295;
        $created = $this->postJson('/api/v1/products', [
            'name' => 'Maximum Product',
            'barcode' => '4800000001234',
            'price' => $maximum,
            'stock' => $maximum,
            'reorder_level' => $maximum,
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.price', $maximum)
            ->assertJsonPath('data.stock.quantity', $maximum)
            ->assertJsonPath('data.stock.reorder_level', $maximum);

        $productId = $created->json('data.id');
        $this->patchJson('/api/v1/products/'.$productId.'/stock', [
            'stock' => $maximum,
            'reorder_level' => $maximum,
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.stock.quantity', $maximum)
            ->assertJsonPath('data.stock.reorder_level', $maximum);
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

    public function test_custom_category_and_leading_zero_barcode_round_trip(): void
    {
        $this->postJson('/api/v1/products', [
            'name' => 'Frozen Dumplings',
            'barcode' => '0012345678905',
            'category' => 'Frozen Food',
            'price' => 1299,
            'stock' => 3,
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.barcode', '0012345678905')
            ->assertJsonPath('data.category', 'Frozen Food');

        $this->getJson('/api/v1/products/barcode/0012345678905', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Frozen Dumplings')
            ->assertJsonPath('data.price', 1299)
            ->assertJsonPath('data.stock.quantity', 3);
    }

    public function test_partial_product_edit_preserves_stock_and_barcode(): void
    {
        $product = Product::factory()->create(['barcode' => '0012345678905', 'price' => 100]);
        $product->inventory()->create(['quantity' => 7]);

        $this->patchJson('/api/v1/products/'.$product->id, [
            'name' => 'Updated Product', 'price' => 199, 'category' => 'Baked Goods',
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.name', 'Updated Product')
            ->assertJsonPath('data.category', 'Baked Goods')
            ->assertJsonPath('data.price', 199)
            ->assertJsonPath('data.barcode', '0012345678905')
            ->assertJsonPath('data.stock.quantity', 7);
    }

    public function test_duplicate_barcode_edit_rejects_all_changes(): void
    {
        Product::factory()->create(['barcode' => '4800000055555']);
        $product = Product::factory()->create(['barcode' => '4800000066666', 'name' => 'Original', 'price' => 100]);
        $product->inventory()->create(['quantity' => 7]);

        $this->patchJson('/api/v1/products/'.$product->id, [
            'barcode' => '4800000055555', 'name' => 'Changed', 'price' => 999, 'stock' => 99,
        ], $this->headers())->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['barcode']]]);

        $this->assertSame('Original', $product->fresh()->name);
        $this->assertSame('4800000066666', $product->fresh()->barcode);
        $this->assertSame(100, $product->fresh()->price);
        $this->assertSame(7, $product->inventory()->value('quantity'));
    }

    public function test_invalid_category_rejects_other_product_changes(): void
    {
        $product = Product::factory()->create(['name' => 'Original', 'price' => 100]);
        $product->inventory()->create(['quantity' => 7]);

        $this->patchJson('/api/v1/products/'.$product->id, [
            'category' => str_repeat('x', 101), 'name' => 'Changed', 'stock' => 99,
        ], $this->headers())->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['category']]]);

        $this->assertSame('Original', $product->fresh()->name);
        $this->assertSame(7, $product->inventory()->value('quantity'));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }
}
