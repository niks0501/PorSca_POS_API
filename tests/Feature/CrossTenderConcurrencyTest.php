<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CrossTenderConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL locking reads and independent connections.');
        }
    }

    public static function tenderOrders(): array
    {
        return [['cash', 'qrph'], ['qrph', 'cash']];
    }

    #[DataProvider('tenderOrders')]
    public function test_disjoint_carts_cannot_claim_one_key_for_both_tenders(string $first, string $second): void
    {
        $workers = [];
        $inputs = [];
        $products = [];
        try {
            foreach ([$first, $second] as $tender) {
                $product = Product::factory()->create(['price' => 500]);
                $product->inventory()->create(['quantity' => 5, 'reorder_level' => 0]);
                $products[$tender] = $product;
                $input = new InputStream;
                $input->write(json_encode([
                    'connection' => config('database.connections.'.DB::getDefaultConnection()),
                    'product_id' => $product->id, 'tender' => $tender,
                ], JSON_THROW_ON_ERROR)."\n");
                $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-tender.php')], base_path());
                $workers[$tender] = $worker;
                $inputs[] = $input;
                $worker->setInput($input)->setTimeout(25)->start();
                $this->assertTrue($worker->waitUntil(
                    fn () => str_contains($worker->getOutput(), "claim-checked\n"),
                ), $worker->getOutput().$worker->getErrorOutput());
            }
            foreach ($inputs as $input) {
                $input->write("release\n");
                $input->close();
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(
                    fn () => str_contains($worker->getOutput(), "claim-released\n"),
                ), $worker->getOutput().$worker->getErrorOutput());
            }
            $outcomes = [];
            foreach ($workers as $tender => $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $outcomes[$tender] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            $sorted = array_values($outcomes);
            sort($sorted);
            $this->assertSame(['conflict', 'created'], $sorted);
            $cashWon = $outcomes['cash'] === 'created';
            $this->assertSame($cashWon ? 1 : 0, Sale::count());
            $this->assertSame($cashWon ? 0 : 1, Payment::count());
            $this->assertDatabaseCount('transactions', 1);
            $this->assertDatabaseCount('sale_items', $cashWon ? 1 : 0);
            $this->assertDatabaseCount('payment_items', $cashWon ? 0 : 1);
            $this->assertSame($cashWon ? 4 : 5, $products['cash']->inventory()->value('quantity'));
            $this->assertSame(5, $products['qrph']->inventory()->value('quantity'));
        } finally {
            foreach ($inputs as $input) {
                $input->close();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
