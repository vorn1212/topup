<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Enums\WalletTxType;
use App\Jobs\ProcessFazerCardsOrderJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\TopUp\OrderService;
use App\Services\TopUp\RefundService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class OrderPagesTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private User $user;

    private Game $game;

    private Product $product;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fazercards.api_key' => 'test-key-123', 'fazercards.fake' => false]);
        Queue::fake();

        $this->wallets = app(WalletService::class);

        $this->user = User::factory()->create();
        $this->fund($this->user, '20.00');

        $this->game = $this->makeGame(['name' => 'PUBG Mobile', 'slug' => 'pubg-mobile', 'provider_category_id' => 'cat_pubgm_1']);
        $this->makeField($this->game);
        $this->product = $this->makeProduct($this->game, '0.80', MarkupType::FixedMarkup, '0.20', [
            'provider_offer_id' => 'offer_60uc', 'name' => '60 UC',
        ]); // selling 1.00
    }

    private function fund(User $user, string $amount): void
    {
        $this->wallets->credit($this->wallets->createWallet($user), $amount, 'test:fund:'.$user->id, WalletTxType::Bonus);
    }

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'game' => 'pubg-mobile',
            'product_id' => $this->product->id,
            'fields' => ['player_id' => '123456789'],
            'request_token' => '8f3a2c9e-1b4d-4f7a-9e2c-5b6a8c1d2e3f',
            'expected_price' => '1.00',
        ], $override);
    }

    private function balance(?User $user = null): string
    {
        return $this->wallets->getBalance($user ?? $this->user);
    }

    private function placeOrder(?User $user = null, array $override = []): Order
    {
        $this->actingAs($user ?? $this->user)->post('/orders', $this->payload($override))->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- placing an order

    public function test_guests_are_sent_to_login(): void
    {
        $this->post('/orders', $this->payload())->assertRedirect('/login');
        $this->get('/orders')->assertRedirect('/login');
        $this->assertSame(0, Order::count());
    }

    public function test_placing_an_order_charges_once_and_shows_the_order(): void
    {
        $response = $this->actingAs($this->user)->post('/orders', $this->payload());

        $order = Order::firstOrFail();
        $response->assertRedirect(route('orders.show', $order->order_number))->assertSessionHas('status');

        $this->assertSame('1.0000', $order->selling_price);
        $this->assertSame('19.0000', $this->balance());
        Queue::assertPushed(ProcessFazerCardsOrderJob::class, 1);
    }

    public function test_prices_sent_by_the_browser_are_ignored(): void
    {
        $this->actingAs($this->user)->post('/orders', $this->payload([
            'selling_price' => '0.01', 'price' => '0.01', 'amount' => '0.01', 'provider_cost' => '0', 'profit' => '99',
        ]))->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame('1.0000', $order->selling_price);
        $this->assertSame('0.8000', $order->provider_cost);
        $this->assertSame('19.0000', $this->balance());
    }

    public function test_a_double_submit_creates_one_order_and_charges_once(): void
    {
        $this->actingAs($this->user)->post('/orders', $this->payload())->assertRedirect();
        $this->actingAs($this->user)->post('/orders', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status', 'This order was already placed.');

        $this->assertSame(1, Order::count());
        $this->assertSame('19.0000', $this->balance());
        Queue::assertPushed(ProcessFazerCardsOrderJob::class, 1);
    }

    public function test_a_changed_price_asks_for_confirmation_and_charges_nothing(): void
    {
        $this->actingAs($this->user)->post('/orders', $this->payload(['expected_price' => '0.50']))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '$1.00'));

        $this->assertSame(0, Order::count());
        $this->assertSame('20.0000', $this->balance());
    }

    public function test_not_enough_money_points_to_the_deposit_page(): void
    {
        $poor = User::factory()->create();
        $this->fund($poor, '0.50');

        $this->actingAs($poor)->post('/orders', $this->payload())
            ->assertSessionHas('insufficient', true)
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        $this->assertSame('0.5000', $this->balance($poor));
    }

    public function test_invalid_player_details_show_errors_and_charge_nothing(): void
    {
        $this->actingAs($this->user)->post('/orders', $this->payload(['fields' => ['player_id' => 'abc']]))
            ->assertSessionHasErrors('fields.player_id');

        $this->assertSame(0, Order::count());
        $this->assertSame('20.0000', $this->balance());
    }

    public function test_a_missing_package_is_reported(): void
    {
        $payload = $this->payload();
        unset($payload['product_id']);

        $this->actingAs($this->user)->post('/orders', $payload)->assertSessionHasErrors('product_id');
        $this->assertSame(0, Order::count());
    }

    public function test_an_unavailable_package_is_refused_with_a_friendly_message(): void
    {
        $this->product->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->user)->post('/orders', $this->payload())
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'not available'));

        $this->assertSame('20.0000', $this->balance());
    }

    public function test_suspended_users_cannot_place_orders(): void
    {
        $suspended = User::factory()->suspended()->create();
        $this->fund($suspended, '5.00');

        $this->actingAs($suspended)->post('/orders', $this->payload())->assertRedirect(route('dashboard'));

        $this->assertSame(0, Order::count());
        $this->assertSame('5.0000', $this->balance($suspended));
    }

    public function test_placing_orders_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->user)->post('/orders', [])->assertRedirect();
        }

        $this->actingAs($this->user)->post('/orders', [])->assertStatus(429);
    }

    // ---------------------------------------------------------------- viewing orders

    public function test_the_order_page_shows_the_details_and_hides_provider_data(): void
    {
        $order = $this->placeOrder();

        $po = ProviderOrder::where('order_id', $order->id)->firstOrFail();
        $po->forceFill(['provider_order_id' => 'ord-445566', 'response_payload' => ['secret' => 'internal-note']])->save();

        $this->actingAs($this->user)->get('/order/'.$order->order_number)
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('PUBG Mobile')
            ->assertSee('60 UC')
            ->assertSee('$1.00')
            ->assertSee('Player Id')
            ->assertSee('123456789')
            ->assertSee('We are delivering your top-up')
            ->assertDontSee($po->idempotency_key)
            ->assertDontSee('ord-445566')
            ->assertDontSee('internal-note');
    }

    public function test_a_refunded_order_explains_the_refund(): void
    {
        $order = app(OrderService::class)->create($this->user, 'pubg-mobile', $this->product->id, ['player_id' => '123456789']);
        app(RefundService::class)->failAndRefund($order, 'Provider failed');

        $this->actingAs($this->user)->get('/order/'.$order->order_number)
            ->assertOk()
            ->assertSee('was returned to your wallet')
            ->assertSee('Refund');
    }

    public function test_nobody_else_can_see_an_order_or_its_status(): void
    {
        $order = $this->placeOrder();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/order/'.$order->order_number)->assertNotFound();
        $this->actingAs($stranger)->get('/order/'.$order->order_number.'/status')->assertNotFound();

        $this->actingAs($this->user)->get('/order/'.$order->order_number)->assertOk();
    }

    public function test_malformed_order_numbers_are_not_found(): void
    {
        $this->actingAs($this->user)->get('/order/not-an-order')->assertNotFound();
        $this->actingAs($this->user)->get('/order/ORD-1-X')->assertNotFound();
    }

    public function test_the_status_endpoint(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->user)->getJson('/order/'.$order->order_number.'/status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.final', false);
    }

    public function test_the_order_list_shows_only_my_orders(): void
    {
        $mine = $this->placeOrder();

        $other = User::factory()->create();
        $this->fund($other, '5.00');
        $theirs = $this->placeOrder($other, ['request_token' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->actingAs($this->user)->get('/orders')
            ->assertOk()
            ->assertSee($mine->order_number)
            ->assertDontSee($theirs->order_number);
    }

    public function test_suspended_users_can_still_see_their_history(): void
    {
        $order = $this->placeOrder();
        $this->user->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->user->fresh())->get('/orders')->assertOk()->assertSee($order->order_number);
        $this->actingAs($this->user->fresh())->get('/order/'.$order->order_number)->assertOk();
    }

    public function test_the_dashboard_lists_recent_orders(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->user)->get('/dashboard')->assertOk()->assertSee($order->order_number);
    }

    // ---------------------------------------------------------------- the top-up form

    public function test_the_top_up_page_posts_to_the_order_route_with_a_token_and_the_expected_price(): void
    {
        $this->actingAs($this->user)->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertSee('action="'.route('checkout.store').'"', false)
            ->assertSee('name="request_token"', false)
            ->assertSee('name="expected_price"', false)
            ->assertSee('Pay with QR');
    }

    public function test_every_page_view_gets_a_fresh_token(): void
    {
        $first = $this->actingAs($this->user)->get('/games/pubg-mobile/top-up')->getContent();
        $second = $this->actingAs($this->user)->get('/games/pubg-mobile/top-up')->getContent();

        preg_match('/name="request_token" value="([^"]+)"/', $first, $a);
        preg_match('/name="request_token" value="([^"]+)"/', $second, $b);

        $this->assertNotEmpty($a[1] ?? null);
        $this->assertNotSame($a[1], $b[1]);
    }
}