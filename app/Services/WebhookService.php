<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WebhookService
{
    public function __construct(private readonly PaymentSettlementService $settlement, private readonly PaymentGateway $gateway) {}

    public function process(array $payload): array
    {
        $data = (array) ($payload['data'] ?? []);
        $attributes = (array) ($data['attributes'] ?? []);
        $resource = (array) ($attributes['resource'] ?? []);
        $eventId = (string) ($data['id'] ?? '');
        $type = (string) ($attributes['type'] ?? '');
        $providerId = (string) ($resource['id'] ?? '');
        if ($eventId === '' || $providerId === '' || ! in_array($type, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
            throw new ApiException('Unsupported PayMongo event.', 422, ['event' => ['Valid event ID, type, and resource ID required.']]);
        }
        $event = WebhookEvent::where('provider_event_id', $eventId)->first();
        if ($event !== null && (($event->payload['resource_id'] ?? null) !== $providerId || $event->event_type !== $type
            || ! in_array($event->processing_error, ['provider_mismatch', 'payment_not_found'], true))) {
            return $this->result($event, true);
        }
        $payment = Payment::query()->where('provider_payment_id', $providerId)
            ->orWhere('provider_resource_id', $providerId)->first();
        // Provider GET is deliberately outside any database transaction. An event's status,
        // amount and currency are not trusted as authorization to sell.
        $inspection = $payment === null ? null : $this->gateway->inspect($payment);
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
