<?php

use App\Exceptions\IdempotencyConflict;
use App\Exceptions\InsufficientStock;
use App\Services\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config([
    'database.default' => 'race',
    'database.connections.race' => $input['connection'],
    'services.paymongo.mode' => 'sandbox',
    'services.paymongo.secret_key' => '',
]);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
DB::listen(function (QueryExecuted $query): void {
    if (DB::transactionLevel() === 1 && str_contains($query->sql, 'from `sales`')) {
        fwrite(STDOUT, "claim-check-ready\n");
        fflush(STDOUT);
    }
});

try {
    $payment = app(CheckoutService::class)->create($input['idempotency_key'], [
        ['product_id' => $input['product_id'], 'quantity' => $input['quantity']],
    ]);
    $result = ['outcome' => 'payable', 'id' => $payment->id, 'status' => $payment->status, 'qr_payload' => $payment->qr_payload];
} catch (InsufficientStock) {
    $result = ['outcome' => 'insufficient_stock'];
} catch (IdempotencyConflict) {
    $result = ['outcome' => 'idempotency_conflict'];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
