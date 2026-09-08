<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->string('request_hash', 64);
            $table->string('provider', 32)->default('paymongo');
            $table->string('provider_payment_id', 128)->nullable()->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PHP');
            $table->string('payment_method', 32)->default('qrph');
            $table->text('qr_payload')->nullable();
            $table->text('checkout_url')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->json('provider_metadata')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->timestamps();
            $table->unique(['payment_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
        Schema::dropIfExists('payments');
    }
};
