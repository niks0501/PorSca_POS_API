<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'checkouts' => ['creation_key', ['store_id']],
        'checkout_operations' => ['key', ['checkout_id']],
        'sales' => ['idempotency_key', ['key_namespace']],
        'payments' => ['idempotency_key', []],
    ];

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::KEYS as $table => [$column]) {
                $this->collation($table, $column, 'utf8mb4_bin');
            }
        }
        Schema::create('checkout_keys', function (Blueprint $table): void {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_bin');
            $table->string('idempotency_key', 128)->primary();
        });
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $collations = [];
            foreach (self::KEYS as $table => [$column, $scope]) {
                $collation = DB::selectOne(
                    'SELECT TABLE_COLLATION AS collation_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [DB::getTablePrefix().$table],
                )->collation_name;
                $collations[$table] = $collation;
                if (DB::table($table)->selectRaw('1')->groupBy($scope)
                    ->groupByRaw('`'.$column.'` COLLATE '.$collation)->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new RuntimeException('Cannot restore case-insensitive idempotency keys without operator reconciliation.');
                }
            }
            foreach (self::KEYS as $table => [$column]) {
                $this->collation($table, $column, $collations[$table]);
            }
        }
        Schema::dropIfExists('checkout_keys');
    }

    private function collation(string $table, string $column, string $collation): void
    {
        Schema::table($table, function (Blueprint $table) use ($column, $collation): void {
            $table->string($column, 128)->charset('utf8mb4')->collation($collation)->change();
        });
    }
};
