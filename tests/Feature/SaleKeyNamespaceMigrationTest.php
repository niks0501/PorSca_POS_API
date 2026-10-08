<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SaleKeyNamespaceMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // These fixtures deliberately exercise keys that cannot be rolled back.
        Sale::query()->delete();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_08_120000_separate_sale_key_namespaces.php');
    }

    public function test_existing_sale_keys_are_backfilled_without_changing_sale_identity(): void
    {
        $migration = $this->migration();
        $migration->down();
        $cash = Sale::create(['idempotency_key' => 'legacy-cash', 'total_amount' => 100, 'payment_method' => 'cash']);
        $qr = Sale::create(['idempotency_key' => 'payment:1', 'total_amount' => 100, 'payment_method' => 'qrph']);
        $migration->up();

        $this->assertSame(Sale::CHECKOUT_NAMESPACE, $cash->fresh()->key_namespace);
        $this->assertSame(Sale::SETTLEMENT_NAMESPACE, $qr->fresh()->key_namespace);
        $this->assertSame('payment:1', $qr->fresh()->idempotency_key);
        Sale::create(['idempotency_key' => 'payment:1', 'total_amount' => 100, 'payment_method' => 'cash']);
        $this->assertSame(3, Sale::count());

        $this->expectException(UniqueConstraintViolationException::class);
        Sale::create(['idempotency_key' => 'payment:1', 'total_amount' => 100, 'payment_method' => 'cash']);
    }

    public function test_rollback_refuses_cross_namespace_duplicates_before_changing_schema(): void
    {
        foreach ([Sale::CHECKOUT_NAMESPACE, Sale::SETTLEMENT_NAMESPACE] as $namespace) {
            Sale::create(['key_namespace' => $namespace, 'idempotency_key' => 'payment:1', 'total_amount' => 100]);
        }
        try {
            $this->migration()->down();
            $this->fail('Rollback must refuse colliding key text.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot restore the shared sale key namespace', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('sales', 'key_namespace'));
        $this->assertSame(2, Sale::count());
        $this->expectException(UniqueConstraintViolationException::class);
        Sale::create(['key_namespace' => Sale::CHECKOUT_NAMESPACE, 'idempotency_key' => 'payment:1', 'total_amount' => 100]);
    }
}
