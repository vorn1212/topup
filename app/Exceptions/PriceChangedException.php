<?php

namespace App\Exceptions;

class PriceChangedException extends OrderRejectedException
{
    public function __construct(public readonly string $newPrice)
    {
        parent::__construct('The price changed. Please review the new price and confirm again.');
    }
}