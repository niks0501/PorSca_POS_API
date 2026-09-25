<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly ReservedStock $stock) {}

    public function create(string $idempotencyKey, array $requestedItems): Payment
    {
        $expirySeconds = (int) config('services.paymongo.qr_expiry_seconds', 1800);
        if ($expirySeconds < 60 || $expirySeconds > 9000) {
            throw new ApiException('Invalid QR expiration configuration.', 503);
        }
        $items = $this->normalizeItems($requestedItems);
        $requestHash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertSameRequest($existing, $requestHash);

            return $this->provision($existing);
        }

        try {
            $payment = DB::transaction(function () use ($idempotencyKey, $items, $requestHash, $expirySeconds): Payment {
                if (Sale::where('idempotency_key', $idempotencyKey)->exists()) {
                    throw new IdempotencyConflict;
                }
                $products = Product::query()->whereIn('id', array_keys($items))->where('active', true)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($products->count() !== count($items)) {
                    throw new ApiException('One or more products are unavailable.', 422, ['items' => ['Unavailable product.']]);
                }
                $total = 0;
                foreach ($items as $productId => $quantity) {
                    $product = $products->get($productId);
                    if ($product->currency !== 'PHP' || $product->price < 1 || $quantity > intdiv(4294967295, $product->price) || $total > 4294967295 - $product->price * $quantity) {
                        throw new ApiException('Invalid PHP payment amount.', 422, ['amount' => ['Positive PHP centavos within provider limits are required.']]);
                    }
                    $this->stock->lockAndCheck($productId, $quantity, $product->name);
                    $total += $product->price * $quantity;
                }
                $payment = Payment::create([
                    'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash,
                    'provider' => 'paymongo', 'status' => Payment::PENDING,
                    'amount' => $total, 'currency' => 'PHP', 'payment_method' => 'qrph',
                    'provider_operation_key' => (string) Str::uuid(),
                    'reservation_expires_at' => now()->addSeconds($expirySeconds),
                ]);
                foreach ($items as $productId => $quantity) {
                    $payment->items()->create(['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => $products->get($productId)->price]);
                }
                $payment->transactions()->create([
                    'type' => 'payment_created', 'status' => Payment::PENDING,
                    'amount' => $total, 'currency' => 'PHP', 'occurred_at' => now(),
                    'metadata' => ['payment_method' => 'qrph'],
                ]);

                return $payment;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSameRequest($existing, $requestHash);

            return $this->provision($existing);
        }

        return $this->provision($payment);
    }

    private function provision(Payment $payment): Payment
    {
        if ($payment->status === Payment::PENDING && $payment->qr_payload === null && $payment->reservation_expires_at?->isFuture()) {
            // DB commit precedes provider I/O; an uncertain POST can be retried with its original key.
            $created = $this->gateway->createQrPayment($payment->fresh());
            $payment->refresh();
            $payment->update([
                'provider_payment_id' => $created['provider_payment_id'],
                'qr_payload' => $created['qr_payload'],
                'checkout_url' => $created['checkout_url'],
                'provider_metadata' => $created['metadata'],
            ]);
        }

        return $payment->fresh()->load('items.product', 'sale');
    }

    private function normalizeItems(array $requestedItems): array
    {
        if ($requestedItems === []) {
            throw new ApiException('At least one item is required.', 422, ['items' => ['At least one item is required.']]);
        }
        $items = [];
        foreach ($requestedItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($productId < 1 || $quantity < 1 || $quantity > 10000 || ($items[$productId] ?? 0) > 10000 - $quantity) {
                throw new ApiException('Invalid item quantity.', 422, ['items' => ['Positive product ID and quantity up to 10000 per product required.']]);
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
