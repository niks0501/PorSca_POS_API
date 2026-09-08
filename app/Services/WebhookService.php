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

                $payment = $this->findPayment($providerPaymentId);
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

    private function findPayment(string $providerPaymentId): ?Payment
    {
        $payment = Payment::where('provider_payment_id', $providerPaymentId)->first();
        if ($payment !== null) {
            return $payment;
        }

        // PayMongo payment.paid events can identify the attached Payment
        // resource instead of the PaymentIntent used for status polling. The
        // aliases are stored as non-sensitive provider metadata at creation.
        return Payment::query()
            ->whereNotNull('provider_metadata')
            ->get()
            ->first(function (Payment $candidate) use ($providerPaymentId): bool {
                $metadata = (array) $candidate->provider_metadata;

                return in_array($providerPaymentId, [
                    $metadata['provider_payment_resource_id'] ?? null,
                    $metadata['payment_id'] ?? null,
                    $metadata['payment_intent_id'] ?? null,
                ], true);
            });
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
        $providerPaymentId = $this->firstString([
            $payload['payment_id'] ?? null,
            data_get($payload, 'data.payment_id'),
            $resource['id'] ?? null,
            data_get($payload, 'data.resource.id'),
            $resourceAttributes['payment_intent_id'] ?? null,
            $resourceAttributes['payment_id'] ?? null,
        ]) ?? '';
        $rawStatus = strtolower($this->firstString([
            $payload['status'] ?? null,
            data_get($payload, 'data.status'),
            $resourceAttributes['status'] ?? null,
        ]) ?? '');
        $status = match (true) {
            in_array($rawStatus, ['paid', 'succeeded', 'successful'], true) || str_contains($eventType, '.paid') || str_contains($eventType, '.succeeded') => Payment::PAID,
            in_array($rawStatus, ['expired', 'qrph_expired'], true) || str_contains($eventType, '.expired') => Payment::EXPIRED,
            in_array($rawStatus, ['failed', 'declined'], true) || str_contains($eventType, '.failed') => Payment::FAILED,
            in_array($rawStatus, ['cancelled', 'canceled'], true) || str_contains($eventType, '.cancelled') || str_contains($eventType, '.canceled') => Payment::CANCELLED,
            in_array($rawStatus, ['pending', 'processing', 'awaiting_payment', 'awaiting_next_action'], true) => Payment::PENDING,
            default => null,
        };

        if ($eventId === '' || $eventType === '' || $providerPaymentId === '') {
            throw new ApiException('Webhook payload is missing event, type, or payment identifiers.', 422, [
                'payload' => ['event_id, type, and payment_id are required.'],
            ]);
        }

        return [$eventId, $eventType, $providerPaymentId, $status, [
            'provider_status' => $rawStatus,
            'provider_event_type' => $eventType,
        ]];
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
