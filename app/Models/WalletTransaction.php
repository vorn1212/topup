<?php

namespace App\Models;

use App\Enums\WalletTxType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable ledger row. Corrections are made by creating a NEW transaction.
 */
class WalletTransaction extends Model
{
    public const CREDIT = 'credit';
    public const DEBIT = 'debit';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'type' => WalletTxType::class,
            'amount' => 'decimal:4',
            'balance_before' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Wallet transactions are immutable. Create an adjustment transaction instead.');
        });

        static::deleting(function () {
            throw new LogicException('Wallet transactions cannot be deleted.');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }
}