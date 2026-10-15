<?php

namespace App\Models;

use App\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Balance is changed ONLY by App\Services\Wallet\WalletService.
 */
class Wallet extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
            'status' => WalletStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(WalletDeposit::class);
    }

    public function isFrozen(): bool
    {
        return $this->status === WalletStatus::Frozen;
    }
}