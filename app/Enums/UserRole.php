<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case Support = 'support';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Support => 'Support',
            self::Admin => 'Admin',
            self::SuperAdmin => 'Super Admin',
        };
    }

    public function isStaff(): bool
    {
        return $this !== self::User;
    }

    /** @return list<string> */
    public static function staffValues(): array
    {
        return [self::Support->value, self::Admin->value, self::SuperAdmin->value];
    }
}