<?php

namespace App\Console\Commands;

use App\Enums\ProviderOrderStatus;
use App\Models\ProviderOrder;
use App\Notifications\OrderNeedsReviewNotification;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every few minutes. Tells staff once about each order that stopped automatic handling.
 */
class AlertStaffCommand extends Command
{
    protected $signature = 'ops:alert';

    protected $description = 'Notify staff about orders that need a manual decision.';

    public function handle(): int
    {
        $sent = 0;

        ProviderOrder::query()
            ->where('status', ProviderOrderStatus::Review->value)
            ->with('order:id,order_number')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ProviderOrder $po) use (&$sent) {
                // Once per order per week, however often this runs.
                if (! $po->order || ! Cache::add('alert:review:'.$po->id, 1, now()->addDays(7))) {
                    return;
                }

                StaffNotifier::send(new OrderNeedsReviewNotification($po->order->order_number, $po->id, $po->last_error));
                $sent++;
            });

        $this->line("Alerted staff about {$sent} order(s).");

        return self::SUCCESS;
    }
}