<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStock;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\ReservedStock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReservationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL locking reads and two independent connections.');
        }

        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        config(['services.paymongo.mode' => 'sandbox', 'services.paymongo.secret_key' => '']);
    }

    public function test_concurrent_qr_checkouts_cannot_both_reserve_the_last_unit_under_repeatable_read(): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 1, 'reorder_level' => 0]);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-checkout.php')], base_path());
        $worker->setTimeout(20);
        $worker->setInput(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'product_id' => $product->id,
        ], JSON_THROW_ON_ERROR));

        $started = false;
        DB::listen(function (QueryExecuted $query) use ($worker, &$started): void {
            if ($started || ! str_contains($query->sql, 'from `products`') || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $started = true;

            $worker->start();
            $this->assertTrue($worker->waitUntil(
                fn () => str_contains($worker->getOutput(), "snapshot-ready\n"),
            ), 'Checkout B did not establish its snapshot: '.$worker->getErrorOutput());
        });

        try {
            $winner = app(CheckoutService::class)->create('race-checkout-a', [
                ['product_id' => $product->id, 'quantity' => 1],
            ]);
            $this->assertTrue($started);
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $loser = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(Payment::PENDING, $winner->status);
            $this->assertNotNull($winner->qr_payload);
            $this->assertSame('insufficient_stock', $loser['outcome']);
            $this->assertSame(1, Payment::count());
            $this->assertDatabaseCount('payment_items', 1);
            $payment = Payment::firstOrFail();
            $this->assertSame(Payment::PENDING, $payment->status);
            $this->assertSame(500, (int) $payment->amount);
            $this->assertTrue($payment->reservation_expires_at->isFuture());
            $this->assertDatabaseHas('payment_items', [
                'payment_id' => $payment->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500,
            ]);
            $reserved = (int) DB::table('payment_items')->join('payments', 'payments.id', '=', 'payment_items.payment_id')
                ->where('payment_items.product_id', $product->id)->where('payments.status', Payment::PENDING)
                ->where('payments.reservation_expires_at', '>', now())->sum('payment_items.quantity');
            $this->assertSame(1, $reserved);
            $this->assertSame(1, Transaction::where('type', 'payment_created')->count());
            $this->assertSame(500, (int) Transaction::where('payment_id', $payment->id)->where('type', 'payment_created')->value('amount'));
            $this->assertSame(0, Sale::count());
            $onHand = (int) $product->fresh()->inventory->quantity;
            $this->assertSame(1, $onHand);
            $this->assertLessThanOrEqual($onHand, $reserved);
            $this->assertGreaterThanOrEqual(0, $onHand);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_cash_deduction_prevents_a_concurrent_qr_reservation_from_overdrawing_stock(): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 1, 'reorder_level' => 0]);
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('reservation-race')->plainTextToken;
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $streams = [new InputStream, new InputStream];
        $workers = [];
        $holderReleased = false;
        try {
            $cash = [
                'connection' => $connection,
                'path' => '/api/v1/sales/checkout', 'method' => 'POST',
                'body' => json_encode(['idempotency_key' => 'reservation-cash', 'payment_method' => 'cash',
                    'cash_received' => 500, 'items' => [['product_id' => $product->id, 'quantity' => 1]]], JSON_THROW_ON_ERROR),
                'server' => ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token],
                'hold_shared_lock' => true,
                'observe_shared_lock' => false,
            ];
            $streams[0]->write(json_encode($cash, JSON_THROW_ON_ERROR)."\n");
            $workers[0] = new Process([PHP_BINARY, base_path('tests/Support/concurrent-settlement.php')], base_path());
            $workers[0]->setInput($streams[0])->setTimeout(30)->start();
            $this->assertTrue($workers[0]->waitUntil(
                fn () => str_contains($workers[0]->getOutput(), "shared-lock-held\n"),
            ), 'Cash checkout did not acquire the shared product lock.');

            $qr = [
                'connection' => $connection,
                'path' => '/api/v1/payments', 'method' => 'POST',
                'body' => json_encode(['items' => [['product_id' => $product->id, 'quantity' => 1]]], JSON_THROW_ON_ERROR),
                'server' => ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'reservation-qr'],
                'hold_shared_lock' => false,
                'observe_shared_lock' => true,
                'observe_table' => 'products',
            ];
            $streams[1]->write(json_encode($qr, JSON_THROW_ON_ERROR)."\n");
            $workers[1] = new Process([PHP_BINARY, base_path('tests/Support/concurrent-settlement.php')], base_path());
            $workers[1]->setInput($streams[1])->setTimeout(30)->start();
            $this->assertTrue($workers[1]->waitUntil(
                fn () => str_contains($workers[1]->getOutput(), 'shared-lock-attempt:'),
            ), 'QR checkout did not reach the shared product lock.');
            usleep(100_000);
            $this->assertTrue($workers[1]->isRunning());
            $this->assertStringNotContainsString('shared-lock-acquired', $workers[1]->getOutput());

            $streams[0]->write("continue\n");
            $streams[0]->close();
            $holderReleased = true;
            $this->assertTrue($workers[0]->waitUntil(
                fn () => str_contains($workers[0]->getOutput(), "shared-lock-released\n"),
            ), 'Cash checkout did not acknowledge product-lock release.');
            $streams[1]->close();

            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            foreach ($results as $result) {
                $this->assertSame(1, $result['attempts']);
                $this->assertSame(0, $result['transaction_level']);
            }
            $this->assertSame(201, $results[0]['http_status']);
            $this->assertSame(409, $results[1]['http_status']);
            $this->assertSame('insufficient_stock', $results[1]['body']['error']['code']);
            $this->assertDatabaseCount('sales', 1);
            $this->assertDatabaseCount('sale_items', 1);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('transactions', 1);
            $sale = Sale::firstOrFail();
            $this->assertSame(500, (int) $sale->total_amount);
            $this->assertDatabaseHas('sale_items', [
                'sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 500,
            ]);
            $this->assertDatabaseHas('transactions', [
                'sale_id' => $sale->id, 'type' => 'cash_sale', 'status' => 'completed', 'amount' => 500,
            ]);
            $onHand = (int) $product->fresh()->inventory->quantity;
            $this->assertSame(0, $onHand);
            $this->assertGreaterThanOrEqual(0, $onHand);
        } finally {
            if (! $holderReleased) {
                $streams[0]->write("continue\n");
                $streams[0]->close();
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

    public function test_stock_floor_includes_reservations_committed_after_its_snapshot(): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 1, 'reorder_level' => 0]);
        $connection = DB::getDefaultConnection();
        config(['database.connections.reservation_writer' => config('database.connections.'.$connection)]);

        DB::beginTransaction();
        try {
            $this->assertSame(0, Payment::count()); // Establish the editor's stale snapshot.
            DB::setDefaultConnection('reservation_writer');
            app(CheckoutService::class)->create('floor-checkout', [
                ['product_id' => $product->id, 'quantity' => 1],
            ]);
            DB::setDefaultConnection($connection);

            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            Inventory::where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $this->assertSame(0, Payment::count()); // Locking inventory did not refresh it.
            $this->expectException(InsufficientStock::class);
            app(ReservedStock::class)->assertStockFloor($product->id, 0, $product->name);
        } finally {
            DB::setDefaultConnection($connection);
            DB::rollBack();
            DB::purge('reservation_writer');
        }
    }
}
