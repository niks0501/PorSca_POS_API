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
                'metadata' => [
                    'driver' => 'local-sandbox-fixture',
                    'payment_method' => 'qrph',
                ],
            ];
        }

        $endpoint = rtrim((string) config('services.paymongo.endpoint'), '/');
        $intentResponse = $this->send(fn (): Response => Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($endpoint, [
                'data' => [
                    'attributes' => [
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'payment_method_allowed' => ['qrph'],
                        'description' => 'PorSca sale '.$payment->id,
                    ],
                ],
            ]));

        $intentAttributes = (array) $intentResponse->json('data.attributes', []);
        $providerId = (string) $intentResponse->json('data.id');
        if ($providerId === '') {
            throw new PaymentGatewayException('PayMongo sandbox returned an invalid payment response.');
        }

        // A fixture or a compatible provider adapter may return the QR on the
        // intent response. Prefer it when present, while the normal PayMongo
        // flow below creates and attaches a QR Ph payment method server-side.
        $directQr = $this->qrPayload($intentAttributes);
        if ($directQr !== null) {
            return [
                'provider_payment_id' => $providerId,
                'qr_payload' => $directQr,
                'checkout_url' => $this->checkoutUrl($intentAttributes),
                'metadata' => $this->providerMetadata($providerId, $intentAttributes),
            ];
        }

        $paymentMethodEndpoint = $this->resourceEndpoint($endpoint, 'payment_methods');
        $paymentMethodAttributes = [
            'type' => 'qrph',
        ];
        $expirySeconds = (int) config('services.paymongo.qr_expiry_seconds', 1800);
        if ($expirySeconds > 0) {
            $paymentMethodAttributes['expiry_seconds'] = $expirySeconds;
        }

        $paymentMethodResponse = $this->send(fn (): Response => Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($paymentMethodEndpoint, [
                'data' => ['attributes' => $paymentMethodAttributes],
            ]));
        $paymentMethodId = (string) $paymentMethodResponse->json('data.id');
        if ($paymentMethodId === '') {
            throw new PaymentGatewayException('PayMongo sandbox returned an invalid QR Ph payment method.');
        }

        $attachPayload = [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                ],
            ],
        ];
        $clientKey = $this->firstString($intentAttributes, ['client_key']);
        if ($clientKey !== null) {
            $attachPayload['data']['attributes']['client_key'] = $clientKey;
        }

        $attachResponse = $this->send(fn (): Response => Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($endpoint.'/'.$providerId.'/attach', $attachPayload));
        $attachedAttributes = (array) $attachResponse->json('data.attributes', []);
        $qrPayload = $this->qrPayload($attachedAttributes);
        if ($qrPayload === null) {
            throw new PaymentGatewayException('PayMongo sandbox did not return a QR Ph payload.');
        }

        return [
            'provider_payment_id' => $providerId,
            'qr_payload' => $qrPayload,
            'checkout_url' => $this->checkoutUrl($attachedAttributes),
            'metadata' => array_merge(
                $this->providerMetadata($providerId, $intentAttributes),
                [
                    'provider_payment_method_id' => $paymentMethodId,
                    'provider_payment_resource_id' => $this->paymentResourceId($attachedAttributes),
                    'provider_status' => $this->firstString($attachedAttributes, ['status']),
                ],
            ),
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
        $response = $this->send(fn (): Response => Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->get($endpoint.'/'.$payment->provider_payment_id));

        $status = strtolower((string) data_get($response->json(), 'data.attributes.status', Payment::PENDING));

        return $this->normalizeStatus($status);
    }

    private function assertSandbox(): void
    {
        if (config('services.paymongo.mode') !== 'sandbox') {
            throw new PaymentGatewayException('Only the PayMongo sandbox is enabled for this API.');
        }
    }

    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new PaymentGatewayException('PayMongo sandbox could not be reached.');
        }

        if ($response->failed()) {
            throw new PaymentGatewayException('PayMongo sandbox rejected the payment request.');
        }

        return $response;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'paid', 'succeeded', 'successful' => Payment::PAID,
            'failed', 'declined' => Payment::FAILED,
            'cancelled', 'canceled' => Payment::CANCELLED,
            'expired', 'qrph_expired' => Payment::EXPIRED,
            default => Payment::PENDING,
        };
    }

    private function resourceEndpoint(string $endpoint, string $resource): string
    {
        $replaced = preg_replace('#/payment_intents/?$#', '/'.$resource, $endpoint);

        return $replaced !== null && $replaced !== $endpoint
            ? $replaced
            : rtrim($endpoint, '/').'/'.$resource;
    }

    private function qrPayload(array $attributes): ?string
    {
        return $this->firstString($attributes, [
            'qr_code',
            'qr_payload',
            'qrph_code',
            'next_action.code.image_url',
        ]);
    }

    private function checkoutUrl(array $attributes): ?string
    {
        return $this->firstString($attributes, [
            'checkout_url',
            'url',
            'next_action.redirect.url',
        ]);
    }

    private function paymentResourceId(array $attributes): ?string
    {
        foreach (['payment', 'payment_id'] as $key) {
            $value = data_get($attributes, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value) && isset($value['id']) && is_string($value['id']) && $value['id'] !== '') {
                return $value['id'];
            }
        }

        return null;
    }

    private function providerMetadata(string $providerId, array $attributes): array
    {
        return array_filter([
            'driver' => 'paymongo-sandbox',
            'payment_method' => 'qrph',
            'provider_payment_intent_id' => $providerId,
            'provider_payment_resource_id' => $this->paymentResourceId($attributes),
            'provider_status' => $this->firstString($attributes, ['status']),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($values, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
