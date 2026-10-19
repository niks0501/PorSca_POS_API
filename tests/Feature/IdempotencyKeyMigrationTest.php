<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\CheckoutOperation;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IdempotencyKeyMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MySQL or MariaDB column collations and index semantics.');
        }
    }

    protected function tearDown(): void
    {
        foreach (['checkout_operations', 'payments', 'sales', 'checkouts'] as $table) {
            DB::table($table)->delete();
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_17_000000_harden_idempotency_keys.php');
    }

    private function records(string $key, ?string $checkoutId = null): array
    {
        $checkout = Checkout::create([
            'id' => (string) Str::uuid(), 'store_id' => 'migration-store', 'creation_key' => $key,
            'request_hash' => str_repeat('a', 64), 'items' => [], 'amount_centavos' => 500,
            'created_by' => User::factory()->create()->id,
        ]);
        $sale = Sale::create(['idempotency_key' => $key, 'total_amount' => 500, 'payment_method' => 'cash']);
        $payment = Payment::create(['idempotency_key' => $key, 'request_hash' => str_repeat('b', 64), 'amount' => 500]);
        $operation = CheckoutOperation::create([
            'checkout_id' => $checkoutId ?? $checkout->id, 'key' => $key, 'kind' => 'qrph',
            'request_hash' => str_repeat('c', 64), 'payment_id' => $payment->id,
        ]);

        return ['creation_key' => $checkout, 'sales.idempotency_key' => $sale,
            'payments.idempotency_key' => $payment, 'key' => $operation];
    }

    public function test_deployed_keys_and_unique_indexes_survive_case_sensitive_conversion(): void
    {
        $migration = $this->migration();
        $migration->down();
        $originals = $this->records('order:A');
        $snapshots = array_map(fn ($model) => $model->fresh()->getAttributes(), $originals);
        foreach ($originals as $name => $model) {
            $column = str_contains($name, '.') ? 'idempotency_key' : $name;
            $this->assertSame(1, $model->newQuery()->where($column, 'order:a')->count());
        }
        $migration->up();
        foreach ($originals as $name => $model) {
            $column = str_contains($name, '.') ? 'idempotency_key' : $name;
            $this->assertSame($snapshots[$name], $model->fresh()->getAttributes());
            $this->assertSame(0, $model->newQuery()->where($column, 'order:a')->count());
        }
        $this->records('order:a', $originals['creation_key']->id);
        foreach ($originals as $model) {
            $duplicate = $model->replicate();
            if ($duplicate instanceof Checkout) {
                $duplicate->id = (string) Str::uuid();
            }
            try {
                DB::transaction(fn () => $duplicate->save());
                $this->fail('An exact duplicate key must still violate its unique index.');
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(2, $model->newQuery()->count());
            }
        }
        try {
            $migration->down();
            $this->fail('Rollback must not merge case-distinct identities.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('operator reconciliation', $exception->getMessage());
        }
        foreach ($originals as $name => $model) {
            $column = str_contains($name, '.') ? 'idempotency_key' : $name;
            $this->assertSame(1, $model->newQuery()->where($column, 'order:A')->count());
        }
    }

    public function test_late_rollback_collision_preserves_all_columns_and_the_shared_key_table(): void
    {
        $originals = $this->records('order:A');
        Payment::create(['idempotency_key' => 'order:a', 'request_hash' => str_repeat('d', 64), 'amount' => 500]);
        try {
            $this->migration()->down();
            $this->fail('All key scopes must be checked before any schema change.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('operator reconciliation', $exception->getMessage());
        }
        foreach ($originals as $name => $model) {
            $column = str_contains($name, '.') ? 'idempotency_key' : $name;
            $this->assertSame(1, $model->newQuery()->where($column, 'order:A')->count());
        }
        DB::table('checkout_keys')->insert([['idempotency_key' => 'lock:A'], ['idempotency_key' => 'lock:a']]);
        $this->assertDatabaseCount('checkout_keys', 2);
    }
}
