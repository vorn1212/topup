<?php

namespace App\Jobs;

use App\Enums\DepositStatus;
use App\Models\WalletDeposit;
use App\Services\Checkout\CheckoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Watches one pay-by-QR checkout. When the payment is confirmed it places the order, even if the customer
 * has closed the page. Gives up when the payment window has passed.
 */
class PlaceCheckoutOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public function __construct(public int $depositId)
    {
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(75);
    }

    public function handle(CheckoutService $checkout): void
    {
        $deposit = WalletDeposit::query()->find($this->depositId);

        if (! $deposit) {
            return;
        }

        if ($deposit->status === DepositStatus::Paid) {
            $checkout->placeOrder($deposit->id);

            if ($checkout->waitingForOrder($deposit->fresh())) {
                $this->release(10);
            }

            return;
        }

        $open = in_array($deposit->status, [DepositStatus::Pending, DepositStatus::Processing], true);

        if ($open && $deposit->created_at->gt(now()->subMinutes(60))) {
            $this->release(10);
        }
    }
}
