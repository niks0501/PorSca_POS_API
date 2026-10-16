<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStock;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\ReservedStock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function checkoutRequests(): array
    {
        return [
            'different keys' => ['race-checkout-b', 1, 'insufficient_stock'],
            'matching replay' => ['race-checkout-a', 1, 'payable'],
            'changed cart replay' => ['race-checkout-a', 2, 'idempotency_conflict'],
        ];
    }

    #[DataProvider('checkoutRequests')]
    public function test_concurrent_qr_checkouts_preserve_stock_and_replay_under_repeatable_read(string $key, int $quantity, string $outcome): void
    {
        $product = Product::factory()->create(['price' => 500]);
        $product->inventory()->create(['quantity' => 1, 'reorder_level' => 0]);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-checkout.php')], base_path());
        $worker->setTimeout(20);
        $worker->setInput(json_encode([
            'connection' => config('database.connections.'.DB::getDefaultConnection()),
            'product_id' => $product->id,
            'idempotency_key' => $key,
            'quantity' => $quantity,
        ], JSON_THROW_ON_ERROR));

        $started = false;
        DB::listen(function (QueryExecuted $query) use ($worker, &$started): void {
            if ($started || ! str_contains($query->sql, 'from `products`') || ! str_contains($query->sql, 'for update')) {
                return;
            }
            $started = true;

            $worker->start();
            $this->assertTrue($worker->waitUntil(
                fn () => str_contains($worker->getOutput(), "claim-check-ready\n"),
            ), 'Checkout B did not reach its claim check: '.$worker->getErrorOutput());
        });

        try {
            $winner = app(CheckoutService::class)->create('race-checkout-a', [
                ['product_id' => $product->id, 'quantity' => 1],
            ]);
            $this->assertTrue($started);
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(Payment::PENDING, $winner->status);
            $this->assertNotNull($winner->qr_payload);
            $this->assertSame($outcome, $result['outcome']);
            if ($outcome === 'payable') {
                $this->assertSame($winner->id, $result['id']);
                $this->assertSame(Payment::PENDING, $result['status']);
                $this->assertNotNull($result['qr_payload']);
            }
            $this->assertSame(1, Payment::count());
            $this->assertDatabaseCount('payment_items', 1);
            $this->assertSame(1, Transaction::where('type', 'payment_created')->count());
            $this->assertSame(0, Sale::count());
            $this->assertSame(1, $product->fresh()->inventory->quantity);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
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
