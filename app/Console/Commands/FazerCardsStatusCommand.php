<?php

namespace App\Console\Commands;

use App\Exceptions\ProviderException;
use App\Services\TopUp\FazerCardsService;
use App\Support\Money;
use Illuminate\Console\Command;

class FazerCardsStatusCommand extends Command
{
    protected $signature = 'fazercards:status';

    protected $description = 'Check the FazerCards API key and show the provider account and balance.';

    public function handle(FazerCardsService $provider): int
    {
        if (! $provider->isConfigured()) {
            $this->error('FAZERCARDS_API_KEY is not set in .env (then run: php artisan config:clear).');

            return self::FAILURE;
        }

        try {
            $me = $provider->getProfile();
            $balance = $provider->getBalance();
        } catch (ProviderException $e) {
            $this->error('FazerCards check failed: '.$e->getMessage().($e->httpStatus ? " (HTTP {$e->httpStatus})" : ''));

            if ($e->isAuthError()) {
                $this->warn('The API key was rejected. Check FAZERCARDS_API_KEY.');
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

        if (Money::cmp($balance['balance'], (string) config('fazercards.low_balance', '20.00')) < 0) {
            $this->warn('The provider balance is low. Top it up in the FazerCards panel or orders will fail.');
        }

        return self::SUCCESS;
    }
}