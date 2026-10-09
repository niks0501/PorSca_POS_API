<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\CheckoutOutcomes;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CheckoutAuthorityConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private array $server;

    protected function tearDown(): void
    {
        parent::tearDown();
        // Do not leave migration state cached for another suite/in-memory connection.
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL locking reads and independent HTTP processes.');
        }
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PORSCA_CONTRACT_VERSION' => 'porsca-mobile-api-v3',
            'HTTP_AUTHORIZATION' => 'Bearer '.User::factory()->create(['role' => 'admin'])->createToken('race')->plainTextToken];
    }

    private function checkout(Product $product, string $key = 'race'): array
    {
        return $this->postJson('/api/v1/checkouts', ['items' => [['productId' => $product->id, 'quantity' => 1]]],
            ['Authorization' => $this->server['HTTP_AUTHORIZATION'], 'X-PorSca-Contract-Version' => 'porsca-mobile-api-v3', 'Idempotency-Key' => $key])
            ->assertCreated()->json('data');
    }

    private function product(int $stock = 1): Product
    {
        $product = Product::factory()->create(['price' => 505]);
        $product->inventory()->create(['quantity' => $stock, 'reorder_level' => 0]);

        return $product;
    }

    public function test_two_different_cash_keys_cannot_finalize_same_checkout_twice(): void
    {
        $product = $this->product();
        $checkout = $this->checkout($product);
        $payload = ['revision' => 1, 'acceptedAmountCentavos' => 505, 'cashReceivedCentavos' => 505];
        $results = $this->race($product, [
            $this->request('/checkouts/'.$checkout['id'].'/cash', $payload, 'cash-a'),
            $this->request('/checkouts/'.$checkout['id'].'/cash', $payload, 'cash-b'),
        ]);
        $codes = array_column($results, 'http_status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('checkout_operations', 1);
        $this->assertSame('completed', Checkout::find($checkout['id'])->state);
        $this->assertSame(0, $product->inventory->fresh()->quantity);
    }

    public function test_same_cash_key_returns_original_receipt_to_both_processes(): void
    {
        $product = $this->product();
        $checkout = $this->checkout($product);
        $request = $this->request('/checkouts/'.$checkout['id'].'/cash', ['revision' => 1, 'acceptedAmountCentavos' => 505, 'cashReceivedCentavos' => 600], 'cash');
        $results = $this->race($product, [$request, $request]);
        $codes = array_column($results, 'http_status');
        sort($codes);
        $this->assertSame([200, 201], $codes);
        $this->assertSame($results[0]['body']['data']['sale']['id'], $results[1]['body']['data']['sale']['id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(0, $product->inventory->fresh()->quantity);
    }

    public function test_same_qr_key_creates_one_attempt_and_one_hold(): void
    {
        $product = $this->product();
        $checkout = $this->checkout($product);
        $request = $this->request('/checkouts/'.$checkout['id'].'/attempts', ['revision' => 1, 'acceptedAmountCentavos' => 505], 'qr');
        $results = $this->race($product, [$request, $request]);
        $codes = array_column($results, 'http_status');
        sort($codes);
        $this->assertSame([200, 201], $codes);
        $this->assertSame($results[0]['body']['data']['attempts'][0]['id'], $results[1]['body']['data']['attempts'][0]['id']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(1, $product->inventory->fresh()->quantity);
    }

    public function test_duplicate_paid_refreshes_converge_on_sale_under_repeatable_read(): void
    {
        $product = $this->product();
        $checkout = $this->checkout($product);
        config(['services.paymongo.secret_key' => '']);
        $response = $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', ['revision' => 1, 'acceptedAmountCentavos' => 505],
            ['Authorization' => $this->server['HTTP_AUTHORIZATION'], 'X-PorSca-Contract-Version' => 'porsca-mobile-api-v3', 'Idempotency-Key' => 'qr'])->assertCreated()->json('data');
        $attempt = Payment::findOrFail($response['attempts'][0]['id']);
        $request = $this->request('/checkouts/'.$checkout['id'].'/attempts/'.$attempt->id.'/refresh', [], 'refresh');
        $request['inspection'] = ['verified' => true, 'attempt_specific' => true, 'status' => 'paid', 'resource_id' => 'pay_'.$attempt->id];
        $results = $this->race($product, [$request, $request]);
        foreach ($results as $result) {
            $this->assertSame(200, $result['http_status']);
            $this->assertSame('completed', $result['body']['data']['state']);
            $this->assertSame(1, $result['inspections']);
        }
        $this->assertSame($results[0]['body']['data']['sale']['id'], $results[1]['body']['data']['sale']['id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(1, $attempt->transactions()->where('type', 'payment_succeeded')->count());
        $this->assertSame(0, $product->inventory->fresh()->quantity);
    }

    public function test_concurrent_administrators_cannot_overwrite_case_history(): void
    {
        $product = $this->product();
        $checkout = $this->checkout($product);
        config(['services.paymongo.secret_key' => '']);
        $response = $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', ['revision' => 1, 'acceptedAmountCentavos' => 505],
            ['Authorization' => $this->server['HTTP_AUTHORIZATION'], 'X-PorSca-Contract-Version' => 'porsca-mobile-api-v3', 'Idempotency-Key' => 'qr'])->assertCreated()->json('data');
        $attempt = Payment::findOrFail($response['attempts'][0]['id']);
        $attempt->update(['reservation_expires_at' => now()->subMinute()]);
        $product->inventory()->update(['quantity' => 0]);
        app(CheckoutOutcomes::class)->observe($attempt, ['verified' => true, 'attempt_specific' => true, 'status' => 'paid']);
        $case = ReconciliationCase::firstOrFail();
        $first = $this->request('/reconciliation-cases/'.$case->id, ['version' => 1, 'action' => 'review', 'explanation' => 'First administrator investigated this case.'], 'first');
        $second = $this->request('/reconciliation-cases/'.$case->id, ['version' => 1, 'action' => 'review', 'explanation' => 'Second administrator investigated this case.'], 'second');
        $first['method'] = $second['method'] = 'PATCH';
        $results = $this->race($product, [$first, $second], $checkout['id']);
        $codes = array_column($results, 'http_status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $this->assertSame(2, $case->fresh()->version);
        $this->assertSame(1, $case->events()->where('type', 'case_review')->count());
        $this->assertDatabaseCount('sales', 0);
    }

    private function request(string $path, array $payload, string $key): array
    {
        return ['path' => '/api/v1'.$path, 'method' => 'POST', 'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'server' => [...$this->server, 'HTTP_IDEMPOTENCY_KEY' => $key]];
    }

    private function race(Product $product, array $requests, ?string $checkoutId = null): array
    {
        $workers = [];
        DB::beginTransaction();
        Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        if ($checkoutId !== null) {
            Checkout::whereKey($checkoutId)->lockForUpdate()->firstOrFail();
        }
        try {
            foreach ($requests as $request) {
                $input = new InputStream;
                $input->write(json_encode($request + ['connection' => config('database.connections.'.DB::getDefaultConnection()),
                    'hold_shared_lock' => false, 'observe_shared_lock' => true, 'observe_table' => $checkoutId === null ? 'products' : 'checkouts'], JSON_THROW_ON_ERROR)."\n");
                $input->close();
                $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-settlement.php')], base_path());
                $worker->setInput($input)->setTimeout(30)->start();
                $workers[] = $worker;
                $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'shared-lock-attempt:')), $worker->getErrorOutput());
                // The parent owns this exact row lock. Check both real workers have
                // attempted it and neither has acquired it before releasing the holder.
                // This barrier needs no global PROCESS privilege.
                usleep(100000);
                $this->assertTrue($worker->isRunning());
                $this->assertStringNotContainsString('shared-lock-acquired', $worker->getOutput());
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                // QR provisioning has a second, post-I/O transaction; no deadlock retries permitted.
                $this->assertLessThanOrEqual(str_ends_with($requests[0]['path'], '/attempts') ? 2 : 1, $result['attempts']);
                $this->assertSame(0, $result['transaction_level']);
                $results[] = $result;
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
