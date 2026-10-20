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

    /** Own icons are stored here by the admin upload only; the path is checked again when it is read. */
    public const ICON_PATTERN = '#^games/product-icons/[A-Za-z0-9]{40}\.png$#';

    public function hasOwnIcon(): bool
    {
        return is_string($this->icon) && preg_match(self::ICON_PATTERN, $this->icon) === 1;
    }

    /**
     * The picture for this package: its own icon, else the game's default package icon, else null
     * (the page then shows the built-in diamond). Pass $game when it is already loaded.
     */
    public function iconUrl(?Game $game = null): ?string
    {
        if ($this->hasOwnIcon()) {
            return asset('storage/'.$this->icon);
        }

        return ($game ?? $this->game)?->packageIconUrl();
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
