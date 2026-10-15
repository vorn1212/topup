<?php

namespace App\Exceptions;

use RuntimeException;

class PriceBelowCostException extends RuntimeException
{
    public function __construct(string $message = 'Selling price is below the provider cost.')
    {
        parent::__construct($message);
    }
}