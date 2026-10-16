<?php

use App\Exceptions\IdempotencyConflict;
use App\Services\CashSaleService;
use App\Services\CheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
config([
    'database.default' => 'race',
    'database.connections.race' => $input['connection'],
    'services.paymongo.mode' => 'sandbox',
    'services.paymongo.secret_key' => '',
]);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$paused = false;
DB::listen(function (QueryExecuted $query) use (&$paused): void {
    if (! $paused && str_contains($query->sql, 'from `products`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        fwrite(STDOUT, "claim-checked\n");
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Tender barrier was not released.');
        }
    }
});

try {
    $items = [['product_id' => $input['product_id'], 'quantity' => 1]];
    if ($input['tender'] === 'cash') {
        app(CashSaleService::class)->create('cross-tender-key', $items, 500);
    } else {
        app(CheckoutService::class)->create('cross-tender-key', $items);
    }
    $result = ['outcome' => 'created'];
} catch (IdempotencyConflict) {
    $result = ['outcome' => 'conflict'];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
