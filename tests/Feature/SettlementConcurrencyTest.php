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
    public function test_two_paid_settlements_complete_under_concurrency(bool $firstWebhook, bool $secondWebhook): void
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
    public function test_paid_settlement_and_stock_edit_complete_under_concurrency(bool $webhook, bool $inventoryRoute): void
    {
        $product = $this->product();
        $payment = $this->checkout($product, 'stock-race');
        $stockRequest = [
            'path' => '/api/v1/'.($inventoryRoute ? 'inventory/' : 'products/').$product->id,
            'method' => 'PATCH',
            // Equal to the initial stock; either serialization leaves sufficient stock.
            'body' => json_encode($inventoryRoute ? ['quantity' => 2] : ['stock' => 2, 'name' => 'Edited product'], JSON_THROW_ON_ERROR),
            'server' => $this->server(),
        ];
        $results = $this->race([$this->settlementRequest($payment, $webhook), $stockRequest], holderIndex: 1);
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
            // Retryable audit rows avoid an unrelated missing-event gap lock in the concurrency path.
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
            'path' => $webhook ? '/api/v1/webhooks/paymongo' : '/api/v1/payments/'.$payment->id.'/refresh',
            'method' => 'POST', 'body' => $body, 'server' => $server,
        ];
    }

    private function worker(array $request, InputStream $input, bool $holdSharedLock = false, bool $observeSharedLock = false): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-settlement.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput($input);
        $input->write(json_encode($request + [
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'hold_shared_lock' => $holdSharedLock,
            'observe_shared_lock' => $observeSharedLock,
        ], JSON_THROW_ON_ERROR)."\n");

        return $worker;
    }

    private function race(array $requests, int $holderIndex = 0): array
    {
        $streams = [new InputStream, new InputStream];
        $workers = [];
        $contenderIndex = 1 - $holderIndex;
        $holderReleased = false;
        try {
            $workers[$holderIndex] = $this->worker($requests[$holderIndex], $streams[$holderIndex], holdSharedLock: true);
            $workers[$holderIndex]->start();
            $this->assertTrue($workers[$holderIndex]->waitUntil(fn () => str_contains($workers[$holderIndex]->getOutput(), "shared-lock-held\n")), 'First worker did not acquire the shared product lock.');

            $workers[$contenderIndex] = $this->worker($requests[$contenderIndex], $streams[$contenderIndex], observeSharedLock: true);
            $workers[$contenderIndex]->start();
            $this->assertTrue($workers[$contenderIndex]->waitUntil(fn () => str_contains($workers[$contenderIndex]->getOutput(), 'shared-lock-attempt:')), 'Competing worker did not reach the shared product lock.');
            $this->assertSame(1, preg_match('/shared-lock-attempt:(\\d+)/', $workers[$contenderIndex]->getOutput(), $matches));
            $this->assertTrue($this->waitForProductLockWait((int) $matches[1]), 'Competing transaction did not wait on the held product lock.');

            $streams[$holderIndex]->write("continue\n");
            $streams[$holderIndex]->close();
            $holderReleased = true;
            $streams[$contenderIndex]->close();
            $results = [$this->workerResult($workers[0]), $this->workerResult($workers[1])];
            foreach ($results as $result) {
                $this->assertSame(1, $result['attempts'], 'Contending requests must complete without a deadlock retry.');
            }

            return $results;
        } finally {
            if (! $holderReleased) {
                $streams[$holderIndex]->write("continue\n");
                $streams[$holderIndex]->close();
            }
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

    private function waitForProductLockWait(int $connectionId): bool
    {
        $deadline = microtime(true) + 5;
        do {
            $transaction = DB::selectOne(
                'SELECT trx_state, trx_query FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?',
                [$connectionId],
            );
            if ($transaction !== null && $transaction->trx_state === 'LOCK WAIT'
                && str_contains(strtolower((string) $transaction->trx_query), 'products')) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function requestWithoutBarrier(array $request): void
    {
        $stream = new InputStream;
        $worker = $this->worker($request, $stream);
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
