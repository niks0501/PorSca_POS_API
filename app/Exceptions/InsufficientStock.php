<?php

namespace App\Exceptions;

class InsufficientStock extends ApiException
{
    public function __construct(string $productName)
    {
        parent::__construct(
            "Insufficient stock for {$productName}.",
            409,
            ['product' => [$productName]],
        );
    }
}
