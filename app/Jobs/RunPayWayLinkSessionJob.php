<?php

namespace App\Jobs;

use App\Services\Payment\PayWayLinkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class RunPayWayLinkSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Never retry: a retry could open a second QR for a deposit that may already be paid.
    public int $tries = 1;

    public int $timeout = 380;

    public function __construct(public int $depositId)
    {
        $this->onQueue((string) config('payway_link.queue', 'payway'));
    }

    public function handle(PayWayLinkService $service): void
    {
        $service->run($this->depositId);
    }

    public function failed(Throwable $exception): void
    {
        app(PayWayLinkService::class)->handleCrash($this->depositId, $exception);
    }
}