<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('request_hash', 64)->nullable();
            $table->string('payment_method', 32)->default('qrph');
            $table->unsignedInteger('cash_received')->nullable();
            $table->unsignedInteger('change_amount')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn([
                'request_hash',
                'payment_method',
                'cash_received',
                'change_amount',
            ]);
        });
    }
};
