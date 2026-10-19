<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LegacyCheckoutKeyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL locking reads and independent HTTP processes.');
        }
    }

    public static function tenderOrders(): array
    {
        return [['cash', 'qrph', false], ['qrph', 'cash', false], ['cash', 'qrph', true], ['qrph', 'cash', true]];
    }

    #[DataProvider('tenderOrders')]
    public function test_concurrent_public_key_has_one_tender_owner(string $first, string $second, bool $differentCart): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 2, 'reorder_level' => 0]);
        $other = $differentCart ? Product::factory()->create(['price' => 500]) : $product;
        if ($differentCart) {
            $other->inventory()->create(['quantity' => 2, 'reorder_level' => 0]);
        }
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('race')->plainTextToken;
        $streams = [new InputStream, new InputStream];
        $workers = [];
        try {
            foreach ([[$first, $product], [$second, $other]] as $index => [$method, $item]) {
                $streams[$index]->write(json_encode([
                    'connection' => config('database.connections.'.DB::getDefaultConnection()),
                    'path' => '/api/v1/sales/checkout', 'method' => 'POST',
                    'body' => json_encode([
                        'idempotency_key' => 'shared-public-key', 'payment_method' => $method, 'cash_received' => 500,
                        'items' => [['product_id' => $item->id, 'quantity' => 1]],
                    ], JSON_THROW_ON_ERROR),
                    'server' => ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                        'HTTP_AUTHORIZATION' => 'Bearer '.$token],
                    'hold_shared_lock' => $index === 0, 'observe_shared_lock' => false,
                    'observe_transaction' => $index === 1,
                ], JSON_THROW_ON_ERROR)."\n");
                $workers[$index] = new Process([PHP_BINARY, base_path('tests/Support/concurrent-settlement.php')], base_path());
                $workers[$index]->setInput($streams[$index])->setTimeout(30)->start();
                $marker = $index === 0 ? "shared-lock-held\n" : 'connection:';
                $this->assertTrue($workers[$index]->waitUntil(fn () => str_contains($workers[$index]->getOutput(), $marker)), $workers[$index]->getErrorOutput());
            }
            $this->assertSame(1, preg_match('/connection:(\d+)/', $workers[1]->getOutput(), $matches));
            $deadline = microtime(true) + 5;
            do {
                $transaction = DB::selectOne(
                    'SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?', [(int) $matches[1]],
                );
                if ($transaction?->trx_state === 'LOCK WAIT') {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertSame('LOCK WAIT', $transaction?->trx_state);
            $streams[0]->write("continue\n");
            $streams[0]->close();
            $this->assertTrue($workers[0]->waitUntil(fn () => str_contains($workers[0]->getOutput(), "shared-lock-released\n")));
            $streams[1]->close();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(0, end($results)['transaction_level']);
                $this->assertSame(1, end($results)['attempts']);
            }
            $this->assertSame(201, $results[0]['http_status']);
            $this->assertSame(409, $results[1]['http_status']);
            $this->assertSame('idempotency_conflict', $results[1]['body']['error']['code']);
            $this->assertDatabaseCount('sales', $first === 'cash' ? 1 : 0);
            $this->assertDatabaseCount('payments', $first === 'qrph' ? 1 : 0);
            $this->assertSame($first === 'cash' ? 1 : 2, $product->inventory()->value('quantity'));
            if ($differentCart) {
                $this->assertSame(2, $other->inventory()->value('quantity'));
            }
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
}
