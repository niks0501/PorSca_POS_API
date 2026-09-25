<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(private readonly PaymentSettlementService $settlement, private readonly PaymentGateway $gateway) {}

    public function process(array $payload): array
    {
        $data = (array) ($payload['data'] ?? []);
        $attributes = (array) ($data['attributes'] ?? []);
        $resource = (array) ($attributes['data'] ?? []);
        $eventId = is_string($data['id'] ?? null) ? $data['id'] : '';
        $type = is_string($attributes['type'] ?? null) ? $attributes['type'] : '';
        $providerId = is_string($resource['id'] ?? null) ? $resource['id'] : '';
        if ($eventId === '' || $type === '' || ($providerId === '' && in_array($type, ['payment.paid', 'payment.failed', 'qrph.expired'], true))) {
            Log::warning('PayMongo event rejected', ['type' => $type, 'has_event_id' => $eventId !== '', 'has_resource_id' => $providerId !== '']);
            throw new ApiException('Unsupported PayMongo event.', 422, ['event' => ['Valid event ID, type, and resource ID required.']]);
        }
        if (! in_array($type, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
            Log::warning('PayMongo event ignored', ['type' => $type, 'has_event_id' => true, 'has_resource_id' => $providerId !== '']);
            try {
                $event = WebhookEvent::firstOrCreate(['provider_event_id' => $eventId], [
                    'event_type' => $type, 'signature_valid' => true,
                    'payload' => ['event_id' => $eventId, 'type' => $type, 'resource_id' => $providerId],
                    'processed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $event = WebhookEvent::where('provider_event_id', $eventId)->firstOrFail();
            }

            return ['event' => $event, 'duplicate' => ! $event->wasRecentlyCreated, 'payment' => null];
        }
        $event = WebhookEvent::where('provider_event_id', $eventId)->first();
        if ($event !== null && (($event->payload['resource_id'] ?? null) !== $providerId || $event->event_type !== $type
            || ! in_array($event->processing_error, ['provider_mismatch', 'payment_not_found'], true))) {
            return $this->result($event, true);
        }
        $payment = Payment::query()->where('provider_payment_id', $providerId)
            ->orWhere('provider_resource_id', $providerId)->first();
        $linkedIntentId = data_get($resource, 'attributes.payment_intent_id');
        $usingLinkedIntent = false;
        if ($payment === null && is_string($linkedIntentId) && $linkedIntentId !== '') {
            $payment = Payment::query()->where('provider_payment_id', $linkedIntentId)
                ->whereNull('provider_resource_id')->first();
            $usingLinkedIntent = $payment !== null;
        }
        // Provider GET is deliberately outside any database transaction. An event's status,
        // amount and currency are not trusted as authorization to sell.
        $inspection = $payment === null ? null : $this->gateway->inspect($payment);
        if ($usingLinkedIntent && (($inspection['verified'] ?? false) !== true || ($inspection['resource_id'] ?? null) !== $providerId)) {
            $payment = null;
        }
        try {
            $event = DB::transaction(function () use ($eventId, $type, $providerId, $payment, $inspection): WebhookEvent {
                $event = WebhookEvent::query()->where('provider_event_id', $eventId)->lockForUpdate()->first();
                if ($event === null) {
                    $event = WebhookEvent::create([
                        'provider_event_id' => $eventId, 'event_type' => $type,
                        'signature_valid' => true,
                        'payload' => ['event_id' => $eventId, 'type' => $type, 'resource_id' => $providerId],
                    ]);
                }
                if ($payment === null) {
                    $event->update(['processed_at' => now(), 'processing_error' => 'payment_not_found']);

                    return $event;
                }
                if (! ($inspection['verified'] ?? false)) {
                    $event->update(['processed_at' => now(), 'processing_error' => 'provider_mismatch']);

                    return $event;
                }
                $status = $inspection['status'];
                if ($status === Payment::PENDING && $type === 'qrph.expired') {
                    $status = Payment::EXPIRED;
                }
                $this->settlement->settle($payment, $status, $eventId, ['provider_event_type' => $type]);
                $event->update(['processed_at' => now(), 'processing_error' => null]);

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->result(WebhookEvent::where('provider_event_id', $eventId)->firstOrFail(), true);
        }

        return $this->result($event, false);
    }

    private function result(WebhookEvent $event, bool $duplicate): array
    {
        $id = (string) (($event->payload ?? [])['resource_id'] ?? '');
        $payment = $id === '' ? null : Payment::query()->where('provider_payment_id', $id)
            ->orWhere('provider_resource_id', $id)->first();

        return ['event' => $event, 'duplicate' => $duplicate, 'payment' => $payment?->load('items.product', 'sale')];
    }
}
