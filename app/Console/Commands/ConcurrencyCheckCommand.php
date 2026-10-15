<?php

namespace App\Console\Commands;

use App\Enums\WalletTxType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Real double-spend test: several PHP processes debit the SAME wallet at the same time.
 * With correct locking only floor(funds / amount) debits succeed and the balance never goes negative.
 * Local/testing environments only. Uses (and cleans up) a temporary probe user.
 */
class ConcurrencyCheckCommand extends Command
{
    protected $signature = 'wallet:concurrency-check {--workers=6} {--amount=3.00} {--funds=10.00}';

    protected $description = 'Spawn parallel processes that try to overspend one wallet (local/testing only).';

    public function handle(WalletService $wallets): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command only runs in local/testing environments.');

            return self::FAILURE;
        }

        $workers = max(2, (int) $this->option('workers'));
        $amount = (string) $this->option('amount');
        $funds = (string) $this->option('funds');
        $run = Str::lower(Str::random(8));

        $user = new User;
        $user->forceFill([
            'name' => 'Concurrency Probe',
            'email' => "probe-{$run}@topup.test",
            'password' => Str::random(32),
        ])->save();

        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

        try {
            $wallets->credit($wallet, $funds, "probe:fund:{$run}", WalletTxType::Bonus, null, 'Probe funding');

            $this->info("Wallet #{$wallet->id} funded with {$funds}. Starting {$workers} parallel debits of {$amount}...");

            $processes = [];
            for ($i = 1; $i <= $workers; $i++) {
                $process = new Process(
                    [PHP_BINARY, base_path('artisan'), 'wallet:probe-debit', (string) $wallet->id, $amount, "probe:{$run}:{$i}"],
                    base_path()
                );
                $process->setTimeout(120);
                $process->start();
                $processes[] = $process;
            }

            $ok = 0;
            $insufficient = 0;
            $errors = 0;

            foreach ($processes as $process) {
                $process->wait();
                $output = $process->getOutput();

                if (str_contains($output, 'RESULT:OK')) {
                    $ok++;
                } elseif (str_contains($output, 'RESULT:INSUFFICIENT')) {
                    $insufficient++;
                } else {
                    $errors++;
                    $this->warn('Worker problem: '.trim($output.' '.$process->getErrorOutput()));
                }
            }

            $wallet->refresh();

            $expectedOk = min($workers, (int) bcdiv($funds, $amount, 0));
            $expectedBalance = Money::sub($funds, Money::mul((string) $expectedOk, $amount));

            $this->line("Succeeded: {$ok} (expected {$expectedOk}) | Rejected for funds: {$insufficient} | Errors: {$errors}");
            $this->line("Final balance: {$wallet->balance} (expected {$expectedBalance})");

            $passed = $ok === $expectedOk
                && $errors === 0
                && Money::cmp((string) $wallet->balance, $expectedBalance) === 0
                && ! Money::isNegative((string) $wallet->balance);

            $passed
                ? $this->info('PASS: no double spend, balance never negative.')
                : $this->error('FAIL: concurrency problem detected.');

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            // Cleanup with the query builder (the ledger model itself refuses deletes).
            $txIds = DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->pluck('id');

            DB::table('audit_logs')
                ->where('subject_type', 'App\\Models\\WalletTransaction')
                ->whereIn('subject_id', $txIds)
                ->delete();
            DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->delete();
            DB::table('wallets')->where('id', $wallet->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }
}