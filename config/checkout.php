<?php

return [
    // Single-store v1: never accept store identity from a request or device.
    'store_id' => env('CHECKOUT_STORE_ID', 'porsca'),
    'hold_seconds' => (int) env('CHECKOUT_HOLD_SECONDS', 1800),
    'qr_minimum_centavos' => (int) env('CHECKOUT_QR_MINIMUM_CENTAVOS', 100),
    // Operator approval after GATE-01..06; false is intentionally fail-closed.
    'simulation_enabled' => (bool) env('CHECKOUT_SIMULATION_ENABLED', false),
    'provider_finality_enabled' => (bool) env('CHECKOUT_PROVIDER_FINALITY_ENABLED', false),
];
