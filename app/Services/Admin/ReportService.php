<?php

namespace App\Services\Admin;

use App\Enums\DepositStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletDeposit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only numbers for the admin reports.
 *
 *  - Money is summed by the database (DECIMAL) and handled as strings with bcmath. Never floats.
 *  - Sales (revenue, cost, profit) are counted on the day the top-up was COMPLETED.
 *  - Outcomes (completed, refunded, failed, open) look at orders CREATED in the period.
 *  - Deposits are counted on the day they were PAID.
 *  - Only the shop currency (config topup.currency) is added up, so two currencies are never mixed.
 */
class ReportService
{
    public const MAX_DAYS = 366;

    public const PRESETS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'custom' => 'Custom range',
    ];

    public function currency(): string
    {
        return (string) config('topup.currency', 'USD');
    }

    /**
     * Turn the (untrusted) query string into a safe date range.
     *
     * @return array{from:Carbon,to:Carbon,preset:string,notice:?string}
     */
    public function resolveRange(mixed $preset, mixed $from, mixed $to): array
    {
        $now = now();
        $notice = null;

        $preset = is_string($preset) && array_key_exists($preset, self::PRESETS) ? $preset : null;
        $fromDate = $this->parseDate($from);
        $toDate = $this->parseDate($to);

        if ($preset === 'custom' || ($preset === null && ($fromDate !== null || $toDate !== null))) {
            $preset = 'custom';

            $wrong = (is_string($from) && $from !== '' && $fromDate === null)
                || (is_string($to) && $to !== '' && $toDate === null);

            if ($wrong) {
                $notice = 'A date was not understood and was ignored.';
            }

            $start = ($fromDate ?? $now->copy()->subDays(29))->startOfDay();
            $end = ($toDate ?? $now->copy())->endOfDay();

            if ($start->gt($end)) {
                $swapped = $start;
                $start = $end->copy()->startOfDay();
                $end = $swapped->copy()->endOfDay();
                $notice = 'The dates were in the wrong order, so they were swapped.';
            }
        } else {
            $preset ??= '30d';

            [$start, $end] = match ($preset) {
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
                'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
                default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            };
        }

        $length = (int) round($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay())) + 1;

        if ($length > self::MAX_DAYS) {
            $start = $end->copy()->subDays(self::MAX_DAYS - 1)->startOfDay();
            $notice = 'The period is limited to '.self::MAX_DAYS.' days. The start date was moved.';
        }

        return ['from' => $start, 'to' => $end, 'preset' => $preset, 'notice' => $notice];
    }

    /**
     * Accepts only a real calendar date written as YYYY-MM-DD.
     */
    public function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0);
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $sales = $this->completedOrders($from, $to)
            ->toBase()
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(selling_price),0) as revenue, COALESCE(SUM(provider_cost),0) as cost, COALESCE(SUM(profit),0) as profit')
            ->first();

        $orders = (int) ($sales->orders ?? 0);
        $revenue = $this->dec($sales->revenue ?? null);
        $cost = $this->dec($sales->cost ?? null);
        $profit = $this->dec($sales->profit ?? null);

        $byStatus = Order::query()
            ->where('currency', $this->currency())
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(selling_price),0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $count = fn (OrderStatus ...$statuses): int => (int) collect($statuses)->sum(fn (OrderStatus $s) => (int) ($byStatus[$s->value]->n ?? 0));
        $amount = function (OrderStatus ...$statuses) use ($byStatus): string {
            $total = '0.0000';
            foreach ($statuses as $s) {
                $total = bcadd($total, $this->dec($byStatus[$s->value]->amount ?? null), 4);
            }

            return $total;
        };

        $created = $count(...OrderStatus::cases());
        $completed = $count(OrderStatus::Completed);
        $refunded = $count(OrderStatus::Refunded, OrderStatus::Refunding);
        $failed = $count(OrderStatus::Failed, OrderStatus::Cancelled);
        $open = $count(OrderStatus::Pending, OrderStatus::Processing);

        $deposits = WalletDeposit::query()
            ->where('status', DepositStatus::Paid->value)
            ->where('currency', $this->currency())
            ->whereBetween('paid_at', [$from, $to])
            ->toBase()
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount),0) as total')
            ->first();

        $wallets = Wallet::query()
            ->where('currency', $this->currency())
            ->toBase()
            ->selectRaw('COALESCE(SUM(balance),0) as total, COUNT(*) as n, COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END),0) as funded')
            ->first();

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $this->percent($profit, $revenue),
            'average' => $orders > 0 ? bcdiv($revenue, (string) $orders, 4) : '0.0000',

            'created' => $created,
            'completed' => $completed,
            'refunded' => $refunded,
            'refunded_amount' => $amount(OrderStatus::Refunded, OrderStatus::Refunding),
            'failed' => $failed,
            'open' => $open,
            'completion_rate' => $this->percent($completed, $created),
            'refund_rate' => $this->percent($refunded, $created),
            'failure_rate' => $this->percent($failed, $created),

            'deposits' => (int) ($deposits->n ?? 0),
            'deposited' => $this->dec($deposits->total ?? null),

            'wallets_total' => $this->dec($wallets->total ?? null),
            'wallets_count' => (int) ($wallets->n ?? 0),
            'wallets_funded' => (int) ($wallets->funded ?? 0),
        ];
    }

    /**
     * One row for every day of the period (days without activity show zeros).
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function daily(Carbon $from, Carbon $to): Collection
    {
        $sales = $this->completedOrders($from, $to)
            ->toBase()
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as orders, COALESCE(SUM(selling_price),0) as revenue, COALESCE(SUM(provider_cost),0) as cost, COALESCE(SUM(profit),0) as profit')
            ->groupByRaw('DATE(completed_at)')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $deposits = WalletDeposit::query()
            ->where('status', DepositStatus::Paid->value)
            ->where('currency', $this->currency())
            ->whereBetween('paid_at', [$from, $to])
            ->toBase()
            ->selectRaw('DATE(paid_at) as day, COUNT(*) as n, COALESCE(SUM(amount),0) as total')
            ->groupByRaw('DATE(paid_at)')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $rows = collect();
        $day = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($day->lte($last)) {
            $key = $day->format('Y-m-d');
            $sale = $sales[$key] ?? null;
            $deposit = $deposits[$key] ?? null;

            $rows->push([
                'day' => $key,
                'orders' => (int) ($sale->orders ?? 0),
                'revenue' => $this->dec($sale->revenue ?? null),
                'cost' => $this->dec($sale->cost ?? null),
                'profit' => $this->dec($sale->profit ?? null),
                'deposits' => (int) ($deposit->n ?? 0),
                'deposited' => $this->dec($deposit->total ?? null),
            ]);

            $day->addDay();
        }

        return $rows;
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function topGames(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return $this->top($from, $to, 'game_id', 'MAX(game_name) as name', $limit)
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'orders' => (int) $row->orders,
                'revenue' => $this->dec($row->revenue),
                'cost' => $this->dec($row->cost),
                'profit' => $this->dec($row->profit),
            ])
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function topProducts(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        return $this->top($from, $to, 'product_id', 'MAX(game_name) as game, MAX(product_name) as name', $limit)
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'game' => (string) $row->game,
                'name' => (string) $row->name,
                'orders' => (int) $row->orders,
                'revenue' => $this->dec($row->revenue),
                'cost' => $this->dec($row->cost),
                'profit' => $this->dec($row->profit),
            ])
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function topCustomers(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        $rows = $this->top($from, $to, 'user_id', 'MAX(user_id) as customer_id', $limit);
        $users = User::query()->whereIn('id', $rows->pluck('id'))->get(['id', 'name', 'email'])->keyBy('id');

        return $rows
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) ($users[$row->id]->name ?? ''),
                'email' => (string) ($users[$row->id]->email ?? ''),
                'orders' => (int) $row->orders,
                'revenue' => $this->dec($row->revenue),
                'cost' => $this->dec($row->cost),
                'profit' => $this->dec($row->profit),
            ])
            ->values();
    }

    /**
     * Completed orders of the period with their customer, for the CSV export.
     *
     * @return Builder<Order>
     */
    public function exportOrders(Carbon $from, Carbon $to): Builder
    {
        return $this->completedOrders($from, $to)->with('user:id,email');
    }

    /**
     * Format a money string for the screen. Handles negative amounts (a sale below cost).
     */
    public function money(string $amount): string
    {
        $amount = $this->dec($amount);

        return bccomp($amount, '0', 4) < 0
            ? '-'.Money::display(ltrim($amount, '-'), $this->currency())
            : Money::display($amount, $this->currency());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return Builder<Order>
     */
    private function completedOrders(Carbon $from, Carbon $to): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Completed->value)
            ->where('currency', $this->currency())
            ->whereBetween('completed_at', [$from, $to]);
    }

    /**
     * $groupBy and $labels are fixed strings written in this class, never user input.
     */
    private function top(Carbon $from, Carbon $to, string $groupBy, string $labels, int $limit): Collection
    {
        return $this->completedOrders($from, $to)
            ->toBase()
            ->selectRaw("{$groupBy} as id, {$labels}, COUNT(*) as orders, COALESCE(SUM(selling_price),0) as revenue, COALESCE(SUM(provider_cost),0) as cost, COALESCE(SUM(profit),0) as profit")
            ->groupBy($groupBy)
            ->orderByRaw('SUM(selling_price) DESC')
            ->orderBy($groupBy)
            ->limit(max(1, min($limit, 5000)))
            ->get();
    }

    /**
     * A database number as a plain decimal string with 4 decimals.
     */
    private function dec(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        $value = (string) $value;

        return preg_match('/^-?\d+(\.\d+)?$/', $value) ? bcadd($value, '0', 4) : '0.0000';
    }

    /**
     * $part / $whole * 100 with 2 decimals (rounded down). 0.00 when there is no whole.
     */
    private function percent(int|string $part, int|string $whole): string
    {
        if (bccomp((string) $whole, '0', 4) === 0) {
            return '0.00';
        }

        return bcdiv(bcmul((string) $part, '100', 6), (string) $whole, 2);
    }
}