<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 bridge: reads wallet/order data with the query builder and returns
 * safe defaults while those tables do not exist yet (they arrive in Phases 3
 * and 7). Phase 3/7 replace this with Eloquent models.
 */
class DashboardService
{
    public function forUser(User $user): array
    {
        $stats = [
            'balance' => '0.0000',
            'currency' => config('topup.currency', 'USD'),
            'total_orders' => 0,
            'pending_orders' => 0,
            'completed_orders' => 0,
            'recent_orders' => collect(),
            'recent_transactions' => collect(),
        ];

        if (Schema::hasTable('wallets')) {
            $wallet = DB::table('wallets')->where('user_id', $user->id)->first(['balance', 'currency']);
            if ($wallet) {
                $stats['balance'] = (string) $wallet->balance;
                $stats['currency'] = $wallet->currency;
            }
        }

        if (Schema::hasTable('orders')) {
            $counts = DB::table('orders')
                ->where('user_id', $user->id)
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw("SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending")
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed")
                ->first();

            $stats['total_orders'] = (int) ($counts->total ?? 0);
            $stats['pending_orders'] = (int) ($counts->pending ?? 0);
            $stats['completed_orders'] = (int) ($counts->completed ?? 0);

            $stats['recent_orders'] = $this->recent('orders', $user, [
                'order_number', 'game_name', 'product_name', 'selling_price', 'status', 'created_at',
            ]);
        }

        if (Schema::hasTable('wallet_transactions')) {
            $stats['recent_transactions'] = $this->recent('wallet_transactions', $user, [
                'type', 'direction', 'amount', 'description', 'status', 'created_at',
            ]);
        }

        return $stats;
    }

    private function recent(string $table, User $user, array $columns): Collection
    {
        return DB::table($table)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get($columns);
    }
}