<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashSaleTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('test')->plainTextToken;
    }

    public function test_cash_received_above_the_unsigned_integer_max_is_rejected_on_every_cash_alias(): void
    {
        $product = $this->product(price: 100, quantity: 5);

        foreach (['/api/v1/sales', '/api/v1/sales/cash', '/api/v1/sales/checkout'] as $path) {
            $this->postJson($path, [
                'idempotency_key' => 'cash-overflow-'.md5($path),
                'payment_method' => 'cash',
                'cash_received' => 4294967296,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ], $this->headers())
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_error')
                ->assertJsonStructure(['error' => ['details' => ['cash_received']]]);
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(5, $product->inventory()->value('quantity'));
    }

    public function test_cash_service_rejects_cash_above_the_unsigned_integer_max_before_writes(): void
    {
        $product = $this->product(price: 0, quantity: 5);

        try {
            app(CashSaleService::class)->create(
                'cash-service-overflow',
                [['product_id' => $product->id, 'quantity' => 1]],
                4294967296,
            );
            $this->fail('Cash above the unsigned integer maximum was accepted.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('cash_received', $exception->errors);
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(5, $product->inventory()->value('quantity'));
    }

    /** @return array<string, array{array<int>, int}> */
    public static function overflowingCarts(): array
    {
        return [
            'line multiplication' => [[2147483648], 2],
            'cart addition' => [[2147483648, 2147483648], 1],
        ];
    }

    #[DataProvider('overflowingCarts')]
    public function test_computed_total_above_the_unsigned_integer_max_is_rejected_before_writes(array $prices, int $quantity): void
    {
        $items = [];
        foreach ($prices as $price) {
            $product = $this->product(price: $price, quantity: 5);
            $items[] = ['product_id' => $product->id, 'quantity' => $quantity];
        }

        $this->postJson('/api/v1/sales/cash', [
            'idempotency_key' => 'cash-total-overflow-'.md5(json_encode([$prices, $quantity])),
            'cash_received' => 4294967295,
            'items' => $items,
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['total_amount']]]);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Transaction::count());
        foreach ($items as $item) {
            $this->assertDatabaseHas('inventories', ['product_id' => $item['product_id'], 'quantity' => 5]);
        }
    }

    public function test_cash_amounts_at_the_unsigned_integer_maximum_are_accepted(): void
    {
        $maximum = 4294967295;
        $tendered = $this->product(price: 1, quantity: 1);

        $this->postJson('/api/v1/sales/cash', [
            'idempotency_key' => 'cash-maximum-tender',
            'cash_received' => $maximum,
            'items' => [['product_id' => $tendered->id, 'quantity' => 1]],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.total_amount', 1)
            ->assertJsonPath('data.cash_received', $maximum)
            ->assertJsonPath('data.change_amount', $maximum - 1);

        $pricedAtMaximum = $this->product(price: $maximum, quantity: 1);
        $this->postJson('/api/v1/sales/cash', [
            'idempotency_key' => 'cash-maximum-total',
            'cash_received' => $maximum,
            'items' => [['product_id' => $pricedAtMaximum->id, 'quantity' => 1]],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.total_amount', $maximum)
            ->assertJsonPath('data.change_amount', 0);
    }

    public function test_cash_sale_recomputes_totals_persists_snapshots_and_deducts_stock_atomically(): void
    {
        $product = $this->product(price: 1250, quantity: 5);

        $response = $this->postJson('/api/v1/sales/checkout', [
            'idempotency_key' => 'cash-success-001',
            'payment_method' => 'cash',
            'cash_received' => 3000,
            'total' => 1,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 1,
            ]],
        ], $this->headers());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.total_amount', 2500)
            ->assertJsonPath('data.cash_received', 3000)
            ->assertJsonPath('data.change_amount', 500)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', 1250);

        $saleId = $response->json('data.id');
        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'idempotency_key' => 'cash-success-001',
            'total_amount' => 2500,
            'payment_method' => 'cash',
            'cash_received' => 3000,
            'change_amount' => 500,
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 1250,
        ]);
        $this->assertDatabaseHas('transactions', [
            'sale_id' => $saleId,
            'payment_id' => null,
            'type' => 'cash_sale',
            'status' => 'completed',
            'amount' => 2500,
        ]);
        $this->assertSame(3, $product->inventory()->value('quantity'));
    }

    public function test_insufficient_stock_rolls_back_the_entire_cash_sale(): void
    {
        $product = $this->product(price: 500, quantity: 2);

        $this->postJson('/api/v1/sales/checkout', [
            'idempotency_key' => 'cash-stock-001',
            'payment_method' => 'cash',
            'cash_received' => 2000,
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'insufficient_stock');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(2, $product->inventory()->value('quantity'));
    }

    public function test_insufficient_cash_blocks_completion_without_writes(): void
    {
        $product = $this->product(price: 1250, quantity: 5);

        $this->postJson('/api/v1/sales/checkout', [
            'idempotency_key' => 'cash-amount-001',
            'payment_method' => 'cash',
            'cash_received' => 2499,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_cash');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(5, $product->inventory()->value('quantity'));
    }

    public function test_retried_cash_sale_returns_the_original_and_deducts_once(): void
    {
        $product = $this->product(price: 750, quantity: 5);
        $payload = [
            'idempotency_key' => 'cash-retry-001',
            'payment_method' => 'cash',
            'cash_received' => 2000,
            'total' => 999999,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];

        $first = $this->postJson('/api/v1/sales/checkout', $payload, $this->headers())
            ->assertCreated();
        $saleId = $first->json('data.id');

        $this->postJson('/api/v1/sales/checkout', $payload, $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $saleId)
            ->assertJsonPath('data.total_amount', 1500)
            ->assertJsonPath('data.change_amount', 500);

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Sale::firstOrFail()->items()->count());
        $this->assertSame(1, Transaction::where('type', 'cash_sale')->count());
        $this->assertSame(3, $product->inventory()->value('quantity'));

        $this->postJson('/api/v1/sales/checkout', [
            ...$payload,
            'cash_received' => 3000,
        ], $this->headers())->assertStatus(409);

        $this->assertSame(1, Sale::count());
        $this->assertSame(3, $product->inventory()->value('quantity'));
    }

    public function test_a_non_cash_sale_in_checkout_namespace_is_not_a_cash_retry(): void
    {
        $product = $this->product(price: 100, quantity: 2);
        Sale::create([
            'idempotency_key' => 'wrong-sale-kind',
            'total_amount' => 100,
            'payment_method' => 'qrph',
            'request_hash' => null,
        ]);

        $this->postJson('/api/v1/sales/cash', [
            'idempotency_key' => 'wrong-sale-kind',
            'cash_received' => 100,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $this->headers())->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, Sale::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(2, $product->inventory()->value('quantity'));
    }

    public function test_completed_cash_sales_are_available_in_history(): void
    {
        $product = $this->product(price: 100, quantity: 2);

        $created = $this->postJson('/api/v1/sales', [
            'idempotencyKey' => 'cash-history-001',
            'paymentMethod' => 'cash',
            'cashReceived' => 100,
            'items' => [['productId' => $product->id, 'quantity' => 1]],
        ], $this->headers())->assertCreated();

        $this->getJson('/api/v1/sales', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $created->json('data.id'))
            ->assertJsonPath('data.items.0.payment_method', 'cash')
            ->assertJsonPath('data.items.0.total_amount', 100);

        $this->getJson('/api/v1/sales/'.$created->json('data.id'), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 100);
    }

    private function product(int $price, int $quantity)
    {
        $product = Product::factory()->create(['price' => $price]);
        $product->inventory()->create(['quantity' => $quantity, 'reorder_level' => 1]);

        return $product;
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
