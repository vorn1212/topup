<?php

namespace App\Exceptions;

use RuntimeException;

class WalletFrozenException extends RuntimeException
{
    public function __construct(string $message = 'This wallet is frozen and cannot be debited.')
    {
        parent::__construct($message);
    }
}