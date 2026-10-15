<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\Admin\ReportService;
use App\Services\TopUp\OrderService;
use App\Services\TopUp\OrderStateMachine;
use App\Services\TopUp\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop('50.00');
        $this->reports = app(ReportService::class);
    }

    // ---------------------------------------------------------------- helpers

    /** Each order from setUpShop costs 0.80, sells for 1.00 and earns 0.20. */
    private function completedOrder(): Order
    {
        $po = $this->placeOrder();
        $this->moveOrder($po, OrderStatus::Processing);
        $this->moveOrder($po, OrderStatus::Completed);

        return Order::findOrFail($po->order_id);
    }

    private function refundedOrder(): Order
    {
        $po = $this->placeOrder();
        $this->moveOrder($po, OrderStatus::Processing);

        return app(RefundService::class)->failAndRefund(Order::findOrFail($po->order_id), 'test refund');
    }

    private function paidDeposit(string $amount, string $status = 'paid', ?Carbon $paidAt = null): void
    {
        DB::table('wallet_deposits')->insert([
            'user_id' => $this->customer->id,
            'wallet_id' => Wallet::query()->where('user_id', $this->customer->id)->value('id'),
            'deposit_number' => 'DEP-'.Str::upper(Str::random(10)),
            'amount' => $amount,
            'currency' => 'USD',
            'payment_reference' => 'ref-'.Str::random(20),
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($paidAt ?? now()) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{from:Carbon,to:Carbon,preset:string,notice:?string} */
    private function last30(): array
    {
        return $this->reports->resolveRange('30d', null, null);
    }

    private function summary(?array $range = null): array
    {
        $range ??= $this->last30();

        return $this->reports->summary($range['from'], $range['to']);
    }

    // ---------------------------------------------------------------- access

    public function test_only_staff_with_the_reports_permission_can_open_reports_and_downloads(): void
    {
        $urls = ['/admin/reports', '/admin/reports/export/daily', '/admin/reports/export/orders'];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        foreach ($urls as $url) {
            $this->actingAs($this->customer)->get($url)->assertForbidden();
            $this->actingAs($this->staff('support'))->get($url)->assertForbidden();
        }

        $this->actingAs($this->staff('admin'))->get('/admin/reports')->assertOk();
        $this->actingAs($this->staff('super_admin'))->get('/admin/reports')->assertOk();
    }

    public function test_reports_are_read_only(): void
    {
        $this->actingAs($this->staff('admin'))->post('/admin/reports')->assertStatus(405);
        $this->actingAs($this->staff('admin'))->delete('/admin/reports')->assertStatus(405);
    }

    public function test_the_menu_shows_the_links_only_to_those_who_may_use_them(): void
    {
        $this->actingAs($this->staff('admin'))->get('/admin/orders')
            ->assertOk()
            ->assertSee(route('admin.reports.index'))
            ->assertSee(route('admin.audit.index'));

        $this->actingAs($this->staff('support'))->get('/admin/orders')
            ->assertOk()
            ->assertDontSee(route('admin.reports.index'))
            ->assertDontSee(route('admin.audit.index'));
    }

    // ---------------------------------------------------------------- numbers

    public function test_the_summary_adds_up_sales_profit_refunds_and_open_orders(): void
    {
        $this->completedOrder();
        $this->completedOrder();
        $this->refundedOrder();
        $this->placeOrder(); // still pending

        $s = $this->summary();

        $this->assertSame(2, $s['orders']);
        $this->assertSame('2.0000', $s['revenue']);
        $this->assertSame('1.6000', $s['cost']);
        $this->assertSame('0.4000', $s['profit']);
        $this->assertSame('20.00', $s['margin']);
        $this->assertSame('1.0000', $s['average']);

        $this->assertSame(4, $s['created']);
        $this->assertSame(2, $s['completed']);
        $this->assertSame(1, $s['refunded']);
        $this->assertSame('1.0000', $s['refunded_amount']);
        $this->assertSame(0, $s['failed']);
        $this->assertSame(1, $s['open']);
        $this->assertSame('50.00', $s['completion_rate']);
        $this->assertSame('25.00', $s['refund_rate']);
        $this->assertSame('0.00', $s['failure_rate']);
    }

    public function test_an_empty_period_shows_zeros_and_never_divides_by_zero(): void
    {
        $s = $this->summary();

        $this->assertSame(0, $s['orders']);
        $this->assertSame('0.0000', $s['revenue']);
        $this->assertSame('0.0000', $s['profit']);
        $this->assertSame('0.00', $s['margin']);
        $this->assertSame('0.0000', $s['average']);
        $this->assertSame(0, $s['created']);
        $this->assertSame('0.00', $s['completion_rate']);
        $this->assertSame('0.00', $s['refund_rate']);
        $this->assertSame('0.00', $s['failure_rate']);
        $this->assertSame(0, $s['deposits']);
        $this->assertSame('0.0000', $s['deposited']);

        $this->actingAs($this->staff('admin'))->get('/admin/reports')->assertOk()->assertSee('No completed orders in this period.');
    }

    public function test_a_sale_below_cost_shows_a_negative_profit_and_margin(): void
    {
        $order = $this->completedOrder();
        DB::table('orders')->where('id', $order->id)->update(['selling_price' => '0.5000', 'profit' => '-0.3000']);

        $s = $this->summary();

        $this->assertSame('0.5000', $s['revenue']);
        $this->assertSame('-0.3000', $s['profit']);
        $this->assertSame('-60.00', $s['margin']);
        $this->assertStringStartsWith('-', $this->reports->money($s['profit']));

        $this->actingAs($this->staff('admin'))->get('/admin/reports')->assertOk()->assertSee($this->reports->money('-0.3000'));
    }

    public function test_the_period_decides_which_sales_are_counted(): void
    {
        $old = $this->completedOrder();
        DB::table('orders')->where('id', $old->id)->update(['completed_at' => now()->subDays(40), 'created_at' => now()->subDays(41)]);
        $this->completedOrder();

        $this->assertSame(1, $this->summary()['orders']);

        $wide = $this->reports->resolveRange('custom', now()->subDays(60)->format('Y-m-d'), now()->format('Y-m-d'));
        $this->assertSame(2, $this->summary($wide)['orders']);
        $this->assertSame('2.0000', $this->summary($wide)['revenue']);

        // Only the old order.
        $only = $this->reports->resolveRange('custom', now()->subDays(45)->format('Y-m-d'), now()->subDays(35)->format('Y-m-d'));
        $this->assertSame(1, $this->summary($only)['orders']);
    }

    public function test_deposits_count_only_paid_ones_in_the_period(): void
    {
        $this->paidDeposit('5.00');
        $this->paidDeposit('2.50');
        $this->paidDeposit('9.00', 'pending');
        $this->paidDeposit('4.00', 'paid', now()->subDays(50));

        $s = $this->summary();

        $this->assertSame(2, $s['deposits']);
        $this->assertSame('7.5000', $s['deposited']);
    }

    public function test_customer_wallet_balances_are_totalled(): void
    {
        $this->completedOrder(); // 1.00 spent from 50.00

        $s = $this->summary();

        $this->assertSame(0, bccomp($s['wallets_total'], $this->customerBalance(), 4));
        $this->assertSame(0, bccomp($s['wallets_total'], '49.0000', 4));
        $this->assertSame(1, $s['wallets_funded']);
        $this->assertGreaterThanOrEqual(1, $s['wallets_count']);
    }

    public function test_the_daily_table_has_one_row_per_day_and_adds_up_to_the_summary(): void
    {
        $this->completedOrder();
        $this->paidDeposit('3.00');

        $range = $this->reports->resolveRange('7d', null, null);
        $rows = $this->reports->daily($range['from'], $range['to']);

        $this->assertCount(7, $rows);
        $this->assertSame(now()->format('Y-m-d'), $rows->last()['day']);
        $this->assertSame(1, $rows->last()['orders']);
        $this->assertSame('1.0000', $rows->last()['revenue']);
        $this->assertSame('0.2000', $rows->last()['profit']);
        $this->assertSame('3.0000', $rows->last()['deposited']);
        $this->assertSame(0, $rows->first()['orders']);
        $this->assertSame('0.0000', $rows->first()['revenue']);

        $revenue = $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['revenue'], 4), '0.0000');
        $this->assertSame($this->summary($range)['revenue'], $revenue);
    }

    public function test_the_top_lists_rank_by_revenue(): void
    {
        $this->completedOrder(); // pubg: 1.00 / 0.20

        $game = $this->makeGame(['slug' => 'free-fire', 'provider_category_id' => 'cat_ff_1']);
        $this->makeField($game);
        $product = $this->makeProduct($game, '2.00', MarkupType::FixedMarkup, '0.50', ['provider_offer_id' => 'offer_ff']);

        $order = app(OrderService::class)->create($this->customer, 'free-fire', $product->id, ['player_id' => '123456789']);
        OrderStateMachine::transition(Order::findOrFail($order->id), OrderStatus::Processing);
        OrderStateMachine::transition(Order::findOrFail($order->id), OrderStatus::Completed);

        $range = $this->last30();

        $games = $this->reports->topGames($range['from'], $range['to']);
        $this->assertCount(2, $games);
        $this->assertSame($game->name, $games[0]['name']);
        $this->assertSame('2.5000', $games[0]['revenue']);
        $this->assertSame('0.5000', $games[0]['profit']);
        $this->assertSame(1, $games[1]['orders']);

        $products = $this->reports->topProducts($range['from'], $range['to']);
        $this->assertSame($product->id, $products[0]['id']);
        $this->assertSame($game->name, $products[0]['game']);

        $customers = $this->reports->topCustomers($range['from'], $range['to']);
        $this->assertCount(1, $customers);
        $this->assertSame($this->customer->email, $customers[0]['email']);
        $this->assertSame(2, $customers[0]['orders']);
        $this->assertSame('3.5000', $customers[0]['revenue']);
        $this->assertSame('0.7000', $customers[0]['profit']);
    }

    public function test_the_page_shows_the_numbers(): void
    {
        $this->completedOrder();
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get('/admin/reports')
            ->assertOk()
            ->assertSee('Day by day')
            ->assertSee('Top customers')
            ->assertSee($this->reports->money('1.0000'))
            ->assertSee($this->reports->money('0.2000'))
            ->assertSee($this->customer->email)
            ->assertSee('Margin 20.00%');

        $this->actingAs($admin)->get('/admin/reports?preset=7d')->assertOk();
        $this->actingAs($admin)->get('/admin/reports?preset=today')->assertOk();
        $this->actingAs($admin)->get('/admin/reports?preset=month')->assertOk();
    }

    // ---------------------------------------------------------------- the period

    public function test_the_presets_pick_the_right_dates(): void
    {
        $today = $this->reports->resolveRange('today', null, null);
        $this->assertSame(now()->format('Y-m-d'), $today['from']->format('Y-m-d'));
        $this->assertSame(now()->format('Y-m-d'), $today['to']->format('Y-m-d'));
        $this->assertSame('00:00:00', $today['from']->format('H:i:s'));
        $this->assertSame('23:59:59', $today['to']->format('H:i:s'));

        $week = $this->reports->resolveRange('7d', null, null);
        $this->assertSame(now()->subDays(6)->format('Y-m-d'), $week['from']->format('Y-m-d'));

        $month = $this->reports->resolveRange('month', null, null);
        $this->assertSame(now()->startOfMonth()->format('Y-m-d'), $month['from']->format('Y-m-d'));

        $default = $this->reports->resolveRange(null, null, null);
        $this->assertSame('30d', $default['preset']);
        $this->assertSame(now()->subDays(29)->format('Y-m-d'), $default['from']->format('Y-m-d'));
        $this->assertNull($default['notice']);
    }

    public function test_bad_dates_never_break_the_page(): void
    {
        $bogus = $this->reports->resolveRange('nonsense', 'zzz', '2026-13-45');
        $this->assertSame('30d', $bogus['preset']);

        $custom = $this->reports->resolveRange('custom', 'zzz', '2026-13-45');
        $this->assertSame('custom', $custom['preset']);
        $this->assertNotNull($custom['notice']);

        $arrays = $this->reports->resolveRange(['x'], ['y'], ['z']);
        $this->assertSame('30d', $arrays['preset']);

        $admin = $this->staff('admin');
        $this->actingAs($admin)->get('/admin/reports?preset=custom&from=zzz&to=2026-13-45')->assertOk()->assertSee('was not understood');
        $this->actingAs($admin)->get('/admin/reports?preset[]=x&from[]=y&to[]=z')->assertOk();
    }

    public function test_dates_in_the_wrong_order_are_swapped(): void
    {
        $range = $this->reports->resolveRange('custom', '2026-10-10', '2026-10-01');

        $this->assertSame('2026-10-01', $range['from']->format('Y-m-d'));
        $this->assertSame('2026-10-10', $range['to']->format('Y-m-d'));
        $this->assertNotNull($range['notice']);
    }

    public function test_a_huge_period_is_limited(): void
    {
        $range = $this->reports->resolveRange('custom', '2000-01-01', now()->format('Y-m-d'));

        $this->assertNotNull($range['notice']);
        $this->assertCount(ReportService::MAX_DAYS, $this->reports->daily($range['from'], $range['to']));
        $this->assertSame(now()->format('Y-m-d'), $range['to']->format('Y-m-d'));

        $this->actingAs($this->staff('admin'))->get('/admin/reports?preset=custom&from=2000-01-01')->assertOk()->assertSee('limited to');
    }

    // ---------------------------------------------------------------- CSV downloads

    public function test_the_orders_download_lists_completed_orders(): void
    {
        $order = $this->completedOrder();
        $this->placeOrder(); // pending: not in the file

        $response = $this->actingAs($this->staff('admin'))->get('/admin/reports/export/orders');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('report-orders-', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Order number', $csv);
        $this->assertStringContainsString($order->order_number, $csv);
        $this->assertStringContainsString($this->customer->email, $csv);
        $this->assertStringContainsString('1.0000', $csv);
        $this->assertSame(2, count(array_filter(preg_split('/\R/', trim($csv)))));
    }

    public function test_every_download_works_and_is_written_to_the_audit_log(): void
    {
        $this->completedOrder();
        $admin = $this->staff('admin');

        foreach (['daily', 'games', 'products', 'customers', 'orders'] as $type) {
            $response = $this->actingAs($admin)->get('/admin/reports/export/'.$type.'?preset=30d');
            $response->assertOk();
            $this->assertStringContainsString("report-{$type}-", (string) $response->headers->get('Content-Disposition'));
            $this->assertNotSame('', $response->streamedContent());
        }

        $this->assertSame(5, AuditLog::query()->where('action', 'report.exported')->count());

        $entry = AuditLog::query()->where('action', 'report.exported')->latest('id')->first();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('orders', $entry->new_values['type']);
        $this->assertSame(now()->subDays(29)->format('Y-m-d'), $entry->new_values['from']);
    }

    public function test_the_download_follows_the_chosen_period(): void
    {
        $old = $this->completedOrder();
        DB::table('orders')->where('id', $old->id)->update(['completed_at' => now()->subDays(40)]);
        $recent = $this->completedOrder();
        $admin = $this->staff('admin');

        $csv = $this->actingAs($admin)->get('/admin/reports/export/orders?preset=30d')->streamedContent();
        $this->assertStringContainsString($recent->order_number, $csv);
        $this->assertStringNotContainsString($old->order_number, $csv);

        $from = now()->subDays(60)->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $csv = $this->actingAs($admin)->get("/admin/reports/export/orders?preset=custom&from={$from}&to={$to}")->streamedContent();
        $this->assertStringContainsString($recent->order_number, $csv);
        $this->assertStringContainsString($old->order_number, $csv);
    }

    public function test_spreadsheet_formulas_in_names_are_neutralised(): void
    {
        $this->customer->forceFill(['name' => '=cmd|calc'])->save();
        $this->completedOrder();

        $csv = $this->actingAs($this->staff('admin'))->get('/admin/reports/export/customers')->streamedContent();

        $this->assertStringContainsString(",'=cmd|calc,", $csv);
        $this->assertStringNotContainsString(',=cmd|calc', $csv);
    }

    public function test_unknown_download_types_are_not_found(): void
    {
        $this->actingAs($this->staff('admin'))->get('/admin/reports/export/users')->assertNotFound();
        $this->actingAs($this->staff('admin'))->get('/admin/reports/export/..%2F..%2Fenv')->assertNotFound();
    }

    public function test_downloads_are_rate_limited(): void
    {
        $admin = $this->staff('admin');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)->get('/admin/reports/export/daily')->assertOk();
        }

        $this->actingAs($admin)->get('/admin/reports/export/daily')->assertStatus(429);
    }

    public function test_money_is_formatted_for_the_screen(): void
    {
        $this->assertStringContainsString('0.00', $this->reports->money('0'));
        $this->assertStringContainsString('1.00', $this->reports->money('1.0000'));
        $this->assertStringNotContainsString('-', $this->reports->money('1.0000'));
        $this->assertStringStartsWith('-', $this->reports->money('-0.2000'));
        $this->assertStringContainsString('0.20', $this->reports->money('-0.2000'));
    }
}