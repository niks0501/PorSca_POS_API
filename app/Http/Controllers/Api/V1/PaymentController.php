<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Services\PaymentSettlementService;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function show(Payment $payment)
    {
        return $this->data($this->paymentArray($payment->load('items.product', 'sale')));
    }

    public function refresh(Payment $payment)
    {
        if (in_array($payment->status, Payment::terminalStatuses(), true)) {
            return $this->data($this->paymentArray($payment->load('items.product', 'sale')));
        }

        $status = $this->gateway->status($payment);
        $payment = $this->settlement->settle($payment, $status, null, ['source' => 'status_refresh']);

        return $this->data($this->paymentArray($payment));
    }
}
