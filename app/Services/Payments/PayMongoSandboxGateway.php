<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
        if (! str_starts_with($secret, 'sk_test_') || $payment->amount < 1 || $payment->amount > 4294967295 || $payment->currency !== 'PHP' || blank($payment->provider_operation_key)) {
            throw new PaymentGatewayException('Invalid sandbox payment configuration.');
        }

        $endpoint = rtrim((string) config('services.paymongo.endpoint'), '/');
        $providerId = $payment->provider_payment_id;
        if ($providerId === null) {
            $intent = $this->request('post', $endpoint, [
                'data' => ['attributes' => [
                    'amount' => $payment->amount, 'currency' => 'PHP',
                    'payment_method_allowed' => ['qrph'], 'description' => 'PorSca sale '.$payment->id,
                ]],
            ], $payment->provider_operation_key.':intent');
            $providerId = (string) $intent->json('data.id');
            if ($providerId === '') {
                throw new PaymentGatewayException('PayMongo returned an invalid payment intent.');
            }
            $payment->update(['provider_payment_id' => $providerId]);
            $intentAttributes = (array) $intent->json('data.attributes', []);
        } else {
            $intentAttributes = (array) $this->request('get', $endpoint.'/'.$providerId)->json('data.attributes', []);
        }
        // Recover an already attached intent before attempting to attach again.
        $qr = $this->qr($intentAttributes);
        $methodId = $payment->provider_method_id;
        if ($qr === null) {
            if ($methodId === null) {
                $method = $this->request('post', $this->resourceEndpoint($endpoint, 'payment_methods'), [
                    'data' => ['attributes' => [
                        'type' => 'qrph', 'expiry_seconds' => (int) config('services.paymongo.qr_expiry_seconds', 1800),
                    ]],
                ], $payment->provider_operation_key.':method');
                $methodId = (string) $method->json('data.id');
                if ($methodId === '') {
                    throw new PaymentGatewayException('PayMongo returned an invalid QR Ph method.');
                }
                $payment->update(['provider_method_id' => $methodId]);
            }
            $attributes = ['payment_method' => $methodId];
            if (is_string($intentAttributes['client_key'] ?? null)) {
                $attributes['client_key'] = $intentAttributes['client_key'];
            }
            $attached = $this->request('post', $endpoint.'/'.$providerId.'/attach', [
                'data' => ['attributes' => $attributes],
            ], $payment->provider_operation_key.':attach');
            $intentAttributes = (array) $attached->json('data.attributes', []);
            $qr = $this->qr($intentAttributes);
        }
        if ($qr === null) {
            throw new PaymentGatewayException('PayMongo did not return a QR Ph image.');
        }
        $resourceId = data_get($intentAttributes, 'payment.id') ?? data_get($intentAttributes, 'payment');
        if (! is_string($resourceId)) {
            $resourceId = null;
        }
        if ($resourceId !== null) {
            $payment->update(['provider_resource_id' => $resourceId]);
        }

        // PayMongo test_url is intentionally not stored or returned to cashiers.
        return [
            'provider_payment_id' => $providerId,
            'qr_payload' => $qr,
            'checkout_url' => null,
            'metadata' => ['driver' => 'paymongo-sandbox', 'provider_status' => $intentAttributes['status'] ?? null],
        ];
    }

    public function inspect(Payment $payment): array
    {
        $this->assertSandbox();
        if (blank($payment->provider_payment_id) || str_starts_with((string) $payment->provider_payment_id, 'sandbox_')) {
            return ['status' => Payment::PENDING, 'verified' => false];
        }
        $response = $this->request('get', rtrim((string) config('services.paymongo.endpoint'), '/').'/'.$payment->provider_payment_id);
        $attributes = (array) $response->json('data.attributes', []);
        $providerId = (string) $response->json('data.id');
        $status = strtolower((string) ($attributes['status'] ?? ''));
        $resourceId = data_get($attributes, 'payment.id') ?? data_get($attributes, 'payment');
        $verified = $providerId === $payment->provider_payment_id
            && ($attributes['amount'] ?? null) === $payment->amount
            && ($attributes['currency'] ?? null) === 'PHP'
            && ($attributes['livemode'] ?? null) === false;
        if ($verified && is_string($resourceId) && $resourceId !== '' && $payment->provider_resource_id === null) {
            $payment->update(['provider_resource_id' => $resourceId]);
        }

        return [
            'status' => match ($status) {
                'succeeded', 'paid' => Payment::PAID,
                'failed', 'cancelled', 'canceled' => Payment::FAILED,
                default => Payment::PENDING, // QR expiry is not intent failure.
            },
            'verified' => $verified,
        ];
    }

    public function status(Payment $payment): string
    {
        $result = $this->inspect($payment);

        return $result['verified'] ? $result['status'] : Payment::PENDING;
    }

    private function request(string $method, string $url, ?array $payload = null, ?string $key = null): Response
    {
        $secret = (string) config('services.paymongo.secret_key');
        if (! str_starts_with($secret, 'sk_test_')) {
            throw new PaymentGatewayException('PayMongo sandbox credentials are unavailable.');
        }
        $request = Http::withBasicAuth($secret, '')->acceptJson()->connectTimeout(5)->timeout(15);
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }
        try {
            $response = $method === 'post' ? $request->post($url, $payload) : $request->get($url);
        } catch (ConnectionException) {
            throw new PaymentGatewayException('PayMongo sandbox could not be reached.');
        }
        if ($response->failed()) {
            throw new PaymentGatewayException('PayMongo sandbox rejected the payment request.');
        }

        return $response;
    }

    private function assertSandbox(): void
    {
        if (config('services.paymongo.mode') !== 'sandbox') {
            throw new PaymentGatewayException('Only the PayMongo sandbox is enabled for this API.');
        }
    }

    private function resourceEndpoint(string $endpoint, string $resource): string
    {
        return (string) preg_replace('#/payment_intents$#', '/'.$resource, $endpoint);
    }

    private function qr(array $attributes): ?string
    {
        foreach (['next_action.code.image_url', 'qr_code', 'qr_payload'] as $key) {
            $value = data_get($attributes, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
