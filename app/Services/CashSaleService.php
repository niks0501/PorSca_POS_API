<?php

namespace App\Services;

use App\Domain\CheckoutRules;
use App\Domain\UnsignedIntegerBound;
use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\InsufficientCash;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashSaleService
{
    public function __construct(private readonly ReservedStock $stock) {}

    public function create(string $idempotencyKey, array $requestedItems, int $cashReceived): Sale
    {
        if (! UnsignedIntegerBound::contains($cashReceived)) {
            throw new ApiException('Invalid cash amount.', 422, [
                'cash_received' => ['Cash must be within the supported integer centavo range.'],
            ]);
        }

        $items = $this->normalizeItems($requestedItems);
        $requestHash = hash('sha256', json_encode([
            'items' => $items,
            'cash_received' => $cashReceived,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($idempotencyKey, $items, $cashReceived, $requestHash): Sale {
            DB::table('checkout_keys')->upsert(
                [['idempotency_key' => $idempotencyKey]], ['idempotency_key'], ['idempotency_key'],
            );
            $products = Product::query()
                ->whereIn('id', array_keys($items))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $existing = Sale::query()
                ->where('key_namespace', Sale::CHECKOUT_NAMESPACE)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertSameRequest($existing, $requestHash);

                return $existing->fresh()->load('items.product');
            }

            if (Payment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->exists()) {
                throw new IdempotencyConflict;
            }

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
                try {
                    $total = CheckoutRules::lineTotal($total, $products->get($productId)->price, $quantity);
                } catch (InvalidArgumentException) {
                    throw new ApiException('Invalid sale total.', 422, [
                        'total_amount' => ['The sale total must be within the supported integer centavo range.'],
                    ]);
                }
            }

            if ($cashReceived < $total) {
                throw new InsufficientCash($cashReceived, $total);
            }

            try {
                $changeAmount = UnsignedIntegerBound::difference($cashReceived, $total);
            } catch (InvalidArgumentException) {
                throw new ApiException('Invalid change amount.', 422, [
                    'change_amount' => ['The change amount must be within the supported integer centavo range.'],
                ]);
            }

            // Lock every inventory row in product-id order and validate it
            // immediately before any sale or stock write is made.
            $lockedInventory = [];
            foreach ($items as $productId => $quantity) {
                $lockedInventory[$productId] = $this->stock->lockAndCheck($productId, $quantity, $products->get($productId)->name);
            }

            $sale = Sale::create([
                'key_namespace' => Sale::CHECKOUT_NAMESPACE,
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
        if ($sale->payment_method !== 'cash'
            || ($sale->request_hash !== null && ! hash_equals((string) $sale->request_hash, $requestHash))) {
            throw new IdempotencyConflict;
        }
    }
}
