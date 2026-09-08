<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PayMongoSandboxGateway implements PaymentGateway
{
    public function createQrPayment(Payment $payment): array
    {
        $this->assertSandbox();

        $secret = (string) config('services.paymongo.secret_key');
        if ($secret === '') {
            return [
                'provider_payment_id' => 'sandbox_'.Str::uuid(),
                'qr_payload' => 'https://sandbox.paymongo.test/qr/'.Str::uuid(),
                'checkout_url' => null,
                'metadata' => ['driver' => 'local-sandbox-fixture', 'payment_method' => 'qrph'],
            ];
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->post((string) config('services.paymongo.endpoint'), [
                'data' => [
                    'attributes' => [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'payment_method_allowed_types' => ['qrph'],
                        'description' => 'PorSca sale '.$payment->id,
                        'livemode' => false,
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new PaymentGatewayException('PayMongo sandbox rejected the QR Ph payment request.');
        }

        $attributes = (array) $response->json('data.attributes', []);
        $providerId = (string) $response->json('data.id');
        if ($providerId === '') {
            throw new PaymentGatewayException('PayMongo sandbox returned an invalid payment response.');
        }

        return [
            'provider_payment_id' => $providerId,
            'qr_payload' => $this->firstString($attributes, ['qr_code', 'qr_payload', 'qrph_code']),
            'checkout_url' => $this->firstString($attributes, ['checkout_url', 'url']),
            'metadata' => $attributes,
        ];
    }

    public function status(Payment $payment): string
    {
        $this->assertSandbox();

        if (blank($payment->provider_payment_id)) {
            return Payment::PENDING;
        }

        $secret = (string) config('services.paymongo.secret_key');
        if ($secret === '') {
            return Payment::PENDING;
        }

        $endpoint = rtrim((string) config('services.paymongo.endpoint'), '/');
        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->get($endpoint.'/'.$payment->provider_payment_id);

        if ($response->failed()) {
            throw new PaymentGatewayException('PayMongo sandbox status lookup failed.');
        }

        $status = strtolower((string) data_get($response->json(), 'data.attributes.status', Payment::PENDING));

        return match ($status) {
            'paid', 'succeeded', 'successful' => Payment::PAID,
            'failed', 'expired' => Payment::FAILED,
            'cancelled', 'canceled' => Payment::CANCELLED,
            default => Payment::PENDING,
        };
    }

    private function assertSandbox(): void
    {
        if (config('services.paymongo.mode') !== 'sandbox') {
            throw new PaymentGatewayException('Only the PayMongo sandbox is enabled for this API.');
        }
    }

    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '') {
                return $values[$key];
            }
        }

        return null;
    }
}
