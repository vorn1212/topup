<?php

namespace App\Enums;

enum MarkupType: string
{
    case FixedPrice = 'fixed_price';
    case FixedMarkup = 'fixed_markup';
    case PercentMarkup = 'percent_markup';

    public function label(): string
    {
        return match ($this) {
            self::FixedPrice => 'Fixed selling price',
            self::FixedMarkup => 'Fixed markup (cost + amount)',
            self::PercentMarkup => 'Percentage markup (cost + %)',
        };
    }

    public function valueLabel(): string
    {
        return match ($this) {
            self::FixedPrice => 'Selling price (USD)',
            self::FixedMarkup => 'Markup amount (USD)',
            self::PercentMarkup => 'Markup (%)',
        };
    }
}