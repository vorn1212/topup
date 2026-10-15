<?php

namespace App\Jobs;

use App\Services\TopUp\OrderProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckFazerCardsOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $providerOrderId)
    {
        $this->onQueue('orders');
    }

    public function handle(OrderProcessor $processor): void
    {
        $processor->check($this->providerOrderId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Order status job crashed', ['provider_order' => $this->providerOrderId, 'error' => $exception->getMessage()]);
    }
}