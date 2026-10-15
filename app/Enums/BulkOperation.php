<?php

namespace App\Enums;

use App\Support\Money;

enum BulkOperation: string
{
    case AddAmount = 'add_amount';
    case AddPercent = 'add_percent';
    case SetMarkupPercent = 'set_markup_percent';
    case SetMarkupAmount = 'set_markup_amount';
    case SetFixedPrice = 'set_fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::AddAmount => 'Increase / decrease price by an amount (USD)',
            self::AddPercent => 'Increase / decrease price by a percentage',
            self::SetMarkupPercent => 'Set percentage markup over cost',
            self::SetMarkupAmount => 'Set fixed markup over cost (USD)',
            self::SetFixedPrice => 'Set a fixed selling price (USD)',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::AddAmount => 'e.g. 0.10 or -0.10',
            self::AddPercent => 'e.g. 5 or -5 (percent)',
            self::SetMarkupPercent => 'e.g. 20 (percent over cost)',
            self::SetMarkupAmount => 'e.g. 0.25 (USD over cost)',
            self::SetFixedPrice => 'e.g. 1.00 (USD)',
        };
    }

    /** Human description of what will happen. $value must already be validated. */
    public function describe(string $value): string
    {
        $sign = str_starts_with($value, '-') ? '−' : '+';
        $abs = ltrim($value, '-');

        return match ($this) {
            self::AddAmount => "Change every selected selling price by {$sign}".Money::display($abs),
            self::AddPercent => "Change every selected selling price by {$sign}{$abs}%",
            self::SetMarkupPercent => "Set the markup of every selected product to {$abs}% over cost",
            self::SetMarkupAmount => 'Set the markup of every selected product to '.Money::display($abs).' over cost',
            self::SetFixedPrice => 'Set the selling price of every selected product to '.Money::display($abs),
        };
    }
}