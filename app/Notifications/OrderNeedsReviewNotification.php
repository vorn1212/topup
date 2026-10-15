<?php

namespace App\Notifications;

/** For staff. */
class OrderNeedsReviewNotification extends AppNotification
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly int $providerOrderId,
        private readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    protected function kind(): string
    {
        return 'order.needs_review';
    }

    protected function title(): string
    {
        return 'An order needs your review';
    }

    protected function body(): string
    {
        $reason = filled($this->reason) ? ' Last error: '.mb_substr($this->reason, 0, 160) : '';

        return "Order {$this->orderNumber} stopped automatic handling and waits for a decision.".$reason;
    }

    protected function link(): string
    {
        return route('admin.attention.orders.show', ['providerOrder' => $this->providerOrderId], false);
    }
}