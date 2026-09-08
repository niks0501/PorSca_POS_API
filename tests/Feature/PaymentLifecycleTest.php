<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookSecret = 'webhook-secret-for-tests';
        config([
            'app.api_token' => 'test-token',
            'services.paymongo.mode' => 'sandbox',
            'services.paymongo.secret_key' => null,
            'services.paymongo.webhook_secret' => $this->webhookSecret,
        ]);
    }

    public function test_configured_sandbox_qr_flow_stays_server_side(): void
    {
        $secret = 'sk_test_server_only';
        $webhookSecret = 'whsec_server_only';
        config([
            'services.paymongo.secret_key' => $secret,
            'services.paymongo.webhook_secret' => $webhookSecret,
            'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents',
        ]);
        Http::fake([
            'https://api.paymongo.test/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_server_001',
                    'attributes' => ['client_key' => 'client_key_server_only'],
                ],
            ], 201),
            'https://api.paymongo.test/v1/payment_methods' => Http::response([
                'data' => ['id' => 'pm_server_001', 'attributes' => ['type' => 'qrph']],
            ], 201),
            'https://api.paymongo.test/v1/payment_intents/pi_server_001/attach' => Http::response([
                'data' => [
                    'attributes' => [
                        'status' => 'awaiting_next_action',
                        'next_action' => ['code' => ['image_url' => 'data:image/png;base64,practice-qr']],
                        'payment' => 'pay_server_001',
                    ],
                ],
            ]),
        ]);

        $product = $this->product();
        $response = $this->postJson('/api/v1/payments', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $this->authHeaders('server-side-001'));

        $response->assertCreated()
            ->assertJsonPath('data.status', Payment::PENDING)
            ->assertJsonPath('data.provider_payment_id', 'pi_server_001')
            ->assertJsonPath('data.qr_payload', 'data:image/png;base64,practice-qr');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($webhookSecret, $response->getContent());
        $this->assertSame(3, count(Http::recorded()));
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'payment_method_allowed')
            && str_contains($request->body(), 'qrph'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paymongo.test/v1/payment_methods'
            && str_contains($request->body(), '"type":"qrph"'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/attach')
            && str_contains($request->body(), 'pm_server_001'));
    }

    public function test_failed_cancelled_and_expired_webhooks_never_complete_a_sale(): void
    {
        foreach ([Payment::FAILED, Payment::CANCELLED, Payment::EXPIRED] as $status) {
            $product = $this->product(stock: 5);
            $payment = app(CheckoutService::class)->create('non-success-'.$status, [
                ['product_id' => $product->id, 'quantity' => 2],
            ]);
            $payload = [
                'event_id' => 'evt-'.$status,
                'type' => 'payment.'.($status === Payment::EXPIRED ? 'expired' : $status),
                'payment_id' => $payment->provider_payment_id,
                'status' => $status,
            ];

            $this->signedWebhook($payload)->assertOk()
                ->assertJsonPath('data.payment.status', $status)
                ->assertJsonPath('data.payment.sale_id', null);

            $this->assertSame(5, $product->fresh()->inventory->quantity);
            $this->assertSame(0, Sale::count());
            $this->assertDatabaseHas('transactions', [
                'payment_id' => $payment->id,
                'type' => match ($status) {
                    Payment::CANCELLED => 'payment_cancelled',
                    Payment::EXPIRED => 'payment_expired',
                    default => 'payment_failed',
                },
                'status' => $status,
            ]);
        }
    }

    public function test_paid_webhook_retries_complete_once_even_with_new_event_ids(): void
    {
        $product = $this->product(stock: 5);
        $payment = app(CheckoutService::class)->create('paid-retry-001', [
            ['product_id' => $product->id, 'quantity' => 2],
        ]);
        $payload = [
            'event_id' => 'evt-paid-001',
            'type' => 'payment.paid',
            'payment_id' => $payment->provider_payment_id,
            'status' => 'paid',
        ];

        $this->signedWebhook($payload)->assertOk()->assertJsonPath('data.duplicate', false);
        $this->signedWebhook($payload)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->signedWebhook([...$payload, 'event_id' => 'evt-paid-retry-002'])
            ->assertOk()
            ->assertJsonPath('data.duplicate', false);

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Transaction::where('type', 'payment_succeeded')->count());
        $this->assertSame(3, $product->fresh()->inventory->quantity);
        $this->assertSame(Payment::PAID, $payment->fresh()->status);
    }

    public function test_paymongo_envelope_is_normalized_before_settlement(): void
    {
        $product = $this->product();
        $payment = app(CheckoutService::class)->create('provider-envelope-001', [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
        $payload = [
            'data' => [
                'id' => 'evt-provider-envelope-001',
                'attributes' => [
                    'type' => 'payment.paid',
                    'resource' => [
                        'id' => $payment->provider_payment_id,
                        'attributes' => ['status' => 'succeeded'],
                    ],
                ],
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->postJson('/api/v1/webhooks/paymongo', $payload, [
            'X-PayMongo-Signature' => hash_hmac('sha256', $raw, $this->webhookSecret),
        ])->assertOk()->assertJsonPath('data.payment.status', Payment::PAID);

        $this->assertSame(1, Sale::count());
        $this->assertSame(4, $product->fresh()->inventory->quantity);
    }

    public function test_invalid_signature_is_rejected_before_event_parsing(): void
    {
        $product = $this->product();
        $payment = app(CheckoutService::class)->create('invalid-signature-001', [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
        $payload = [
            'event_id' => 'evt-invalid-signature',
            'type' => 'payment.paid',
            'payment_id' => $payment->provider_payment_id,
            'status' => 'paid',
        ];

        $this->postJson('/api/v1/webhooks/paymongo', $payload, [
            'X-PayMongo-Signature' => str_repeat('0', 64),
        ])->assertUnauthorized();

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
        $this->assertSame(5, $product->fresh()->inventory->quantity);

        config(['services.paymongo.webhook_secret' => null]);
        $this->postJson('/api/v1/webhooks/paymongo', $payload, [
            'X-PayMongo-Signature' => hash_hmac('sha256', json_encode($payload), $this->webhookSecret),
        ])->assertUnauthorized();
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_expired_status_refresh_is_explicit_and_non_destructive(): void
    {
        $product = $this->product();
        $payment = app(CheckoutService::class)->create('expired-refresh-001', [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
        config([
            'services.paymongo.secret_key' => 'sk_test_status_only',
            'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents',
        ]);
        Http::fake([
            'https://api.paymongo.test/v1/payment_intents/*' => Http::response([
                'data' => ['attributes' => ['status' => 'expired']],
            ]),
        ]);

        $this->postJson('/api/v1/payments/'.$payment->id.'/refresh', [], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', Payment::EXPIRED);

        $this->assertSame(0, Sale::count());
        $this->assertSame(5, $product->fresh()->inventory->quantity);
    }

    public function test_provider_network_failure_leaves_payment_pending(): void
    {
        $product = $this->product();
        $payment = app(CheckoutService::class)->create('network-uncertain-001', [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
        config([
            'services.paymongo.secret_key' => 'sk_test_status_only',
            'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents',
        ]);
        Http::fake([
            'https://api.paymongo.test/v1/payment_intents/*' => Http::response([], 503),
        ]);

        $this->postJson('/api/v1/payments/'.$payment->id.'/refresh', [], $this->authHeaders())
            ->assertStatus(503);

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
        $this->assertSame(0, Sale::count());
        $this->assertSame(5, $product->fresh()->inventory->quantity);
    }

    private function product(int $price = 500, int $stock = 5): Product
    {
        $product = Product::factory()->create(['price' => $price]);
        $product->inventory()->create(['quantity' => $stock, 'reorder_level' => 1]);

        return $product;
    }

    private function authHeaders(string $idempotencyKey = 'test-key'): array
    {
        return [
            'Authorization' => 'Bearer test-token',
            'Idempotency-Key' => $idempotencyKey,
        ];
    }

    private function signedWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->postJson('/api/v1/webhooks/paymongo', $payload, [
            'X-PayMongo-Signature' => hash_hmac('sha256', $raw, $this->webhookSecret),
        ]);
    }
}
