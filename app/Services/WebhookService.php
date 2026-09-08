<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WebhookService
{
    public function __construct(private readonly PaymentSettlementService $settlement) {}

    /**
     * Process a normalized PayMongo-like event exactly once.
     *
     * The extractor intentionally accepts both the provider envelope and the
     * small fixture shape used by Postman/automated tests.
     */
    public function process(array $payload): array
    {
        [$eventId, $eventType, $providerPaymentId, $status, $metadata] = $this->extract($payload);

        try {
            return DB::transaction(function () use ($eventId, $eventType, $providerPaymentId, $status, $metadata, $payload): array {
                $event = WebhookEvent::where('provider_event_id', $eventId)->lockForUpdate()->first();
                if ($event !== null) {
                    return ['event' => $event, 'duplicate' => true, 'payment' => null];
                }

                $event = WebhookEvent::create([
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'signature_valid' => true,
                    'payload' => $payload,
                ]);

                $payment = Payment::where('provider_payment_id', $providerPaymentId)->first();
                if ($payment === null) {
                    $event->update([
                        'processed_at' => now(),
                        'processing_error' => 'payment_not_found',
                    ]);

                    return ['event' => $event, 'duplicate' => false, 'payment' => null];
                }

                if ($status !== null) {
                    $payment = $this->settlement->settle($payment, $status, $eventId, $metadata);
                }

                $event->update(['processed_at' => now()]);

                return ['event' => $event->fresh(), 'duplicate' => false, 'payment' => $payment];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery that won the unique key is an idempotent
            // success, not a provider failure.
            $event = WebhookEvent::where('provider_event_id', $eventId)->firstOrFail();

            return ['event' => $event, 'duplicate' => true, 'payment' => null];
        }
    }

    /** @return array{0:string,1:string,2:string,3:?string,4:array} */
    private function extract(array $payload): array
    {
        $data = (array) ($payload['data'] ?? []);
        $attributes = (array) ($data['attributes'] ?? []);
        $resource = (array) ($attributes['resource'] ?? $data['resource'] ?? []);
        $resourceAttributes = (array) ($resource['attributes'] ?? []);

        $eventId = (string) ($payload['event_id'] ?? $data['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? $attributes['type'] ?? '');
        $providerPaymentId = (string) (
            $payload['payment_id']
            ?? data_get($payload, 'data.payment_id')
            ?? $resource['id']
            ?? data_get($payload, 'data.resource.id')
            ?? ''
        );
        $rawStatus = strtolower((string) (
            $payload['status']
            ?? data_get($payload, 'data.status')
            ?? $resourceAttributes['status']
            ?? ''
        ));
        $status = match (true) {
            in_array($rawStatus, ['paid', 'succeeded', 'successful'], true) || str_contains($eventType, '.paid') || str_contains($eventType, '.succeeded') => Payment::PAID,
            in_array($rawStatus, ['failed', 'expired'], true) || str_contains($eventType, '.failed') => Payment::FAILED,
            in_array($rawStatus, ['cancelled', 'canceled'], true) || str_contains($eventType, '.cancelled') || str_contains($eventType, '.canceled') => Payment::CANCELLED,
            in_array($rawStatus, ['pending', 'processing'], true) => Payment::PENDING,
            default => null,
        };

        if ($eventId === '' || $eventType === '' || $providerPaymentId === '') {
            throw new ApiException('Webhook payload is missing event, type, or payment identifiers.', 422, [
                'payload' => ['event_id, type, and payment_id are required.'],
            ]);
        }

        return [$eventId, $eventType, $providerPaymentId, $status, [
            'provider_status' => $rawStatus,
        ]];
    }
}
