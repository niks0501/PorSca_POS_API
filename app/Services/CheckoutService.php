<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\InsufficientStock;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function create(string $idempotencyKey, array $requestedItems): Payment
    {
        $items = $this->normalizeItems($requestedItems);
        $requestHash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));

        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertSameRequest($existing, $requestHash);

            return $existing->load('items.product', 'sale');
        }

        try {
            $payment = DB::transaction(function () use ($idempotencyKey, $items, $requestHash): Payment {
                $products = Product::query()
                    ->with('inventory')
                    ->whereIn('id', array_keys($items))
                    ->where('active', true)
                    ->get()
                    ->keyBy('id');

                if (Sale::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    throw new IdempotencyConflict;
                }

                if ($products->count() !== count($items)) {
                    $missing = array_values(array_diff(array_keys($items), $products->keys()->all()));
                    throw new ApiException('One or more products are unavailable.', 422, [
                        'items' => ['Unavailable product IDs: '.implode(', ', $missing)],
                    ]);
                }

                $total = 0;
                foreach ($items as $productId => $quantity) {
                    $product = $products->get($productId);
                    if ($product->inventory === null || $product->inventory->quantity < $quantity) {
                        throw new InsufficientStock($product->name);
                    }
                    $total += $product->price * $quantity;
                }

                $payment = Payment::create([
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'provider' => 'paymongo',
                    'status' => Payment::PENDING,
                    'amount' => $total,
                    'currency' => 'PHP',
                    'payment_method' => 'qrph',
                ]);

                foreach ($items as $productId => $quantity) {
                    $product = $products->get($productId);
                    $payment->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_price' => $product->price,
                    ]);
                }

                $gatewayPayment = $this->gateway->createQrPayment($payment->load('items'));
                $payment->update([
                    'provider_payment_id' => $gatewayPayment['provider_payment_id'],
                    'qr_payload' => $gatewayPayment['qr_payload'],
                    'checkout_url' => $gatewayPayment['checkout_url'],
                    'provider_metadata' => $gatewayPayment['metadata'],
                ]);
                $payment->transactions()->create([
                    'type' => 'payment_created',
                    'status' => Payment::PENDING,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'occurred_at' => now(),
                    'metadata' => ['payment_method' => 'qrph'],
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent retry may win the unique idempotency key between
            // the read above and the insert. Reuse that committed payment
            // instead of leaking a database error to the client.
            $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameRequest($existing, $requestHash);

                return $existing->load('items.product', 'sale');
            }

            throw $exception;
        }

        return $payment->fresh()->load('items.product', 'sale');
    }

    private function normalizeItems(array $requestedItems): array
    {
        if ($requestedItems === []) {
            throw new ApiException('At least one item is required.', 422, [
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

    private function assertSameRequest(Payment $payment, string $requestHash): void
    {
        if (! hash_equals((string) $payment->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }
    }
}
