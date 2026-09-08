<?php

use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::post('webhooks/paymongo', [WebhookController::class, 'paymongo'])->name('api.v1.webhooks.paymongo');

    Route::middleware('api.token')->group(function (): void {
        Route::get('products', [ProductController::class, 'index'])->name('api.v1.products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('api.v1.products.show');

        Route::get('inventory', [InventoryController::class, 'index'])->name('api.v1.inventory.index');
        Route::get('inventory/{product}', [InventoryController::class, 'show'])->name('api.v1.inventory.show');
        Route::get('stock', [InventoryController::class, 'index'])->name('api.v1.stock.index');
        Route::get('stock/{product}', [InventoryController::class, 'show'])->name('api.v1.stock.show');

        Route::post('sales/checkout', [CheckoutController::class, 'store'])->name('api.v1.sales.checkout');
        Route::get('sales', [SaleController::class, 'index'])->name('api.v1.sales.index');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->name('api.v1.sales.show');

        // POST /payments is a contract alias for mobile clients that model a
        // checkout as a payment creation operation.
        Route::post('payments', [CheckoutController::class, 'store'])->name('api.v1.payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('api.v1.payments.show');
        Route::get('payments/{payment}/status', [PaymentController::class, 'show'])->name('api.v1.payments.status');
        Route::post('payments/{payment}/refresh', [PaymentController::class, 'refresh'])->name('api.v1.payments.refresh');
        Route::post('payments/{payment}/status', [PaymentController::class, 'refresh'])->name('api.v1.payments.status.refresh');

        Route::get('transactions', [TransactionController::class, 'index'])->name('api.v1.transactions.index');
        Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('api.v1.transactions.show');
    });
});
