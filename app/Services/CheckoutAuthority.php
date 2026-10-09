<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Domain\CheckoutRules;
use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\PaymentGatewayException;
use App\Models\Checkout;
use App\Models\CheckoutOperation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable single-store checkout actions. Provider I/O is always outside transactions. */
class CheckoutAuthority
{
    public function __construct(
        private readonly ReservedStock $stock,
        private readonly CashSaleService $cash,
        private readonly PaymentGateway $gateway,
        private readonly CheckoutOutcomes $outcomes,
    ) {}

    public function create(string $key, array $requested, User $actor): Checkout
    {
        $items = [];
        foreach ($requested as $item) {
            $id = $item['productId'];
            $items[$id] = ($items[$id] ?? 0) + $item['quantity'];
            if ($items[$id] > 10000) {
                throw new ApiException('Quantity exceeds 10000 per product.', 422);
            }
        }
        ksort($items);
        $hash = $this->hash($items);
        $store = (string) config('checkout.store_id');
        $existing = Checkout::where('store_id', $store)->where('creation_key', $key)->first();
        if ($existing !== null) {
            $this->sameHash($existing->request_hash, $hash);

            return $existing;
        }
        try {
            return DB::transaction(function () use ($key, $items, $hash, $store, $actor): Checkout {
                [$snapshot, $amount] = $this->quote($items);
                $checkout = Checkout::create([
                    'id' => (string) Str::uuid(), 'store_id' => $store, 'creation_key' => $key,
                    'request_hash' => $hash, 'items' => $snapshot, 'amount_centavos' => $amount,
                    'created_by' => $actor->id,
                ]);
                $this->event($checkout, 'checkout_created', $actor, ['revision' => 1, 'items' => $snapshot, 'amountCentavos' => $amount]);

                return $checkout->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Checkout::where('store_id', $store)->where('creation_key', $key)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->sameHash($existing->request_hash, $hash);

            return $existing;
        }
    }

    public function lock(Checkout $checkout): Checkout
    {
        // This order is shared with legacy stock, reservation and settlement writers.
        $this->stock->lockSettlementResources(array_column($checkout->items, 'productId'));

        return Checkout::whereKey($checkout->id)->lockForUpdate()->firstOrFail();
    }

    public function recover(Checkout $checkout, User $actor): Checkout
    {
        return DB::transaction(function () use ($checkout, $actor): Checkout {
            $checkout = $this->lock($checkout);
            $this->event($checkout, 'checkout_recovered', $actor);

            return $checkout;
        }, 3);
    }

    public function revalidate(Checkout $checkout, User $actor): Checkout
    {
        return DB::transaction(function () use ($checkout, $actor): Checkout {
            $checkout = $this->lock($checkout);
            $this->assertOpen($checkout);
            $quantities = array_column($checkout->items, 'quantity', 'productId');
            [$items, $amount] = $this->quote($quantities);
            $checkout->update(['items' => $items, 'amount_centavos' => $amount, 'revision' => $checkout->revision + 1]);
            $this->event($checkout, 'checkout_revalidated', $actor, ['revision' => $checkout->revision, 'items' => $items, 'amountCentavos' => $amount]);

            return $checkout;
        }, 3);
    }

    public function abandon(Checkout $checkout, string $reason, User $actor): Checkout
    {
        return DB::transaction(function () use ($checkout, $reason, $actor): Checkout {
            $checkout = $this->lock($checkout);
            $this->assertOpen($checkout);
            $checkout->update(['state' => 'abandoned', 'abandoned_at' => now()]);
            $this->event($checkout, 'checkout_abandoned', $actor, ['reason' => $reason]);

            return $checkout;
        }, 3);
    }

    /** @return array{Checkout, bool} */
    public function tender(Checkout $checkout, string $key, string $kind, array $payload, User $actor): array
    {
        $hash = $this->hash(['kind' => $kind, ...$payload]);
        [$checkout, $created, $paymentId] = DB::transaction(function () use ($checkout, $key, $kind, $payload, $hash, $actor): array {
            $checkout = $this->lock($checkout);
            $operation = CheckoutOperation::where('checkout_id', $checkout->id)->where('key', $key)->lockForUpdate()->first();
            if ($operation !== null) {
                $this->sameHash($operation->request_hash, $hash);
                $this->event($checkout, 'tender_replayed', $actor, ['kind' => $kind], $operation->payment_id);

                return [$checkout, false, $operation->payment_id];
            }
            $this->assertOpen($checkout);
            if ($payload['revision'] !== $checkout->revision || $payload['acceptedAmountCentavos'] !== $checkout->amount_centavos) {
                throw new ApiException('Accept the current checkout quote before tender.', 409);
            }
            $quantities = array_column($checkout->items, 'quantity', 'productId');
            [$currentItems, $amount] = $this->quote($quantities);
            // MySQL JSON canonicalizes object key order; compare snapshot values, not serialization order.
            if ($currentItems != $checkout->items || $amount !== $checkout->amount_centavos) {
                throw new ApiException('Prices changed; revalidate and explicitly accept the revised quote.', 409, ['revalidationRequired' => true]);
            }
            $internalKey = 'v3:'.Str::uuid();
            if ($kind === 'cash') {
                $sale = $this->cash->create($internalKey, array_map(fn ($item) => [
                    'product_id' => $item['productId'], 'quantity' => $item['quantity'],
                ], $checkout->items), $payload['cashReceivedCentavos']);
                $sale->update(['checkout_id' => $checkout->id, 'created_by' => $actor->id]);
                $payment = Payment::create([
                    'checkout_id' => $checkout->id, 'created_by' => $actor->id, 'idempotency_key' => $internalKey,
                    'request_hash' => $hash, 'provider' => 'cash', 'payment_method' => 'cash',
                    'amount' => $amount, 'currency' => 'PHP', 'status' => Payment::PAID,
                    'verification_state' => 'paid', 'first_verified_outcome' => 'paid', 'sale_id' => $sale->id, 'paid_at' => now(),
                ]);
                $checkout->update(['state' => 'completed', 'sale_id' => $sale->id]);
            } else {
                $qrSeconds = (int) config('services.paymongo.qr_expiry_seconds');
                $holdSeconds = (int) config('checkout.hold_seconds');
                if ($qrSeconds < 60 || $qrSeconds > 9000 || $holdSeconds !== $qrSeconds) {
                    throw new ApiException('Reservation and QR durations must be aligned (60–9000 seconds).', 503);
                }
                if ($amount < (int) config('checkout.qr_minimum_centavos', 100)) {
                    throw new ApiException('Below the provisional staging QR minimum; Cash remains eligible.', 422);
                }
                $payment = Payment::create([
                    'checkout_id' => $checkout->id, 'created_by' => $actor->id, 'idempotency_key' => $internalKey,
                    'request_hash' => $hash, 'provider' => 'paymongo', 'payment_method' => 'qrph',
                    'provider_operation_key' => (string) Str::uuid(), 'amount' => $amount, 'currency' => 'PHP',
                    'status' => Payment::PENDING, 'verification_state' => 'pending',
                    'reservation_expires_at' => now()->addSeconds($holdSeconds),
                    'qr_expires_at' => now()->addSeconds($qrSeconds), 'hold_seconds' => $holdSeconds, 'qr_seconds' => $qrSeconds,
                ]);
                $checkout->update(['state' => 'payment_unresolved']);
            }
            foreach ($checkout->items as $item) {
                $payment->items()->create(['product_id' => $item['productId'], 'quantity' => $item['quantity'], 'unit_price' => $item['unitPriceCentavos']]);
            }
            CheckoutOperation::create(['checkout_id' => $checkout->id, 'key' => $key, 'kind' => $kind, 'request_hash' => $hash, 'payment_id' => $payment->id]);
            $this->event($checkout, 'tender_started', $actor, ['kind' => $kind, 'amountCentavos' => $amount], $payment->id);

            return [$checkout, true, $payment->id];
        }, 3);

        $payment = Payment::findOrFail($paymentId);
        if ($kind === 'qrph' && $payment->first_verified_outcome === null && $payment->qr_payload === null) {
            $this->provision($payment, $actor);
        }

        return [$checkout->fresh(), $created];
    }

    private function provision(Payment $payment, User $actor): void
    {
        // Never extend a reservation or make a new provider operation during recovery.
        if (! $payment->qr_expires_at?->isFuture()) {
            return;
        }
        try {
            $result = $this->gateway->createQrPayment($payment->fresh());
        } catch (PaymentGatewayException) {
            $this->outcomes->unknown($payment, $actor, 'provision_unavailable');

            return;
        }
        DB::transaction(function () use ($payment, $result, $actor): void {
            $checkout = $this->lock(Checkout::findOrFail($payment->checkout_id));
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            // A webhook may have settled while provider creation was in flight.
            if ($payment->first_verified_outcome === null) {
                $payment->update([
                    'provider_payment_id' => $result['provider_payment_id'], 'qr_payload' => $result['qr_payload'],
                    'checkout_url' => null,
                    'provider_metadata' => ['driver' => data_get($result, 'metadata.driver')],
                ]);
            }
            $this->event($checkout, 'qr_provision_observed', $actor, [], $payment->id);
        }, 3);
    }

    private function quote(array $quantities): array
    {
        $products = Product::whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $snapshot = [];
        $total = 0;
        foreach ($quantities as $id => $quantity) {
            $product = $products->get($id);
            if ($product === null || ! $product->active || $product->currency !== 'PHP') {
                throw new ApiException('A product is unavailable.', 422);
            }
            try {
                $total = CheckoutRules::lineTotal($total, $product->price, $quantity);
            } catch (\InvalidArgumentException $exception) {
                throw new ApiException($exception->getMessage(), 422);
            }
            $this->stock->lockAndCheck($id, $quantity, $product->name);
            $snapshot[] = ['productId' => (int) $id, 'name' => $product->name, 'quantity' => $quantity, 'unitPriceCentavos' => $product->price];
        }

        return [$snapshot, $total];
    }

    private function assertOpen(Checkout $checkout): void
    {
        $blocked = $checkout->attempts()->whereIn('verification_state', ['pending', 'unknown', 'contradiction', 'paid'])->lockForUpdate()->exists();
        if (! CheckoutRules::canTender($checkout->state, $checkout->sale_id !== null, $blocked)) {
            throw new ApiException('Checkout is locked against new tender or abandonment.', 409);
        }
    }

    public function event(Checkout $checkout, string $type, ?User $actor, array $evidence = [], ?int $paymentId = null, ?int $caseId = null): void
    {
        $checkout->events()->create([
            'type' => $type, 'actor_type' => $actor === null ? 'provider' : 'operator', 'actor_id' => $actor?->id,
            'payment_id' => $paymentId, 'case_id' => $caseId, 'evidence' => $evidence, 'occurred_at' => now(),
        ]);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function sameHash(string $original, string $supplied): void
    {
        if (! hash_equals($original, $supplied)) {
            throw new IdempotencyConflict;
        }
    }
}
