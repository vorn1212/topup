<?php

namespace App\Console\Commands;

use App\Jobs\CheckFazerCardsOrderStatusJob;
use App\Jobs\ProcessFazerCardsOrderJob;
use App\Models\ProviderOrder;
use Illuminate\Console\Command;

/**
 * Runs every minute. Re-sends orders that were never sent, are due a retry, or were abandoned
 * mid-send (always with the same idempotency key), and polls orders the provider is working on.
 */
class ProcessPendingOrdersCommand extends Command
{
    protected $signature = 'orders:process-pending';

    protected $description = 'Send missed or retry-due provider orders and check processing ones.';

    public function handle(): int
    {
        $now = now();

        $submit = ProviderOrder::query()
            ->where(function ($query) use ($now) {
                $query
                    ->where(fn ($w) => $w->where('status', 'queued')->where('updated_at', '<=', $now->copy()->subSeconds(45)))
                    ->orWhere(fn ($w) => $w->where('status', 'unknown')->where('next_check_at', '<=', $now))
                    ->orWhere(fn ($w) => $w->where('status', 'submitting')->where('updated_at', '<=', $now->copy()->subMinutes(3)));
            })
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'status']);

        foreach ($submit as $row) {
            ProcessFazerCardsOrderJob::dispatch($row->id);
        }

        // Do not dispatch the same order again every minute while a worker is busy or down.
        ProviderOrder::query()->whereIn('id', $submit->where('status', 'queued')->pluck('id'))->update(['updated_at' => $now]);
        ProviderOrder::query()->whereIn('id', $submit->where('status', 'unknown')->pluck('id'))->update(['next_check_at' => $now->copy()->addSeconds(60)]);

        $check = ProviderOrder::query()
            ->where('status', 'processing')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($check as $id) {
            CheckFazerCardsOrderStatusJob::dispatch($id);
        }

        ProviderOrder::query()->whereIn('id', $check)->update(['next_check_at' => $now->copy()->addSeconds(45)]);

        $this->line("Queued {$submit->count()} send(s) and {$check->count()} status check(s).");

        return self::SUCCESS;
    }
}