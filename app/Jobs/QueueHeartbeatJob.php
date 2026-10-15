<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Sent by the scheduler every minute to each queue the shop uses. When the queue worker handles it,
 * it leaves a timestamp. ops:check and /admin/health read that timestamp, so "the worker is not running"
 * (or "it does not read the payway queue") is noticed instead of silently losing deposits and orders.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $queueName = 'default')
    {
    }

    public static function key(string $queueName): string
    {
        return 'ops:queue-heartbeat:'.$queueName;
    }

    public function handle(): void
    {
        Cache::put(self::key($this->queueName), now()->getTimestamp(), now()->addHour());
    }
}