<?php

namespace App\Enums;

enum WalletStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}