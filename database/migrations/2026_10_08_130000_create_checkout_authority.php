<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('store_id', 64);
            $table->string('creation_key', 128);
            $table->char('request_hash', 64);
            $table->string('state', 32)->default('open');
            $table->unsignedInteger('revision')->default(1);
            $table->json('items');
            $table->unsignedBigInteger('amount_centavos');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->unique()->constrained('sales')->restrictOnDelete();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'creation_key']);
            $table->index(['store_id', 'state', 'created_at']);
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUuid('checkout_id')->nullable()->constrained('checkouts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('verification_state', 32)->nullable();
            $table->string('first_verified_outcome', 32)->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->unsignedInteger('hold_seconds')->nullable();
            $table->unsignedInteger('qr_seconds')->nullable();
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignUuid('checkout_id')->nullable()->unique()->constrained('checkouts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('checkout_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('checkout_id')->constrained('checkouts')->restrictOnDelete();
            $table->string('key', 128);
            $table->string('kind', 32);
            $table->char('request_hash', 64);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['checkout_id', 'key']);
        });
        Schema::create('reconciliation_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('checkout_id')->constrained('checkouts')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('reason', 64);
            $table->string('state', 32)->default('open');
            $table->unsignedInteger('version')->default(1);
            $table->json('resolution')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'reason']);
        });
        Schema::create('checkout_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('checkout_id')->constrained('checkouts')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('reconciliation_cases')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 32);
            $table->string('type', 64);
            $table->json('evidence');
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        // Historical records must not be erased by an automatic rollback.
        if (DB::table('checkouts')->exists()) {
            throw new RuntimeException('Checkout authority rollback requires operator archival/reconciliation.');
        }
        Schema::dropIfExists('checkout_events');
        Schema::dropIfExists('reconciliation_cases');
        Schema::dropIfExists('checkout_operations');
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['checkout_id']);
            $table->dropUnique(['checkout_id']);
            $table->dropColumn('checkout_id');
            $table->dropConstrainedForeignId('created_by');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('checkout_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['verification_state', 'first_verified_outcome', 'qr_expires_at', 'hold_seconds', 'qr_seconds']);
        });
        Schema::dropIfExists('checkouts');
    }
};
