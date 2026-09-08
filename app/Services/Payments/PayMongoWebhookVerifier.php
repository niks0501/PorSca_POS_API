<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * Minimal HMAC verifier seam for the sandbox webhook endpoint.
 *
 * PayMongo can change its signed-header format; the production adapter should
 * replace this class without changing webhook processing. Until then the
 * endpoint accepts X-PayMongo-Signature as a hex SHA-256 HMAC of the raw body.
 */
class PayMongoWebhookVerifier
{
    public function verify(Request $request): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        $provided = (string) $request->header('X-PayMongo-Signature', '');
        if ($secret === '' || $provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
