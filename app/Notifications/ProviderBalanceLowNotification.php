<?php

namespace App\Notifications;

use App\Support\Money;

/** For staff. */
class ProviderBalanceLowNotification extends AppNotification
{
    public function __construct(
        private readonly string $balance,
        private readonly string $limit,
        private readonly string $currency = 'USD',
    ) {
        parent::__construct();
    }

    protected function kind(): string
    {
        return 'provider.balance_low';
    }

    protected function title(): string
    {
        return 'Provider balance is low';
    }

    protected function body(): string
    {
        return 'The FazerCards balance is '.Money::display($this->balance, $this->currency)
            .' (alert limit '.Money::display($this->limit, $this->currency).'). Top it up or customer orders will fail.';
    }

    protected function link(): string
    {
        return route('admin.attention.index', [], false);
    }
}