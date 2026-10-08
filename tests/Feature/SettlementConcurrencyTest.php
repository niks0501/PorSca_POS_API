<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SettlementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL deadlock detection and independent connections.');
        }
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        config(['services.paymongo.mode' => 'sandbox', 'services.paymongo.secret_key' => '']);
        $this->token = User::factory()->create()->createToken('concurrency')->plainTextToken;
    }

    public static function settlementPairs(): array
    {
        return [
            'direct refreshes' => [false, false],
            'signed webhooks' => [true, true],
            'webhook and refresh' => [true, false],
        ];
    }

    #[DataProvider('settlementPairs')]
    public function test_two_paid_settlements_complete_after_a_real_deadlock(bool $firstWebhook, bool $secondWebhook): void
    {
        $product = $this->product();
        $first = $this->checkout($product, 'first');
        $second = $this->checkout($product, 'second');
        $requests = [$this->settlementRequest($first, $firstWebhook), $this->settlementRequest($second, $secondWebhook)];
        $results = $this->race($requests);
        foreach ([$first, $second] as $index => $payment) {
            $this->assertSame(Payment::PAID, $payment->fresh()->status);
            $this->assertNotNull($payment->fresh()->sale_id);
            $this->assertSame(1, $results[$index]['inspections']);
            $this->assertSame(1, Transaction::where('payment_id', $payment->id)->where('type', 'payment_succeeded')->count());
            $this->assertSame(1, Sale::where('idempotency_key', 'payment:'.$payment->id)->count());
        }
        $this->assertSame(2, Sale::count());
        $this->assertDatabaseCount('sale_items', 2);
        $this->assertSame(0, $product->fresh()->inventory->quantity);
        $this->assertProcessedWebhooks((int) $firstWebhook + (int) $secondWebhook);

        // Redelivery must not duplicate the committed sale, ledger, or deduction.
        foreach ($requests as $request) {
            $this->requestWithoutBarrier($request);
        }
        $this->assertSame(2, Sale::count());
        $this->assertSame(2, Transaction::where('type', 'payment_succeeded')->count());
        $this->assertSame(0, $product->fresh()->inventory->quantity);
    }

    public static function stockPairs(): array
    {
        return [
            'refresh versus product edit' => [false, false],
            'refresh versus inventory edit' => [false, true],
            'webhook versus product edit' => [true, false],
            'webhook versus inventory edit' => [true, true],
        ];
    }

    #[DataProvider('stockPairs')]
    public function test_paid_settlement_and_stock_edit_complete_after_a_real_deadlock(bool $webhook, bool $inventoryRoute): void
    {
        $product = $this->product();
        $payment = $this->checkout($product, 'stock-race');
        $stockRequest = [
            'operation' => 'stock',
            'path' => '/api/v1/'.($inventoryRoute ? 'inventory/' : 'products/').$product->id,
            'method' => 'PATCH',
            // Equal to the initial stock; either serialization leaves sufficient stock.
            'body' => json_encode($inventoryRoute ? ['quantity' => 2] : ['stock' => 2, 'name' => 'Edited product'], JSON_THROW_ON_ERROR),
            'server' => $this->server(),
        ];
        $results = $this->race([$this->settlementRequest($payment, $webhook), $stockRequest]);
        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame(1, $results[0]['inspections']);
        $this->assertSame(0, $results[1]['inspections']);
        $this->assertSame(1, Sale::count());
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertSame(1, Transaction::where('type', 'payment_succeeded')->count());
        // Absolute stock edit semantics are unchanged: its order determines quantity.
        $this->assertContains($product->fresh()->inventory->quantity, [1, 2]);
        if (! $inventoryRoute) {
            $this->assertSame('Edited product', $product->fresh()->name);
        }
        $this->assertProcessedWebhooks((int) $webhook);
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 2, 'reorder_level' => 0]);

        return $product;
    }

    private function checkout(Product $product, string $key): Payment
    {
        return app(CheckoutService::class)->create($key, [['product_id' => $product->id, 'quantity' => 1]]);
    }

    private function server(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
    }

    private function settlementRequest(Payment $payment, bool $webhook): array
    {
        if ($webhook) {
            // Retryable audit rows avoid an unrelated missing-event gap lock
            // blocking the barrier before both payment locks have been acquired.
            WebhookEvent::create([
                'provider_event_id' => 'evt-race-'.$payment->id,
                'event_type' => 'payment.paid', 'signature_valid' => true,
                'payload' => ['resource_id' => $payment->provider_payment_id],
                'processing_error' => 'provider_mismatch',
            ]);
        }
        $body = $webhook ? json_encode(['data' => [
            'id' => 'evt-race-'.$payment->id,
            'attributes' => ['type' => 'payment.paid', 'data' => ['id' => $payment->provider_payment_id]],
        ]], JSON_THROW_ON_ERROR) : '{}';
        $server = $this->server();
        $timestamp = time();
        $server['HTTP_PAYMONGO_SIGNATURE'] = 't='.$timestamp.',te='.hash_hmac('sha256', $timestamp.'.'.$body, 'concurrency-fixture-secret');

        return [
            'operation' => 'settle',
            'path' => $webhook ? '/api/v1/webhooks/paymongo' : '/api/v1/payments/'.$payment->id.'/refresh',
            'method' => 'POST', 'body' => $body, 'server' => $server,
        ];
    }

    private function worker(array $request, InputStream $input): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-settlement.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput($input);
        $input->write(json_encode($request + ['connection' => config('database.connections.'.DB::getDefaultConnection())], JSON_THROW_ON_ERROR)."\n");

        return $worker;
    }

    private function race(array $requests): array
    {
        $streams = [new InputStream, new InputStream];
        $workers = [$this->worker($requests[0], $streams[0]), $this->worker($requests[1], $streams[1])];
        try {
            foreach ($workers as $worker) {
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), "lock-ready\n")), 'Worker never acquired its first lock: '.$worker->getOutput().$worker->getErrorOutput());
            }
            foreach ($streams as $stream) {
                $stream->write("continue\n");
                $stream->close();
            }
            // Pump each process's input pipe before waiting for either result.
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), "lock-released\n")), 'Worker did not leave the barrier.');
            }
            $results = array_map(fn (Process $worker) => $this->workerResult($worker), $workers);
            $this->assertGreaterThan(2, array_sum(array_column($results, 'attempts')), 'The barrier must cause an actual deadlock and outer retry.');
            foreach ($results as $result) {
                $this->assertLessThanOrEqual(3, $result['attempts']);
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ($streams as $stream) {
                $stream->close();
            }
        }
    }

    private function requestWithoutBarrier(array $request): void
    {
        $stream = new InputStream;
        $worker = $this->worker($request, $stream);
        $stream->write("continue\n");
        $stream->close();
        $worker->start();
        $this->workerResult($worker);
    }

    private function workerResult(Process $worker): array
    {
        $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(200, $result['http_status'], json_encode($result['body']));
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }

    private function assertProcessedWebhooks(int $expected): void
    {
        $this->assertSame($expected, WebhookEvent::count());
        $this->assertSame($expected, WebhookEvent::whereNotNull('processed_at')->whereNull('processing_error')->count());
    }
}
