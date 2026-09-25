<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

class PayMongoWebhookVerifier
{
    public function verify(Request $request): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        $header = (string) $request->header('Paymongo-Signature', '');
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) !== 2 || isset($parts[$pair[0]])) {
                return false;
            }
            $parts[$pair[0]] = $pair[1];
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['te'] ?? '';
        if (! preg_match('/^\d{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 300
            || ! preg_match('/^[a-fA-F0-9]{64}$/D', $signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), strtolower($signature));
    }
}
