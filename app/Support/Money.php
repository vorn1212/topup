<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal-string money math using bcmath. Never use floats for money.
 */
final class Money
{
    public const SCALE = 4;

    private const SYMBOLS = ['USD' => '$', 'KHR' => '៛'];

    public static function normalize(string $value): string
    {
        return bcadd(self::clean($value), '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::clean($a), self::clean($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::clean($a), self::clean($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul(self::clean($a), self::clean($b), self::SCALE + 6), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp(self::clean($b), '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return self::round(bcdiv(self::clean($a), self::clean($b), self::SCALE + 6), self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp(self::clean($a), self::clean($b), self::SCALE);
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    /**
     * Round half away from zero to $scale decimals.
     */
    public static function round(string $value, int $scale = 2): string
    {
        $value = self::clean($value);
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', $scale + 6) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    public static function format(string $amount, string $currency = 'USD', int $decimals = 2): string
    {
        $rounded = self::round($amount, $decimals);
        $negative = str_starts_with($rounded, '-');
        $abs = ltrim($rounded, '-');

        [$int, $dec] = array_pad(explode('.', $abs, 2), 2, '');
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);
        $number = $decimals > 0 ? $int.'.'.str_pad($dec, $decimals, '0') : $int;

        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return ($negative ? '-' : '').$symbol.$number;
    }

    private static function clean(string $value): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('Invalid money value.');
        }

        return $value;
    }

        /**
     * Show 2 decimals, or 4 when the price really has sub-cent digits.
     */
    public static function display(string $amount, string $currency = 'USD'): string
    {
        $normalized = self::normalize($amount);
        $decimals = substr($normalized, -2) === '00' ? 2 : 4;

        return self::format($normalized, $currency, $decimals);
    }
}