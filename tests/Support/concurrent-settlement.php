<?php

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
config([
    'database.default' => 'race',
    'database.connections.race' => $input['connection'],
    'services.paymongo.mode' => 'sandbox',
    'services.paymongo.webhook_secret' => 'concurrency-fixture-secret',
]);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$attempts = 0;
DB::connection()->beforeStartingTransaction(function ($connection) use (&$attempts): void {
    if ($connection->transactionLevel() === 0) {
        $attempts++;
    }
});
$inspections = 0;
$gateway = Mockery::mock(PaymentGateway::class);
$gateway->shouldReceive('inspect')->andReturnUsing(function () use (&$inspections): array {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Provider inspection ran inside a transaction.');
    }
    $inspections++;

    return ['verified' => true, 'status' => Payment::PAID];
});
$app->instance(PaymentGateway::class, $gateway);
fwrite(STDOUT, "worker-ready\n");
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'continue') {
    throw new RuntimeException('Concurrency barrier was not released.');
}
$kernel = $app->make(Kernel::class);
$response = $kernel->handle(Request::create($input['path'], $input['method'], [], [], [], $input['server'], $input['body']));
fwrite(STDOUT, json_encode([
    'http_status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    'attempts' => $attempts,
    'inspections' => $inspections,
    'transaction_level' => DB::transactionLevel(),
], JSON_THROW_ON_ERROR)."\n");
