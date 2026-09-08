<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.api_token' => 'test-token']);
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
            'Authorization' => 'Bearer test-token',
            'Accept' => 'application/json',
        ];
    }
}
