<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.api_token' => 'test-token',
            'services.paymongo.mode' => 'sandbox',
            'services.paymongo.secret_key' => 'sk_test_fixture_only',
            'services.paymongo.webhook_secret' => 'fixture-webhook-secret',
            'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents',
        ]);
        $this->fakeCreation();
    }

    private function fakeHttp(array $routes): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($routes);
    }

    private function fakeCreation(): void
    {
        $this->fakeHttp([
            'https://api.paymongo.test/v1/payment_intents' => Http::response(['data' => [
                'id' => 'pi_fixture', 'attributes' => ['client_key' => 'client_fixture'],
            ]], 201),
            'https://api.paymongo.test/v1/payment_methods' => Http::response(['data' => ['id' => 'pm_fixture']], 201),
            'https://api.paymongo.test/v1/payment_intents/pi_fixture/attach' => Http::response(['data' => [
                'attributes' => ['status' => 'awaiting_next_action', 'payment' => 'pay_fixture',
                    'next_action' => ['code' => ['image_url' => 'data:image/png;base64,fixture']]],
            ]]),
            'https://api.paymongo.test/v1/payment_intents/pi_fixture' => Http::response($this->intent('awaiting_next_action')),
        ]);
    }

    private function intent(string $status, int $amount = 500, string $currency = 'PHP', bool $livemode = false): array
    {
        return ['data' => ['id' => 'pi_fixture', 'attributes' => [
            'status' => $status, 'amount' => $amount, 'currency' => $currency, 'livemode' => $livemode,
            'payment' => 'pay_fixture',
        ]]];
    }

    private function product(int $stock = 5): Product
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => $stock, 'reorder_level' => 1]);

        return $product;
    }

    private function start(Product $product, string $key = 'fixture-checkout'): Payment
    {
        $this->postJson('/api/v1/payments', ['items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1],
        ], 'total' => 1], ['Authorization' => 'Bearer test-token', 'Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.amount', 500)
            ->assertJsonPath('data.qr_payload', 'data:image/png;base64,fixture')
            ->assertJsonPath('data.checkout_url', null);

        return Payment::where('idempotency_key', $key)->firstOrFail();
    }

    private function signed(array $payload, ?int $timestamp = null, bool $live = false)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $header = 't='.$timestamp.','.($live ? 'li=' : 'te=').hash_hmac('sha256', $timestamp.'.'.$raw, 'fixture-webhook-secret');

        return $this->postJson('/api/v1/webhooks/paymongo', $payload, ['Paymongo-Signature' => $header]);
    }

    private function event(string $id, string $type = 'payment.paid', string $resource = 'pay_fixture'): array
    {
        return ['data' => ['id' => $id, 'attributes' => [
            'type' => $type, 'resource' => ['id' => $resource, 'attributes' => ['status' => 'paid']],
        ]]];
    }

    public function test_persisted_operation_keys_are_reused_after_partial_failure_and_no_network_inside_transaction(): void
    {
        $product = $this->product();
        $testTransactionLevel = DB::transactionLevel();
        $this->fakeHttp([
            'https://api.paymongo.test/v1/payment_intents' => function () use ($testTransactionLevel) {
                $this->assertSame($testTransactionLevel, DB::transactionLevel());

                return Http::response(['data' => ['id' => 'pi_fixture', 'attributes' => ['client_key' => 'client_fixture']]], 201);
            },
            'https://api.paymongo.test/v1/payment_methods' => Http::response([], 503),
        ]);
        $body = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $headers = ['Authorization' => 'Bearer test-token', 'Idempotency-Key' => 'retry-key'];
        $this->postJson('/api/v1/payments', $body, $headers)->assertStatus(503);
        $payment = Payment::firstOrFail();
        $this->assertNotNull($payment->provider_operation_key);
        $this->assertSame('pi_fixture', $payment->provider_payment_id);
        $this->assertSame(0, Sale::count());
        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === 'https://api.paymongo.test/v1/payment_intents')->count());
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $payment->provider_operation_key.':intent')
            && str_contains($request->body(), '"amount":500') && str_contains($request->body(), '"currency":"PHP"'));
        $this->fakeCreation();
        $this->postJson('/api/v1/payments', $body, $headers)->assertOk()->assertJsonPath('data.id', $payment->id);
        $this->assertSame(1, Payment::count());
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $payment->provider_operation_key.':method'));
        $this->assertSame(0, Http::recorded(fn ($request) => $request->url() === 'https://api.paymongo.test/v1/payment_intents')->count());
    }

    public function test_signed_paid_event_and_duplicate_and_late_failure_commit_once(): void
    {
        $product = $this->product();
        $payment = $this->start($product);
        $this->fakeHttp(['https://api.paymongo.test/v1/payment_intents/pi_fixture' => Http::response($this->intent('succeeded'))]);
        $event = $this->event('evt-paid');
        $this->signed($event)->assertOk()->assertJsonPath('data.payment.status', Payment::PAID);
        $this->signed($event)->assertOk()->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.payment.sale_id', $payment->fresh()->sale_id);
        $this->signed($this->event('evt-late', 'payment.failed'))->assertOk()->assertJsonPath('data.payment.status', Payment::PAID);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Transaction::where('type', 'payment_succeeded')->count());
        $this->assertSame(4, $product->fresh()->inventory->quantity);
    }

    public function test_signature_freshness_live_mode_and_unknown_event_are_rejected_before_settlement(): void
    {
        $product = $this->product();
        $this->start($product);
        $event = $this->event('evt-invalid');
        $this->signed($event, time() - 301)->assertUnauthorized();
        $this->signed($event, null, true)->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/paymongo', $event, ['X-PayMongo-Signature' => str_repeat('0', 64)])->assertUnauthorized();
        $this->signed($this->event('evt-unknown', 'other.event'))->assertStatus(422);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_provider_mismatch_and_pending_and_failed_never_sell(): void
    {
        $product = $this->product();
        $this->start($product);
        foreach ([$this->intent('succeeded', 499), $this->intent('succeeded', 500, 'USD'), $this->intent('succeeded', 500, 'PHP', true), $this->intent('awaiting_next_action')] as $i => $response) {
            $this->fakeHttp(['https://api.paymongo.test/v1/payment_intents/pi_fixture' => Http::response($response)]);
            $this->signed($this->event('evt-mismatch-'.$i))->assertOk();
            $this->assertSame(0, Sale::count());
        }
        $this->fakeHttp(['https://api.paymongo.test/v1/payment_intents/pi_fixture' => Http::response($this->intent('failed'))]);
        $this->signed($this->event('evt-failed', 'payment.failed'))->assertOk()->assertJsonPath('data.payment.status', Payment::FAILED);
        $this->assertSame(5, $product->fresh()->inventory->quantity);
    }

    public function test_invalid_amount_currency_and_aggregate_quantity_fail_before_http(): void
    {
        $product = $this->product();
        $headers = ['Authorization' => 'Bearer test-token', 'Idempotency-Key' => 'invalid-cart'];
        $product->update(['price' => 0]);
        $this->postJson('/api/v1/payments', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], $headers)->assertStatus(422);
        $product->update(['price' => 500, 'currency' => 'USD']);
        $this->postJson('/api/v1/payments', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], $headers)->assertStatus(422);
        $product->update(['price' => 4294967295, 'currency' => 'PHP']);
        $this->postJson('/api/v1/payments', ['items' => [['product_id' => $product->id, 'quantity' => 2]]], $headers)->assertStatus(422);
        $this->postJson('/api/v1/payments', ['items' => [
            ['product_id' => $product->id, 'quantity' => 9000],
            ['product_id' => $product->id, 'quantity' => 2000],
        ]], $headers)->assertStatus(422);
        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_raw_bytes_and_out_of_order_expiry_then_paid_reconcile(): void
    {
        $product = $this->product(1);
        $payment = $this->start($product);
        $raw = json_encode($this->event('evt-raw-expiry', 'qrph.expired'), JSON_THROW_ON_ERROR)."  \n";
        $t = time();
        $signature = 't='.$t.',te='.hash_hmac('sha256', $t.'.'.$raw, 'fixture-webhook-secret');
        $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $raw)->assertOk()->assertJsonPath('data.payment.status', Payment::EXPIRED);
        $this->assertSame(0, Sale::count());
        $this->signed($this->event('evt-raw-expiry', 'qrph.expired'))->assertOk()->assertJsonPath('data.duplicate', true);
        $this->fakeHttp(['https://api.paymongo.test/v1/payment_intents/pi_fixture' => Http::response($this->intent('succeeded'))]);
        $this->signed($this->event('evt-after-expiry'))->assertOk()->assertJsonPath('data.payment.status', Payment::PAID);
        $this->assertSame(1, Sale::count());
        $this->assertSame(0, $product->fresh()->inventory->quantity);
        $this->assertSame($payment->fresh()->sale_id, Sale::firstOrFail()->id);
    }

    public function test_reservations_block_cash_and_stock_reduction_and_late_paid_is_visible(): void
    {
        $product = $this->product(1);
        $payment = $this->start($product);
        $this->postJson('/api/v1/payments', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], [
            'Authorization' => 'Bearer test-token', 'Idempotency-Key' => 'second-qr',
        ])->assertStatus(409);
        $this->postJson('/api/v1/sales/cash', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'cash_received' => 500], [
            'Authorization' => 'Bearer test-token', 'Idempotency-Key' => 'cash-race',
        ])->assertStatus(409);
        $this->patchJson('/api/v1/products/'.$product->id.'/stock', ['stock' => 0], ['Authorization' => 'Bearer test-token'])->assertStatus(409);
        $this->patchJson('/api/v1/products/'.$product->id, ['stock' => 0], ['Authorization' => 'Bearer test-token'])->assertStatus(409);
        $this->assertSame(1, $product->fresh()->inventory->quantity);
        $payment->update(['reservation_expires_at' => now()->subMinute()]);
        $this->patchJson('/api/v1/products/'.$product->id.'/stock', ['stock' => 0], ['Authorization' => 'Bearer test-token'])->assertOk();
        $this->fakeHttp(['https://api.paymongo.test/v1/payment_intents/pi_fixture' => Http::response($this->intent('succeeded'))]);
        $this->signed($this->event('evt-late-paid'))->assertOk()->assertJsonPath('data.payment.status', Payment::PAID_UNFULFILLED);
        $this->assertSame(0, Sale::count());
        $this->assertSame('stock_reconciliation_required', $payment->fresh()->failure_reason);
        $this->assertSame(1, Transaction::where('type', 'payment_paid_unfulfilled')->count());
    }
}
