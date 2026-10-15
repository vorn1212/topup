<?php

namespace App\Enums;

enum WalletTxType: string
{
    case Deposit = 'deposit';
    case Purchase = 'purchase';
    case Refund = 'refund';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Wallet Deposit',
            self::Purchase => 'Purchase',
            self::Refund => 'Order Refund',
            self::Bonus => 'Bonus',
            self::Adjustment => 'Adjustment',
            self::Withdrawal => 'Withdrawal',
        };
    }
}