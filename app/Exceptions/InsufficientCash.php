<?php

namespace App\Exceptions;

class InsufficientCash extends ApiException
{
    public function __construct(int $cashReceived, int $totalAmount)
    {
        parent::__construct(
            'Cash received is less than the total amount.',
            422,
            [
                'cash_received' => ["Cash received ({$cashReceived}) must be at least {$totalAmount}."],
                'total_amount' => [(string) $totalAmount],
            ],
        );
    }
}
