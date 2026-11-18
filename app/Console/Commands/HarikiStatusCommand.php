<?php

namespace App\Console\Commands;

use App\Exceptions\ProviderException;
use App\Services\TopUp\HarikiService;
use App\Support\Money;
use Illuminate\Console\Command;

class HarikiStatusCommand extends Command
{
    protected $signature = 'hariki:status';

    protected $description = 'Check the Hariki API key and show the provider account and balance.';

    public function handle(HarikiService $provider): int
    {
        if (! $provider->isConfigured()) {
            $this->error('HARIKI_API_KEY is not set in .env (then run: php artisan config:clear).');

            return self::FAILURE;
        }

        try {
            $me = $provider->getProfile();
            $balance = $provider->getBalance();
        } catch (ProviderException $e) {
            $this->error('Hariki check failed: '.$e->getMessage().($e->httpStatus ? " (HTTP {$e->httpStatus})" : ''));

            if ($e->isAuthError()) {
                $this->warn('The API key was rejected. Check HARIKI_API_KEY.');
            }

            return self::FAILURE;
        }

        $active = ($me['subscriptionActive'] ?? false) === true;

        $this->info(sprintf(
            'Connected as %s, plan %s, subscription %s.',
            $me['login'] ?? '?',
            $me['plan'] ?? '?',
            $active ? 'active' : 'INACTIVE'
        ));
        $this->line("Provider balance: {$balance['balance']} {$balance['currency']}");

        if (! $active) {
            $this->warn('The subscription is inactive: the provider may refuse catalog and order requests (HTTP 403).');
        }

        if (Money::cmp($balance['balance'], (string) config('hariki.low_balance', '20.00')) < 0) {
            $this->warn('The provider balance is low. Top it up in the Hariki panel or orders will fail.');
        }

        return self::SUCCESS;
    }
}