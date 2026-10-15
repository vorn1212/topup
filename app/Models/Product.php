<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Enums\MarkupType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /**
     * Price columns (provider_cost, markup_type, markup_value, selling_price) are deliberately NOT
     * fillable: they change only through App\Services\Pricing\PricingService.
     */
    protected $fillable = [
        'name',
        'description',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost' => 'decimal:4',
            'markup_type' => MarkupType::class,
            'markup_value' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'status' => CatalogStatus::class,
            'is_available' => 'boolean',
            'sort_order' => 'integer',
            'provider_data' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    /** Profit per sale (selling price minus provider cost). Same value as the effective markup. */
    public function profit(): string
    {
        return Money::sub((string) $this->selling_price, (string) $this->provider_cost);
    }

    public function isBelowCost(): bool
    {
        return Money::cmp((string) $this->selling_price, (string) $this->provider_cost) < 0;
    }
}