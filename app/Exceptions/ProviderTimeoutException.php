<?php

namespace App\Exceptions;

use Throwable;

class ProviderTimeoutException extends ProviderException
{
    public function __construct(string $message = 'The top-up provider did not answer in time.', ?Throwable $previous = null)
    {
        parent::__construct($message, null, null, $previous);
    }
}