<?php

namespace App\Notifications;

use App\Support\Money;

class DepositPaidNotification extends AppNotification
{
    public function __construct(
        private readonly string $depositNumber,
        private readonly string $amount,
        private readonly string $currency = 'USD',
    ) {
        parent::__construct();
    }

    protected function kind(): string
    {
        return 'deposit.paid';
    }

    protected function title(): string
    {
        return 'Deposit received';
    }

    protected function body(): string
    {
        return Money::display($this->amount, $this->currency)." was added to your wallet (deposit {$this->depositNumber}).";
    }

    protected function link(): string
    {
        return route('wallet.index', [], false);
    }
}