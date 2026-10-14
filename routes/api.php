<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CheckoutAuthorityController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\EnsureCheckoutContract;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::post('webhooks/paymongo', [WebhookController::class, 'paymongo'])->name('api.v1.webhooks.paymongo');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');

        // Read and sales surface shared by every active signed-in user.
        Route::get('products', [ProductController::class, 'index'])->name('api.v1.products.index');
        Route::get('products/barcode/{barcode}', [ProductController::class, 'byBarcode'])->name('api.v1.products.barcode');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('api.v1.products.show');

        Route::get('inventory', [InventoryController::class, 'index'])->name('api.v1.inventory.index');
        Route::get('inventory/{product}', [InventoryController::class, 'show'])->name('api.v1.inventory.show');
        Route::get('stock', [InventoryController::class, 'index'])->name('api.v1.stock.index');
        Route::get('stock/{product}', [InventoryController::class, 'show'])->name('api.v1.stock.show');

        Route::post('sales', [CheckoutController::class, 'cash'])->name('api.v1.sales.store');
        Route::post('sales/cash', [CheckoutController::class, 'cash'])->name('api.v1.sales.cash');
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

        // Admin-only surface: catalog, stock, and cashier accounts.
        Route::middleware('role:admin')->group(function (): void {
            Route::post('products', [ProductController::class, 'store'])->name('api.v1.products.store');
            Route::patch('products/{product}', [ProductController::class, 'update'])->name('api.v1.products.update');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('api.v1.products.replace');
            Route::patch('products/{product}/stock', [ProductController::class, 'updateStock'])->name('api.v1.products.stock.update');
            Route::put('products/{product}/stock', [ProductController::class, 'updateStock'])->name('api.v1.products.stock.replace');

            Route::patch('inventory/{product}', [ProductController::class, 'updateStock'])->name('api.v1.inventory.update');
            Route::put('inventory/{product}', [ProductController::class, 'updateStock'])->name('api.v1.inventory.replace');
            Route::patch('stock/{product}', [ProductController::class, 'updateStock'])->name('api.v1.stock.update');
            Route::put('stock/{product}', [ProductController::class, 'updateStock'])->name('api.v1.stock.replace');

            Route::get('users', [UserController::class, 'index'])->name('api.v1.users.index');
            Route::post('users', [UserController::class, 'store'])->name('api.v1.users.store');
            Route::patch('users/{user}', [UserController::class, 'update'])->name('api.v1.users.update');
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('api.v1.users.deactivate');
        });
    });
});

// v3 is an additive contract surface; the deployed URL base remains /api/v1.
Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'role:admin,cashier', EnsureCheckoutContract::class])->group(function (): void {
    Route::get('checkout-session', [CheckoutAuthorityController::class, 'session']);
    Route::get('checkouts', [CheckoutAuthorityController::class, 'index']);
    Route::post('checkouts', [CheckoutAuthorityController::class, 'store']);
    Route::get('checkouts/{checkout}', [CheckoutAuthorityController::class, 'show']);
    Route::post('checkouts/{checkout}/recover', [CheckoutAuthorityController::class, 'recover']);
    Route::post('checkouts/{checkout}/revalidate', [CheckoutAuthorityController::class, 'revalidate']);
    Route::post('checkouts/{checkout}/abandon', [CheckoutAuthorityController::class, 'abandon']);
    Route::post('checkouts/{checkout}/cash', [CheckoutAuthorityController::class, 'cash']);
    Route::get('checkouts/{checkout}/attempts', [CheckoutAuthorityController::class, 'attempts']);
    Route::post('checkouts/{checkout}/attempts', [CheckoutAuthorityController::class, 'attempt']);
    Route::get('checkouts/{checkout}/attempts/{attempt}', [CheckoutAuthorityController::class, 'attemptShow']);
    Route::get('checkouts/{checkout}/attempts/{attempt}/reservation', [CheckoutAuthorityController::class, 'reservation']);
    Route::post('checkouts/{checkout}/attempts/{attempt}/refresh', [CheckoutAuthorityController::class, 'refresh']);
    Route::middleware('role:admin')->group(function (): void {
        Route::post('checkouts/{checkout}/attempts/{attempt}/simulation-capability', [CheckoutAuthorityController::class, 'simulation']);
        Route::get('reconciliation-cases', [CheckoutAuthorityController::class, 'cases']);
        Route::get('reconciliation-cases/{case}', [CheckoutAuthorityController::class, 'caseShow']);
        Route::patch('reconciliation-cases/{case}', [CheckoutAuthorityController::class, 'caseUpdate']);
    });
});
