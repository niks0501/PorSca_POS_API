<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider_operation_key', 128)->nullable()->unique();
            $table->string('provider_method_id', 128)->nullable()->unique();
            $table->string('provider_resource_id', 128)->nullable()->unique();
            $table->timestamp('reservation_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['provider_operation_key']);
            $table->dropUnique(['provider_method_id']);
            $table->dropUnique(['provider_resource_id']);
            $table->dropIndex(['reservation_expires_at']);
            $table->dropColumn(['provider_operation_key', 'provider_method_id', 'provider_resource_id', 'reservation_expires_at']);
        });
    }
};
