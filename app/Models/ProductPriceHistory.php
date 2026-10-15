<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable record of one price change.
 */
class ProductPriceHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:4',
            'new_price' => 'decimal:4',
            'old_markup' => 'decimal:4',
            'new_markup' => 'decimal:4',
            'old_cost' => 'decimal:4',
            'new_cost' => 'decimal:4',
            'old_markup_value' => 'decimal:4',
            'new_markup_value' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Price history is immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Price history cannot be deleted.');
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}