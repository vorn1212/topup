<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A private staff note on an order. Append-only: notes are never edited or deleted.
 */
class OrderNote extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Order notes cannot be edited.');
        });

        static::deleting(function () {
            throw new LogicException('Order notes cannot be deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}