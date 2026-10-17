<?php

use App\Exceptions\InsufficientStock;
use App\Models\Payment;
use App\Services\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
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
$snapshotReady = false;
DB::listen(function () use (&$snapshotReady): void {
    if (! $snapshotReady && DB::transactionLevel() === 1) {
        $snapshotReady = true;
        Payment::query()->count();
        fwrite(STDOUT, "snapshot-ready\n");
        fflush(STDOUT);
    }
});

try {
    $payment = app(CheckoutService::class)->create('race-checkout-b', [
        ['product_id' => $input['product_id'], 'quantity' => 1],
    ]);
    $result = ['outcome' => 'payable', 'status' => $payment->status, 'qr_payload' => $payment->qr_payload];
} catch (InsufficientStock) {
    $result = ['outcome' => 'insufficient_stock'];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
