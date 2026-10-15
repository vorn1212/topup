<?php

namespace App\Notifications;

use App\Support\Money;

class OrderRefundedNotification extends AppNotification
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $amount,
        private readonly string $currency = 'USD',
    ) {
        parent::__construct();
    }

    protected function kind(): string
    {
        return 'order.refunded';
    }

    protected function title(): string
    {
        return 'Order refunded';
    }

    protected function body(): string
    {
        return "Order {$this->orderNumber} could not be completed. ".Money::display($this->amount, $this->currency).' was returned to your wallet.';
    }

    protected function link(): string
    {
        return route('orders.show', ['orderNumber' => $this->orderNumber], false);
    }
}