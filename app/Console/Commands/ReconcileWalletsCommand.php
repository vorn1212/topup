<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileWalletsCommand extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Verify every wallet balance equals its ledger and every ledger row balances arithmetically.';

    public function handle(AuditService $audit): int
    {
        $mismatched = [];

        $rows = DB::table('wallets as w')
            ->leftJoin('wallet_transactions as t', function ($join) {
                $join->on('t.wallet_id', '=', 'w.id')->where('t.status', '=', 'completed');
            })
            ->groupBy('w.id', 'w.user_id', 'w.balance')
            ->selectRaw("w.id, w.user_id, w.balance, COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount WHEN t.direction = 'debit' THEN -t.amount ELSE 0 END), 0) AS ledger")
            ->cursor();

        $checked = 0;
        foreach ($rows as $row) {
            $checked++;
            if (Money::cmp((string) $row->balance, (string) $row->ledger) !== 0) {
                $mismatched[] = [
                    'wallet_id' => (int) $row->id,
                    'user_id' => (int) $row->user_id,
                    'balance' => (string) $row->balance,
                    'ledger' => (string) $row->ledger,
                ];
            }
        }

        $brokenRows = DB::table('wallet_transactions')
            ->where(function ($q) {
                $q->where('direction', 'credit')->whereRaw('balance_after <> balance_before + amount');
            })
            ->orWhere(function ($q) {
                $q->where('direction', 'debit')->whereRaw('balance_after <> balance_before - amount');
            })
            ->pluck('id')
            ->all();

        $this->info("Wallets checked: {$checked}");

        if ($mismatched === [] && $brokenRows === []) {
            $this->info('OK: all wallets reconcile with the ledger.');

            return self::SUCCESS;
        }

        foreach ($mismatched as $m) {
            $this->error("Wallet #{$m['wallet_id']} (user {$m['user_id']}): balance {$m['balance']} but ledger {$m['ledger']}");
        }
        if ($brokenRows !== []) {
            $this->error('Ledger rows with inconsistent balance_before/after: '.implode(', ', $brokenRows));
        }

        Log::critical('wallet:reconcile found mismatches', [
            'wallets' => $mismatched,
            'ledger_rows' => $brokenRows,
        ]);

        $audit->log('wallet.reconcile_mismatch', null, [], [
            'wallets' => $mismatched,
            'ledger_rows' => $brokenRows,
        ]);

        return self::FAILURE;
    }
}