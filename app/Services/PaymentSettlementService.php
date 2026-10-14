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
        $linked = $payment instanceof Payment ? $payment : Payment::findOrFail($payment);
        if ($linked->checkout_id !== null) {
            // A status string alone can never authorize the v3 checkout authority.
            return app(CheckoutOutcomes::class)->observe($linked, $metadata['inspection'] ?? ['verified' => false], $providerEventId);
        }
        $status = strtolower($status);
        if (! in_array($status, [Payment::PENDING, ...Payment::terminalStatuses()], true)) {
            throw new ApiException('Unsupported payment status.', 422, ['status' => ['Unsupported payment status.']]);
        }

        // Lock products and inventory before payment rows, matching stock edits and reservation reads.
        // Retry the whole unit; a webhook caller must also retry its outer transaction.
        $paymentId = $payment instanceof Payment ? $payment->getKey() : $payment;

        return DB::transaction(function () use ($paymentId, $status, $providerEventId, $metadata): Payment {
            if ($status === Payment::PAID) {
                $productIds = Payment::query()->whereKey($paymentId)->firstOrFail()
                    ->items()->orderBy('product_id')->pluck('product_id')->all();
                $this->stock->lockSettlementResources($productIds);
            }
            $paymentModel = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($providerEventId !== null && Transaction::where('provider_event_id', $providerEventId)->exists()) {
                return $this->loadCurrentPaymentRelations($paymentModel);
            }

            // A terminal result is never downgraded or reprocessed. This is the
            // primary guard against duplicate webhook/payment delivery.
            if (in_array($paymentModel->status, [Payment::PAID, Payment::PAID_UNFULFILLED], true)
                || (in_array($paymentModel->status, Payment::terminalStatuses(), true) && $status !== Payment::PAID)) {
                return $this->loadCurrentPaymentRelations($paymentModel);
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
                'key_namespace' => Sale::SETTLEMENT_NAMESPACE,
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
        }, 3);
    }

    private function loadCurrentPaymentRelations(Payment $payment): Payment
    {
        $payment->load('items.product');
        $sale = $payment->sale_id === null
            ? null
            : Sale::query()->whereKey($payment->sale_id)->lockForUpdate()->first()?->load('items.product');

        return $payment->setRelation('sale', $sale);
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
