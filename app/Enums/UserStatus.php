<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}