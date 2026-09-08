<?php

namespace App\Exceptions;

class IdempotencyConflict extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'The idempotency key was already used with a different checkout request.',
            409,
            ['idempotency_key' => ['Use a new key for a different cart.']],
        );
    }
}
