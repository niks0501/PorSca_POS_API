<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('status', 24);
            $table->unsignedInteger('amount')->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->string('provider_event_id', 128)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['payment_id', 'type']);
            $table->index(['sale_id', 'type']);
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_event_id', 128)->unique();
            $table->string('event_type', 64);
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('transactions');
    }
};
