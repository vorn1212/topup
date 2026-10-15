<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** Written only by OrderService / OrderStateMachine, never from user input. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'player_fields' => 'array',
            'provider_cost' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'profit' => 'decimal:4',
            'status' => OrderStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function providerOrder(): HasOne
    {
        return $this->hasOne(ProviderOrder::class);
    }
}