<?php

namespace App\Exceptions;

use RuntimeException;

class StalePreviewException extends RuntimeException
{
    public function __construct(string $message = 'Prices changed since you previewed. Nothing was changed. Please preview again.')
    {
        parent::__construct($message);
    }
}