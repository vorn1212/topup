<?php

namespace App\Console\Commands;

use App\Services\TopUp\ProviderBalanceMonitor;
use App\Support\Money;
use Illuminate\Console\Command;

class CheckProviderBalanceCommand extends Command
{
    protected $signature = 'fazercards:check-balance';

    protected $description = 'Read the FazerCards balance, remember it, and warn staff when it is low.';

    public function handle(ProviderBalanceMonitor $monitor): int
    {
        if (! $monitor->enabled()) {
            $this->line('Skipped: no API key, or the fake provider is on.');

            return self::SUCCESS;
        }

        $snapshot = $monitor->refresh();

        if ($snapshot === null) {
            $this->error('Could not read the provider balance (see the log).');

            return self::FAILURE;
        }

        $this->info('Provider balance: '.Money::format($snapshot['balance'], $snapshot['currency']).($monitor->isLow($snapshot) ? '  (LOW)' : ''));

        return self::SUCCESS;
    }
}