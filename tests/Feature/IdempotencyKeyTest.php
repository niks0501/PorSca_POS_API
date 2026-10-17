<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->headers = ['Authorization' => 'Bearer '.User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('keys')->plainTextToken];
        config(['services.paymongo.mode' => 'sandbox', 'services.paymongo.secret_key' => '']);
    }

    public static function tenders(): array
    {
        return [['cash', 'cash'], ['qrph', 'qrph'], ['cash', 'qrph'], ['qrph', 'cash']];
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 4, 'reorder_level' => 0]);

        return $product;
    }

    #[DataProvider('tenders')]
    public function test_legacy_keys_distinguish_case_and_replay_only_the_original_tender(string $first, string $second): void
    {
        $product = $this->product();
        $payload = ['cash_received' => 500, 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $original = $this->postJson('/api/v1/sales/checkout', [
            ...$payload, 'payment_method' => $first, 'idempotency_key' => 'order:A',
        ], $this->headers)->assertCreated()->json('data');
        DB::table('checkout_keys')->delete();
        $this->postJson('/api/v1/sales/checkout', [
            ...$payload, 'payment_method' => $second, 'idempotency_key' => 'order:a',
        ], $this->headers)->assertCreated();
        $this->postJson('/api/v1/sales/checkout', [
            ...$payload, 'payment_method' => $first === 'cash' ? 'qrph' : 'cash', 'idempotency_key' => 'order:A',
        ], $this->headers)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

        $product->update(['active' => false]);
        $this->postJson('/api/v1/sales/checkout', [
            ...$payload, 'payment_method' => $first, 'idempotency_key' => 'order:A',
        ], $this->headers)->assertOk()->assertJsonPath('data.id', $original['id']);
        $cashCount = (int) ($first === 'cash') + (int) ($second === 'cash');
        $this->assertDatabaseCount('sales', $cashCount);
        $this->assertDatabaseCount('payments', 2 - $cashCount);
        $this->assertSame(4 - $cashCount, $product->inventory()->value('quantity'));
    }

    public function test_checkout_creation_and_cash_operation_keys_distinguish_case(): void
    {
        $product = $this->product();
        $headers = [...$this->headers, 'X-PorSca-Contract-Version' => 'porsca-mobile-api-v3'];
        $cart = ['items' => [['productId' => $product->id, 'quantity' => 1]]];
        $first = $this->postJson('/api/v1/checkouts', $cart, [...$headers, 'Idempotency-Key' => 'purchase:A'])->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/checkouts', $cart, [...$headers, 'Idempotency-Key' => 'purchase:a'])->assertCreated()->json('data');
        $this->assertNotSame($first['id'], $second['id']);
        foreach ([$first, $second] as $checkout) {
            $payload = ['revision' => 1, 'acceptedAmountCentavos' => 500, 'cashReceivedCentavos' => 500];
            $path = '/api/v1/checkouts/'.$checkout['id'].'/cash';
            $receipt = $this->postJson($path, $payload, [...$headers, 'Idempotency-Key' => 'cash:A'])->assertCreated()->json('data.sale.id');
            $this->postJson($path, $payload, [...$headers, 'Idempotency-Key' => 'cash:a'])->assertStatus(409);
            $this->postJson($path, $payload, [...$headers, 'Idempotency-Key' => 'cash:A'])->assertOk()->assertJsonPath('data.sale.id', $receipt);
        }
        $this->postJson('/api/v1/checkouts', $cart, [...$headers, 'Idempotency-Key' => 'purchase:A'])->assertOk()->assertJsonPath('data.id', $first['id']);
        $this->assertDatabaseCount('checkouts', 2);
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('checkout_operations', 2);
        $this->assertSame(2, $product->inventory()->value('quantity'));
    }
}
