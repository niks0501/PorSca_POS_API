<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Models\Checkout;
use App\Models\Payment;
use App\Services\CheckoutOutcomes;
use App\Services\PaymentSettlementService;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function show(Payment $payment)
    {
        $this->scopeLinkedCheckout($payment);

        return $this->data($this->paymentArray($payment->load('items.product', 'sale')));
    }

    public function refresh(Request $request, Payment $payment)
    {
        $this->scopeLinkedCheckout($payment);

        if ($payment->checkout_id !== null) {
            $payment = app(CheckoutOutcomes::class)->refresh($payment, $request->user());

            return $this->data($this->paymentArray($payment->load('items.product', 'sale')));
        }
        if (in_array($payment->status, [Payment::PAID, Payment::PAID_UNFULFILLED], true)) {
            return $this->data($this->paymentArray($payment->load('items.product', 'sale')));
        }

        $inspection = $this->gateway->inspect($payment);
        if ($inspection['verified'] ?? false) {
            $status = $inspection['status'];
            if ($status === Payment::PENDING && $payment->reservation_expires_at?->isPast()) {
                $status = Payment::EXPIRED;
            }
            $payment = $this->settlement->settle($payment, $status, null, ['source' => 'status_refresh']);
        } else {
            $payment->load('items.product', 'sale');
        }

        return $this->data($this->paymentArray($payment));
    }

    private function scopeLinkedCheckout(Payment $payment): void
    {
        if ($payment->checkout_id !== null) {
            abort_unless(Checkout::whereKey($payment->checkout_id)->where('store_id', config('checkout.store_id'))->exists(), 404);
        }
    }
}
