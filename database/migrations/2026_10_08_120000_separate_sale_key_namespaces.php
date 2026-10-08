<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('key_namespace', 24)->default('checkout');
        });

        // Existing non-cash sales were assigned internal payment:<id> keys.
        // Preserve their keys and public sale IDs, but remove them from checkout identity.
        DB::table('sales')->where('payment_method', '!=', 'cash')->update(['key_namespace' => 'settlement']);

        Schema::table('sales', function (Blueprint $table): void {
            $table->unique(['key_namespace', 'idempotency_key'], 'sales_namespace_key_unique');
            $table->dropUnique(['idempotency_key']);
        });
    }

    public function down(): void
    {
        // A cash and settled QR sale may now legitimately share the key text.
        // Refuse an unsafe rollback before changing any indexes or losing identity.
        if (DB::table('sales')->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore the shared sale key namespace while duplicate key text exists.');
        }

        Schema::table('sales', function (Blueprint $table): void {
            $table->unique('idempotency_key');
            $table->dropUnique('sales_namespace_key_unique');
            $table->dropColumn('key_namespace');
        });
    }
};
