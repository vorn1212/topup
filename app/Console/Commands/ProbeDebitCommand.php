<?php

namespace App\Console\Commands;

use App\Enums\WalletTxType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Internal helper used by wallet:concurrency-check. Not for manual use.
 */
class ProbeDebitCommand extends Command
{
    protected $signature = 'wallet:probe-debit {wallet} {amount} {key}';

    protected $description = 'Internal: attempt one debit (used by wallet:concurrency-check).';

    protected $hidden = true;

    public function handle(WalletService $wallets): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Not available in this environment.');

            return self::FAILURE;
        }

        try {
            $wallet = Wallet::query()->findOrFail((int) $this->argument('wallet'));

            $wallets->debit(
                $wallet,
                (string) $this->argument('amount'),
                (string) $this->argument('key'),
                WalletTxType::Purchase,
                null,
                'Concurrency probe',
            );

            $this->line('RESULT:OK');
        } catch (InsufficientBalanceException) {
            $this->line('RESULT:INSUFFICIENT');
        } catch (Throwable $e) {
            $this->line('RESULT:ERROR '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}