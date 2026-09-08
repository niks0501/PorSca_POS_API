<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\InsufficientCash;
use App\Exceptions\InsufficientStock;
use App\Models\Inventory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CashSaleService
{
    public function create(string $idempotencyKey, array $requestedItems, int $cashReceived): Sale
    {
        $items = $this->normalizeItems($requestedItems);
        $requestHash = hash('sha256', json_encode([
            'items' => $items,
            'cash_received' => $cashReceived,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($idempotencyKey, $items, $cashReceived, $requestHash): Sale {
                $existing = Sale::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $this->assertSameRequest($existing, $requestHash);

                    return $existing->fresh()->load('items.product');
                }

                if (Payment::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    throw new IdempotencyConflict;
                }

                $products = Product::query()
                    ->whereIn('id', array_keys($items))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $unavailable = [];
                foreach (array_keys($items) as $productId) {
                    $product = $products->get($productId);
                    if ($product === null || ! $product->active) {
                        $unavailable[] = $productId;
                    }
                }

                if ($unavailable !== []) {
                    throw new ApiException('One or more products are unavailable.', 422, [
                        'items' => ['Unavailable product IDs: '.implode(', ', $unavailable)],
                    ]);
                }

                $total = 0;
                foreach ($items as $productId => $quantity) {
                    $total += $products->get($productId)->price * $quantity;
                }

                if ($cashReceived < $total) {
                    throw new InsufficientCash($cashReceived, $total);
                }

                // Lock every inventory row in product-id order and validate it
                // immediately before any sale or stock write is made.
                $lockedInventory = [];
                foreach ($items as $productId => $quantity) {
                    $inventory = Inventory::query()
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    $product = $products->get($productId);
                    if ($inventory === null || $inventory->quantity < $quantity) {
                        throw new InsufficientStock($product->name);
                    }

                    $lockedInventory[$productId] = $inventory;
                }

                $changeAmount = $cashReceived - $total;
                $sale = Sale::create([
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'total_amount' => $total,
                    'currency' => 'PHP',
                    'status' => 'completed',
                    'payment_method' => 'cash',
                    'cash_received' => $cashReceived,
                    'change_amount' => $changeAmount,
                    'completed_at' => now(),
                ]);

                foreach ($items as $productId => $quantity) {
                    $sale->items()->create([
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $products->get($productId)->price,
                    ]);
                    $lockedInventory[$productId]->decrement('quantity', $quantity);
                }

                Transaction::create([
                    'sale_id' => $sale->id,
                    'type' => 'cash_sale',
                    'status' => 'completed',
                    'amount' => $total,
                    'currency' => $sale->currency,
                    'occurred_at' => now(),
                    'metadata' => [
                        'payment_method' => 'cash',
                        'cash_received' => $cashReceived,
                        'change_amount' => $changeAmount,
                    ],
                ]);

                return $sale->fresh()->load('items.product');
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // If two requests arrive together, the database's unique
            // idempotency key is the final exactly-once guard. Return the row
            // created by the winner after its transaction has rolled back ours.
            $existing = Sale::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameRequest($existing, $requestHash);

            return $existing->load('items.product');
        }
    }

    /** @param array<int, array<string, mixed>> $requestedItems */
    private function normalizeItems(array $requestedItems): array
    {
        if ($requestedItems === []) {
            throw new ApiException('At least one sale item is required.', 422, [
                'items' => ['At least one item is required.'],
            ]);
        }

        $items = [];
        foreach ($requestedItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId < 1 || $quantity < 1) {
                throw new ApiException('Each item must have a positive product_id and quantity.', 422, [
                    'items' => ['product_id and quantity must be positive integers.'],
                ]);
            }
            $items[$productId] = ($items[$productId] ?? 0) + $quantity;
        }

        ksort($items);

        return $items;
    }

    private function assertSameRequest(Sale $sale, string $requestHash): void
    {
        if ($sale->request_hash !== null && ! hash_equals((string) $sale->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }
    }
}
