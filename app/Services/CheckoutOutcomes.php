<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Domain\CheckoutRules;
use App\Exceptions\InsufficientStock;
use App\Exceptions\PaymentGatewayException;
use App\Models\Checkout;
use App\Models\Payment;
use App\Models\ReconciliationCase;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Only verified, attempt-correlated provider evidence can authorize QR finality. */
class CheckoutOutcomes
{
    public function __construct(private readonly ReservedStock $stock, private readonly PaymentGateway $gateway) {}

    public function refresh(Payment $payment, User $actor): Payment
    {
        try {
            $inspection = $this->gateway->inspect($payment);
        } catch (PaymentGatewayException) {
            return $this->unknown($payment, $actor, 'verification_unavailable');
        }

        return $this->observe($payment, $inspection, null, $actor);
    }

    public function unknown(Payment $payment, ?User $actor, string $reason): Payment
    {
        return $this->observe($payment, ['verified' => false, 'reason' => $reason], null, $actor);
    }

    public function observe(Payment $payment, array $inspection, ?string $eventId = null, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $inspection, $eventId, $actor): Payment {
            $this->stock->lockSettlementResources($payment->items()->orderBy('product_id')->pluck('product_id')->all());
            $checkout = Checkout::whereKey($payment->checkout_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($eventId !== null && $checkout->events()->where('payment_id', $payment->id)
                ->where('type', 'provider_observed')->where('evidence->eventId', $eventId)
                ->where('evidence->verified', true)->lockForUpdate()->exists()) {
                return $payment;
            }
            $status = $inspection['status'] ?? null;
            $classification = CheckoutRules::observation($inspection);
            $specific = ($inspection['attempt_specific'] ?? false) === true;
            $accepted = $classification !== 'unknown';
            // Persist an allow-list, never raw provider data, URLs, credentials or client keys.
            $evidence = [
                'eventId' => $eventId, 'providerReference' => $payment->provider_payment_id,
                'resourceReference' => is_string($inspection['resource_id'] ?? null) ? $inspection['resource_id'] : null,
                'outcome' => $accepted ? $status : 'unknown', 'verified' => $accepted,
                'attemptSpecific' => $specific, 'firstOutcome' => $payment->first_verified_outcome,
                'failureClass' => $accepted ? null : (in_array($inspection['reason'] ?? null, ['verification_unavailable', 'provision_unavailable'], true)
                    ? $inspection['reason'] : 'unverified_or_uncorrelated'),
            ];
            $checkout->events()->create([
                'payment_id' => $payment->id, 'type' => 'provider_observed',
                'actor_type' => $actor === null ? 'provider' : 'operator', 'actor_id' => $actor?->id,
                'evidence' => $evidence, 'occurred_at' => now(),
            ]);
            if (! $accepted) {
                if ($payment->first_verified_outcome === null) {
                    $payment->update(['verification_state' => 'unknown']);
                }

                return $payment;
            }
            if ($status === Payment::PENDING) {
                if ($payment->first_verified_outcome === null) {
                    $payment->update(['verification_state' => 'pending']);
                }

                return $payment;
            }
            $outcome = $status === Payment::PAID ? 'paid' : 'non_payable';
            if ($payment->first_verified_outcome !== null) {
                if ($payment->first_verified_outcome !== $outcome) {
                    $payment->update(['verification_state' => 'contradiction']);
                    // Preserve existing sale and financial outcome. Exception state is separate.
                    $checkout->update(['state' => $checkout->state === 'abandoned' ? 'abandoned' : 'provider_contradiction']);
                    $this->openCase($checkout, $payment, 'provider_contradiction');
                }

                return $payment;
            }
            if ($outcome === 'non_payable') {
                $payment->update(['first_verified_outcome' => $outcome, 'verification_state' => $outcome, 'status' => $status]);
                if ($checkout->sale_id === null && $checkout->state !== 'abandoned' && $checkout->state !== 'provider_contradiction') {
                    $checkout->update(['state' => 'ready_for_attempt']);
                }
                $this->ledger($payment, 'payment_non_payable', $eventId);

                return $payment;
            }

            $otherPayable = $checkout->attempts()->where('id', '!=', $payment->id)
                ->whereIn('verification_state', ['pending', 'unknown', 'paid', 'contradiction'])->lockForUpdate()->exists();
            $safe = CheckoutRules::canFulfill($checkout->state, $checkout->sale_id !== null, $otherPayable);
            $inventory = [];
            $items = $payment->items()->with('product')->orderBy('product_id')->get();
            if ($safe) {
                try {
                    foreach ($items as $item) {
                        $inventory[$item->product_id] = $this->stock->lockAndCheck($item->product_id, $item->quantity, $item->product?->name ?? 'Historical product', $payment->id);
                    }
                } catch (InsufficientStock) {
                    $safe = false;
                }
            }
            if (! $safe) {
                $payment->update([
                    'first_verified_outcome' => 'paid', 'verification_state' => 'paid',
                    'status' => Payment::PAID_UNFULFILLED, 'paid_at' => now(), 'failure_reason' => 'reconciliation_required',
                ]);
                if ($checkout->sale_id === null && $checkout->state !== 'abandoned') {
                    $checkout->update(['state' => 'paid_unfulfilled']);
                }
                $this->openCase($checkout, $payment, 'paid_unfulfilled');
                $this->ledger($payment, 'payment_paid_unfulfilled', $eventId);

                return $payment;
            }

            $sale = Sale::create([
                'checkout_id' => $checkout->id, 'created_by' => $payment->created_by,
                'key_namespace' => Sale::SETTLEMENT_NAMESPACE, 'idempotency_key' => 'payment:'.$payment->id,
                'total_amount' => $payment->amount, 'currency' => 'PHP', 'status' => 'completed',
                'payment_method' => 'qrph', 'completed_at' => now(),
            ]);
            foreach ($items as $item) {
                $sale->items()->create(['product_id' => $item->product_id, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price]);
                $inventory[$item->product_id]->decrement('quantity', $item->quantity);
            }
            $payment->update([
                'first_verified_outcome' => 'paid', 'verification_state' => 'paid', 'status' => Payment::PAID,
                'sale_id' => $sale->id, 'paid_at' => now(), 'failure_reason' => null,
            ]);
            $checkout->update(['state' => 'completed', 'sale_id' => $sale->id]);
            $this->ledger($payment, 'payment_succeeded', $eventId);

            return $payment;
        }, 3);
    }

    private function openCase(Checkout $checkout, Payment $payment, string $reason): void
    {
        // Current locking read: an earlier item read may have established an RR snapshot.
        $case = ReconciliationCase::where('payment_id', $payment->id)->where('reason', $reason)->lockForUpdate()->first();
        $case ??= ReconciliationCase::create(['payment_id' => $payment->id, 'reason' => $reason, 'checkout_id' => $checkout->id]);
        if ($case->state === 'resolved') {
            $case->update(['state' => 'reopened', 'version' => $case->version + 1]);
            $checkout->events()->create([
                'case_id' => $case->id, 'payment_id' => $payment->id, 'actor_type' => 'system', 'type' => 'case_reopened_by_evidence',
                'evidence' => ['reason' => $reason, 'version' => $case->version], 'occurred_at' => now(),
            ]);
        }
        if ($case->wasRecentlyCreated) {
            $checkout->events()->create([
                'case_id' => $case->id, 'payment_id' => $payment->id, 'actor_type' => 'system', 'type' => 'case_opened',
                'evidence' => ['reason' => $reason], 'occurred_at' => now(),
            ]);
        }
    }

    private function ledger(Payment $payment, string $type, ?string $eventId): void
    {
        $payment->transactions()->create([
            'sale_id' => $payment->sale_id, 'type' => $type, 'status' => $payment->status,
            'amount' => $payment->amount, 'currency' => 'PHP', 'provider_event_id' => $eventId,
            'metadata' => ['checkoutId' => $payment->checkout_id, 'source' => 'verified_provider'], 'occurred_at' => now(),
        ]);
    }
}
