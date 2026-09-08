<?php

namespace App\Exceptions;

class PaymentGatewayException extends ApiException
{
    public function __construct(string $message = 'The payment provider is unavailable.')
    {
        parent::__construct($message, 503);
    }
}
