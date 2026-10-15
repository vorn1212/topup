<?php

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ledger integrity check: wallet balance must equal the sum of its ledger.
Schedule::command('wallet:reconcile')->dailyAt('03:00')->withoutOverlapping();

// Provider catalog and cost sync (only when an API key is configured).
Schedule::command('fazercards:sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->when(fn () => filled(config('fazercards.api_key')));

// Orders: send missed/retry-due provider orders and poll the ones being processed.
Schedule::command('orders:process-pending')->everyMinute()->withoutOverlapping(5);

// Provider wallet: remember the balance (blocks orders that would fail) and warn staff when it is low.
Schedule::command('fazercards:check-balance')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->when(fn () => filled(config('fazercards.api_key')) && ! config('fazercards.fake'));

// Tell staff once about every order that is waiting for a manual decision.
Schedule::command('ops:alert')->everyFiveMinutes()->withoutOverlapping(5);

// Proof of life for ops:check and /admin/health: the scheduler leaves a timestamp, and sends a tiny job to every queue.
// When the queue worker handles those jobs it leaves its own timestamp. No timestamp = that part is not running.
Schedule::call(function () {
    Cache::put('ops:scheduler-heartbeat', now()->getTimestamp(), now()->addHour());

    foreach (array_unique(['default', (string) config('payway_link.queue', 'payway')]) as $queue) {
        QueueHeartbeatJob::dispatch($queue)->onQueue($queue);
    }
})->name('ops-heartbeat')->everyMinute();