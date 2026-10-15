<?php

namespace App\Jobs;

use App\Services\TopUp\OrderProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFazerCardsOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Retries are decided by OrderProcessor (same idempotency key, backoff), not by the queue.
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $providerOrderId)
    {
        $this->onQueue('orders');
    }

    public function handle(OrderProcessor $processor): void
    {
        $processor->submit($this->providerOrderId);
    }

    public function failed(Throwable $exception): void
    {
        // The provider order stays "submitting": orders:process-pending re-sends it later with the SAME key.
        Log::error('Order submit job crashed', ['provider_order' => $this->providerOrderId, 'error' => $exception->getMessage()]);
    }
}