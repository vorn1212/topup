<?php

namespace App\Notifications;

class OrderCompletedNotification extends AppNotification
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $gameName,
        private readonly string $productName,
    ) {
        parent::__construct();
    }

    protected function kind(): string
    {
        return 'order.completed';
    }

    protected function title(): string
    {
        return 'Top-up completed';
    }

    protected function body(): string
    {
        return "{$this->gameName} - {$this->productName} was delivered (order {$this->orderNumber}).";
    }

    protected function link(): string
    {
        return route('orders.show', ['orderNumber' => $this->orderNumber], false);
    }
}