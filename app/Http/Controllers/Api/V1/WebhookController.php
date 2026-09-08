<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Payments\PayMongoWebhookVerifier;
use App\Services\WebhookService;
use Illuminate\Http\Request;

class WebhookController extends ApiController
{
    public function __construct(
        private readonly PayMongoWebhookVerifier $verifier,
        private readonly WebhookService $webhooks,
    ) {}

    public function paymongo(Request $request)
    {
        if (! $this->verifier->verify($request)) {
            return $this->error('invalid_signature', 'Webhook signature verification failed.', 401);
        }

        $result = $this->webhooks->process($request->json()->all());
        $payment = $result['payment'];

        return $this->data([
            'event_id' => $result['event']->provider_event_id,
            'event_type' => $result['event']->event_type,
            'duplicate' => $result['duplicate'],
            'processed_at' => $result['event']->processed_at?->toISOString(),
            'payment' => $payment === null ? null : $this->paymentArray($payment),
        ]);
    }
}
