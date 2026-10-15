<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\ProviderOrder;
use App\Services\Payment\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class AdminOrdersAndListsTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    // ---------------------------------------------------------------- access

        public function test_customers_and_guests_cannot_open_any_admin_list(): void
    {
        $order = Order::find($this->placeOrder()->order_id);
        $urls = ['/admin/orders', '/admin/orders/'.$order->id, '/admin/deposits', '/admin/transactions'];

        // Guests first: once actingAs() is used, the test stays logged in.
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        foreach ($urls as $url) {
            $this->actingAs($this->customer)->get($url)->assertForbidden();
        }

        $this->actingAs($this->customer)->post('/admin/orders/'.$order->id.'/notes', ['body' => 'Hello there'])->assertForbidden();
    }

    // ---------------------------------------------------------------- orders list

    public function test_the_list_shows_orders_and_filters_them(): void
    {
        $first = Order::find($this->placeOrder()->order_id);
        $second = Order::find($this->reviewOrder()->order_id);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get('/admin/orders')->assertOk()->assertSee($first->order_number)->assertSee($second->order_number);

        // By order status: the first is still pending, the second is processing.
        $this->actingAs($admin)->get('/admin/orders?status=pending')->assertOk()->assertSee($first->order_number)->assertDontSee($second->order_number);

        // By provider status.
        $this->actingAs($admin)->get('/admin/orders?provider=review')->assertOk()->assertSee($second->order_number)->assertDontSee($first->order_number);

        // By text: order number, e-mail, player id.
        $this->actingAs($admin)->get('/admin/orders?q='.$first->order_number)->assertOk()->assertSee($first->order_number)->assertDontSee($second->order_number);
        $this->actingAs($admin)->get('/admin/orders?q='.urlencode($this->customer->email))->assertOk()->assertSee($first->order_number);
        $this->actingAs($admin)->get('/admin/orders?q=123456789')->assertOk()->assertSee($first->order_number);
        $this->actingAs($admin)->get('/admin/orders?q=nobody-matches-this')->assertOk()->assertSee('No orders found.');
    }

    public function test_the_date_filter_and_bad_input_are_handled(): void
    {
        $order = Order::find($this->placeOrder()->order_id);
        $admin = $this->staff('admin');
        $today = now()->format('Y-m-d');

        $this->actingAs($admin)->get("/admin/orders?from={$today}&to={$today}")->assertOk()->assertSee($order->order_number);
        $this->actingAs($admin)->get('/admin/orders?to='.now()->subDays(2)->format('Y-m-d'))->assertOk()->assertDontSee($order->order_number);
        $this->actingAs($admin)->get('/admin/orders?from=not-a-date&status=bogus&provider=bogus')->assertOk()->assertSee($order->order_number);
    }

    // ---------------------------------------------------------------- order detail

    public function test_the_detail_page_shows_money_timeline_and_provider_info(): void
    {
        $po = $this->processingOrder();
        $order = Order::find($po->order_id);

        $this->actingAs($this->staff('admin'))->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('123456789')
            ->assertSee('ord-9002')
            ->assertSee('Wallet entries of this order')
            ->assertSee('Purchase')
            ->assertSee('order.status_changed');
    }

    public function test_support_can_read_but_not_write_notes(): void
    {
        $order = Order::find($this->placeOrder()->order_id);
        $support = $this->staff('support');

        $this->actingAs($support)->get('/admin/orders/'.$order->id)->assertOk()->assertDontSee('Add note');
        $this->actingAs($support)->post('/admin/orders/'.$order->id.'/notes', ['body' => 'Support note'])->assertForbidden();

        $this->assertSame(0, OrderNote::count());
    }

    public function test_an_admin_can_add_notes_that_show_newest_first(): void
    {
        $order = Order::find($this->placeOrder()->order_id);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/notes', ['body' => 'Customer wrote on chat'])
            ->assertRedirect('/admin/orders/'.$order->id)
            ->assertSessionHas('status');
        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/notes', ['body' => 'Asked the provider'])->assertSessionHas('status');

        $this->actingAs($admin)->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSeeInOrder(['Asked the provider', 'Customer wrote on chat'])
            ->assertSee($admin->email);

        $this->assertSame(2, AuditLog::where('action', 'order.note_added')->count());
    }

    public function test_notes_are_validated_and_escaped(): void
    {
        $order = Order::find($this->placeOrder()->order_id);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/notes', ['body' => 'x'])->assertSessionHasErrors('body');
        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/notes', ['body' => str_repeat('a', 1001)])->assertSessionHasErrors('body');
        $this->assertSame(0, OrderNote::count());

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/notes', ['body' => '<script>alert(1)</script>'])->assertSessionHas('status');
        $this->actingAs($admin)->get('/admin/orders/'.$order->id)->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_notes_can_never_be_edited_or_deleted(): void
    {
        $order = Order::find($this->placeOrder()->order_id);
        $this->actingAs($this->staff('admin'))->post('/admin/orders/'.$order->id.'/notes', ['body' => 'Keep this forever']);
        $note = OrderNote::firstOrFail();

        try {
            $note->forceFill(['body' => 'Changed'])->save();
            $this->fail('A note must not be editable.');
        } catch (LogicException) {
            $this->assertSame('Keep this forever', $note->fresh()->body);
        }

        $this->expectException(LogicException::class);
        $note->delete();
    }

    public function test_an_order_in_review_links_to_the_decision_page(): void
    {
        $po = $this->reviewOrder();

        $this->actingAs($this->staff('admin'))->get('/admin/orders/'.$po->order_id)
            ->assertOk()
            ->assertSee('This order needs a decision')
            ->assertSee('/admin/attention/orders/'.$po->id);
    }

    // ---------------------------------------------------------------- deposits and ledger

    public function test_the_deposit_list_shows_and_filters_deposits(): void
    {
        $deposits = app(DepositService::class);
        $paid = $deposits->create($this->customer, '5.00');
        $deposits->markPaid($paid->id, 'ABA-TX-777');
        $pending = $deposits->create($this->customer, '7.00');
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get('/admin/deposits')->assertOk()->assertSee($paid->deposit_number)->assertSee($pending->deposit_number)->assertSee('ABA-TX-777');
        $this->actingAs($admin)->get('/admin/deposits?status=paid')->assertOk()->assertSee($paid->deposit_number)->assertDontSee($pending->deposit_number);
        $this->actingAs($admin)->get('/admin/deposits?q=ABA-TX-777')->assertOk()->assertSee($paid->deposit_number)->assertDontSee($pending->deposit_number);
        $this->actingAs($admin)->get('/admin/deposits?q='.urlencode($this->customer->email))->assertOk()->assertSee($pending->deposit_number);
    }

    public function test_support_can_view_deposits_and_the_ledger(): void
    {
        $this->placeOrder();
        $support = $this->staff('support');

        $this->actingAs($support)->get('/admin/deposits')->assertOk();
        $this->actingAs($support)->get('/admin/transactions')->assertOk()->assertSee($this->customer->email)->assertSee('Purchase');
    }

    public function test_the_ledger_filters_by_type_and_direction(): void
    {
        $this->placeOrder(); // one purchase (debit); the funding was a bonus (credit)
        $admin = $this->staff('admin');

        $types = fn ($response) => array_values(array_unique(array_map(
            fn ($tx) => $tx->type->value,
            $response->viewData('transactions')->items()
        )));

        $this->assertSame(['purchase'], $types($this->actingAs($admin)->get('/admin/transactions?type=purchase')->assertOk()));
        $this->assertSame(['bonus'], $types($this->actingAs($admin)->get('/admin/transactions?direction=credit')->assertOk()));
        $this->assertSame(['purchase'], $types($this->actingAs($admin)->get('/admin/transactions?direction=debit')->assertOk()));

        // A bad direction is ignored, and the e-mail search still works.
        $all = $this->actingAs($admin)->get('/admin/transactions?q='.urlencode($this->customer->email).'&direction=bogus')->assertOk();
        $this->assertCount(2, $all->viewData('transactions')->items());
    }

    public function test_the_admin_menu_links_to_the_new_pages(): void
    {
        $this->actingAs($this->staff('admin'))->get('/admin/orders')
            ->assertOk()
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.orders.index'), false)
            ->assertSee(route('admin.deposits.index'), false)
            ->assertSee(route('admin.transactions.index'), false);
    }
}