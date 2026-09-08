<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentGateway
{
    /**
     * Create a QR Ph payment in the PayMongo sandbox.
     *
     * @return array{provider_payment_id:string, qr_payload:?string, checkout_url:?string, metadata:array}
     */
    public function createQrPayment(Payment $payment): array;

    /**
     * Return a normalized local status: pending, paid, failed, cancelled, or expired.
     */
    public function status(Payment $payment): string;
}
