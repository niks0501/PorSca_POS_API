<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\InsufficientStock;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class PaymentSettlementService
{
    public function __construct(private readonly ReservedStock $stock) {}

    public function settle(Payment|int $payment, string $status, ?string $providerEventId = null, array $metadata = []): Payment
    {
        $status = strtolower($status);
        if (! in_array($status, [Payment::PENDING, ...Payment::terminalStatuses()], true)) {
            throw new ApiException('Unsupported payment status.', 422, ['status' => ['Unsupported payment status.']]);
        }

        return DB::transaction(function () use ($payment, $status, $providerEventId, $metadata): Payment {
            $paymentModel = Payment::query()->whereKey($payment instanceof Payment ? $payment->getKey() : $payment)->lockForUpdate()->firstOrFail();

            if ($providerEventId !== null && Transaction::where('provider_event_id', $providerEventId)->exists()) {
                return $paymentModel->fresh()->load('items.product', 'sale');
            }

            // A terminal result is never downgraded or reprocessed. This is the
            // primary guard against duplicate webhook/payment delivery.
            if (in_array($paymentModel->status, [Payment::PAID, Payment::PAID_UNFULFILLED], true)
                || (in_array($paymentModel->status, Payment::terminalStatuses(), true) && $status !== Payment::PAID)) {
                return $paymentModel->fresh()->load('items.product', 'sale');
            }

            if ($status === Payment::PENDING) {
                return $paymentModel->fresh()->load('items.product', 'sale');
            }

            if (in_array($status, [Payment::FAILED, Payment::CANCELLED, Payment::EXPIRED], true)) {
                $paymentModel->update([
                    'status' => $status,
                    'failure_reason' => $metadata['failure_reason'] ?? $status,
                ]);
                $this->recordTransaction($paymentModel, match ($status) {
                    Payment::CANCELLED => 'payment_cancelled',
                    Payment::EXPIRED => 'payment_expired',
                    default => 'payment_failed',
                }, $status, $providerEventId, $metadata);

                return $paymentModel->fresh()->load('items.product', 'sale');
            }

            $items = $paymentModel->items()->with('product')->orderBy('product_id')->get();
            $lockedInventory = [];
            try {
                foreach ($items as $item) {
                    $lockedInventory[$item->product_id] = $this->stock->lockAndCheck($item->product_id, $item->quantity, $item->product->name, $paymentModel->id);
                }
            } catch (InsufficientStock $exception) {
                // Money was received: surface an explicit reconciliation exception, never a failed charge.
                $paymentModel->update([
                    'status' => Payment::PAID_UNFULFILLED,
                    'paid_at' => now(),
                    'failure_reason' => 'stock_reconciliation_required',
                ]);
                $this->recordTransaction($paymentModel, 'payment_paid_unfulfilled', Payment::PAID_UNFULFILLED, $providerEventId, [
                    'reason' => 'stock_reconciliation_required',
                ]);

                return $paymentModel->fresh()->load('items.product', 'sale');
            }

            $sale = Sale::create([
                'idempotency_key' => 'payment:'.$paymentModel->id,
                'total_amount' => $paymentModel->amount,
                'currency' => $paymentModel->currency,
                'status' => 'completed',
                'payment_method' => $paymentModel->payment_method,
                'completed_at' => now(),
            ]);

            foreach ($items as $item) {
                $sale->items()->create([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ]);
                $lockedInventory[$item->product_id]->decrement('quantity', $item->quantity);
            }

            $paymentModel->update([
                'status' => Payment::PAID,
                'sale_id' => $sale->id,
                'paid_at' => now(),
                'failure_reason' => null,
            ]);
            $this->recordTransaction($paymentModel, 'payment_succeeded', Payment::PAID, $providerEventId, $metadata, $sale);

            return $paymentModel->fresh()->load('items.product', 'sale.items.product');
        });
    }

    private function recordTransaction(
        Payment $payment,
        string $type,
        string $status,
        ?string $providerEventId,
        array $metadata = [],
        ?Sale $sale = null,
    ): void {
        if ($providerEventId !== null && Transaction::where('provider_event_id', $providerEventId)->exists()) {
            return;
        }

        $payment->transactions()->create([
            'sale_id' => $sale?->id,
            'type' => $type,
            'status' => $status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'provider_event_id' => $providerEventId,
            'occurred_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
