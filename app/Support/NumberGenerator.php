<?php

namespace App\Support;

final class NumberGenerator
{
    /** No 0/O/1/I to avoid confusion when read aloud or typed. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Format: DEP-YYYYMMDD-XXXXXX */
    public static function depositNumber(): string
    {
        return 'DEP-'.now()->format('Ymd').'-'.self::random(6);
    }

    public static function random(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}