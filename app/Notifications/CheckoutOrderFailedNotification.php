<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Tells staff that a customer paid by QR but the top-up could not be started. */
class CheckoutOrderFailedNotification extends Notification
{
    public function __construct(
        private readonly string $depositNumber,
        private readonly string $amount,
        private readonly string $reason,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,string> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'checkout.failed',
            'title' => 'Paid by QR, top-up not started',
            'body' => "Payment {$this->depositNumber} ({$this->amount}) was received but the order could not be placed: {$this->reason} The money is still in the customer's account. Refund or fix it by hand.",
            'url' => '/admin/deposits',
        ];
    }
}
