<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookSecret = bin2hex(random_bytes(16));
        config([
            'app.api_token' => 'test-token',
            'services.paymongo.mode' => 'sandbox',
            'services.paymongo.secret_key' => null,
            'services.paymongo.webhook_secret' => $this->webhookSecret,
        ]);
    }

    public function test_health_is_public_and_reports_the_api_version(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('data.service', 'porsca-api')
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_protected_resources_require_a_bearer_token(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->getJson('/api/v1/products', ['Authorization' => 'Bearer test-token'])->assertOk();
    }

    public function test_checkout_is_idempotent_and_paid_settlement_is_atomic(): void
    {
        $product = Product::factory()->create(['price' => 1250]);
        $product->inventory()->create(['quantity' => 5, 'reorder_level' => 1]);
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 2]]];
        $headers = ['Authorization' => 'Bearer test-token', 'Idempotency-Key' => 'checkout-001'];

        $first = $this->postJson('/api/v1/sales/checkout', $payload, $headers);
        $first->assertCreated()
            ->assertJsonPath('data.status', Payment::PENDING)
            ->assertJsonPath('data.amount', 2500)
            ->assertJsonPath('data.items.0.quantity', 2);

        $paymentId = $first->json('data.id');
        $this->postJson('/api/v1/sales/checkout', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $paymentId);

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Transaction::where('type', 'payment_created')->count());

        $this->postJson('/api/v1/sales/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ], $headers)->assertStatus(409);

        $payment = Payment::findOrFail($paymentId);
        $settlement = app(PaymentSettlementService::class);
        $settlement->settle($payment, Payment::PAID, 'provider-event-001');
        $settlement->settle($payment, Payment::PAID, 'provider-event-001');

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Transaction::where('type', 'payment_succeeded')->count());
        $this->assertSame(3, $product->inventory()->value('quantity'));
    }

    public function test_failed_payment_does_not_create_a_sale_or_deduct_inventory(): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 4, 'reorder_level' => 1]);
        $payment = app(CheckoutService::class)->create('failed-001', [
            ['product_id' => $product->id, 'quantity' => 2],
        ]);

        app(PaymentSettlementService::class)->settle($payment, Payment::CANCELLED, 'provider-event-cancelled');

        $this->assertSame(Payment::CANCELLED, $payment->fresh()->status);
        $this->assertSame(0, Sale::count());
        $this->assertSame(4, $product->inventory()->value('quantity'));
    }

    public function test_webhook_signature_and_duplicate_delivery_are_handled_once(): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 4]);
        $payment = app(CheckoutService::class)->create('webhook-001', [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
        $payload = [
            'event_id' => 'evt-webhook-001',
            'type' => 'payment.paid',
            'payment_id' => $payment->provider_payment_id,
            'status' => 'paid',
        ];
        $signature = hash_hmac('sha256', json_encode($payload), $this->webhookSecret);

        $this->postJson('/api/v1/webhooks/paymongo', $payload, ['X-PayMongo-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.payment.status', Payment::PAID);
        $this->postJson('/api/v1/webhooks/paymongo', $payload, ['X-PayMongo-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, Sale::count());
        $this->assertSame(3, $product->inventory()->value('quantity'));
    }

    public function test_invalid_webhook_signature_and_invalid_checkout_are_rejected(): void
    {
        $this->postJson('/api/v1/webhooks/paymongo', [], ['X-PayMongo-Signature' => 'wrong'])
            ->assertUnauthorized();
        $this->postJson('/api/v1/sales/checkout', ['items' => []], [
            'Authorization' => 'Bearer test-token',
            'Idempotency-Key' => 'invalid-001',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }
}
