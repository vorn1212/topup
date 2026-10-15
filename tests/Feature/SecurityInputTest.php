<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletDeposit;
use App\Services\Wallet\WalletService;
use App\Enums\WalletTxType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

/**
 * What a hostile browser can send: tampered prices, bad IDs, repeated submits, script tags.
 */
class SecurityInputTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    /** @param array<string,mixed> $extra */
    private function order(User $user, array $extra = [])
    {
        return $this->actingAs($user)->post('/orders', array_merge([
            'game' => 'pubg-mobile',
            'product_id' => $this->product->id,
            'fields' => ['player_id' => '123456789'],
        ], $extra));
    }

    private function fundedCustomer(string $funds = '20.00'): User
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->createWallet($user), $funds, 'test:fund:'.Str::random(10), WalletTxType::Bonus);

        return $user;
    }

    private function balanceOf(User $user): string
    {
        return app(WalletService::class)->getBalance($user);
    }

    private function charged(User $user, string $before = '20.00'): string
    {
        return bcsub($before, $this->balanceOf($user), 4);
    }

    // ---------------------------------------------------------------- price tampering

    public function test_price_fields_sent_by_the_browser_are_ignored(): void
    {
        $honest = $this->fundedCustomer();
        $tamperer = $this->fundedCustomer();

        $this->order($honest)->assertSessionHasNoErrors();
        $this->order($tamperer, [
            'selling_price' => '0.01',
            'price' => '0.01',
            'amount' => '0.01',
            'total' => '0.01',
            'provider_cost' => '0.01',
            'profit' => '99',
            'user_id' => $honest->id,
            'status' => 'completed',
            'wallet_transaction_id' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Order::where('user_id', $tamperer->id)->count());
        $this->assertSame(1, Order::where('user_id', $honest->id)->count());
        $this->assertSame(1, bccomp($this->charged($tamperer), '0.5', 4), 'the price must come from the database, not the browser');
        $this->assertSame(0, bccomp($this->charged($tamperer), $this->charged($honest), 4));

        $order = Order::where('user_id', $tamperer->id)->firstOrFail();
        $this->assertSame($tamperer->id, $order->user_id);
        $this->assertNotSame('completed', $order->status->value);
    }

    public function test_a_wrong_expected_price_is_refused_and_nothing_is_charged(): void
    {
        $this->order($this->customer, ['expected_price' => '0.01'])->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, bccomp($this->customerBalance(), '20', 4));
    }

    public function test_the_right_expected_price_is_accepted(): void
    {
        $this->order($this->customer, ['expected_price' => '1.00'])->assertSessionHasNoErrors();

        $this->assertSame(1, Order::count());
    }

    public function test_a_package_of_another_game_or_an_unavailable_package_is_refused(): void
    {
        $otherGame = $this->makeGame(['slug' => 'other-game']);
        $otherProduct = $this->makeProduct($otherGame);

        $this->order($this->customer, ['product_id' => $otherProduct->id]);
        $this->assertSame(0, Order::count());

        $this->product->forceFill(['is_available' => false])->save();
        $this->order($this->customer);
        $this->assertSame(0, Order::count());

        $this->assertSame(0, bccomp($this->customerBalance(), '20', 4));
    }

    public function test_the_same_request_token_creates_one_order_and_one_charge(): void
    {
        $token = Str::random(32);

        $this->order($this->customer, ['request_token' => $token]);
        $this->order($this->customer, ['request_token' => $token]);
        $this->order($this->customer, ['request_token' => $token]);

        $this->assertSame(1, Order::count());
        $this->assertSame(0, bccomp($this->charged($this->customer), '1', 4));
    }

    public function test_a_customer_without_enough_money_cannot_order(): void
    {
        $poor = $this->fundedCustomer('0.50');

        $this->order($poor)->assertSessionHas('error');

        $this->assertSame(0, Order::where('user_id', $poor->id)->count());
        $this->assertSame(0, bccomp($this->balanceOf($poor), '0.5', 4));
    }

    // ---------------------------------------------------------------- bad input

    public function test_broken_order_input_gives_validation_errors_not_server_errors(): void
    {
        $this->actingAs($this->customer);

        $bad = [
            ['game' => 'Not A Slug!'],
            ['game' => ['array']],
            ['product_id' => 'abc'],
            ['product_id' => '-5'],
            ['product_id' => ['1']],
            ['fields' => 'text'],
            ['fields' => ['player_id' => ['nested']]],
            ['fields' => ['player_id' => str_repeat('9', 500)]],
            ['request_token' => str_repeat('a', 500)],
        ];

        foreach ($bad as $change) {
            $response = $this->post('/orders', array_merge([
                'game' => 'pubg-mobile',
                'product_id' => $this->product->id,
                'fields' => ['player_id' => '123456789'],
            ], $change));

            $this->assertLessThan(500, $response->getStatusCode(), json_encode($change));
        }

        $this->assertSame(0, Order::count());
    }

    public function test_bad_player_ids_are_refused(): void
    {
        foreach (['abc', '12', "123456789'; DROP TABLE orders;--", '<script>alert(1)</script>', '１２３４５６７８９', ''] as $playerId) {
            $this->order($this->customer, ['fields' => ['player_id' => $playerId]])->assertSessionHasErrors();
        }

        $this->assertSame(0, Order::count());
    }

    public function test_bad_deposit_amounts_create_nothing(): void
    {
        // Many wrong tries in a row would hit the rate limit, which has its own test.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->customer);

        foreach (['0', '-5', 'abc', '1e3', '10.999', '99999999', '0.50', '1,000', '', ' '] as $amount) {
            $this->post('/wallet/deposit', ['amount' => $amount])->assertSessionHasErrors('amount');
        }

        $this->post('/wallet/deposit', ['amount' => ['10']])->assertSessionHasErrors('amount');

        $this->assertSame(0, WalletDeposit::count());
    }

    public function test_a_valid_deposit_request_does_not_change_the_balance(): void
    {
        $this->actingAs($this->customer)->post('/wallet/deposit', ['amount' => '10.50'])->assertSessionHasNoErrors();

        $this->assertSame(1, WalletDeposit::count());
        $this->assertSame('pending', WalletDeposit::firstOrFail()->getRawOriginal('status'));
        $this->assertSame(0, bccomp($this->customerBalance(), '20', 4), 'a deposit only adds money after the payment is confirmed');
    }

    // ---------------------------------------------------------------- script injection

    public function test_a_customer_name_with_script_tags_is_escaped_for_staff(): void
    {
        $payload = '<script>alert("xss9271")</script>';
        $this->customer->forceFill(['name' => $payload])->save();
        $admin = $this->staff('admin');

        foreach (['/admin/users', '/admin/users/'.$this->customer->id] as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertDontSee($payload, false)
                ->assertSee('&lt;script&gt;', false);
        }
    }

    public function test_a_game_name_with_markup_is_escaped_on_public_pages(): void
    {
        $payload = '<img src=x onerror=alert(9271)>';
        $this->makeGame(['name' => 'Evil '.$payload, 'slug' => 'evil-game']);

        foreach (['/', '/games', '/games/evil-game'] as $url) {
            $this->get($url)->assertOk()->assertDontSee($payload, false);
        }
    }

    public function test_a_script_in_the_search_or_query_string_is_not_echoed_back_raw(): void
    {
        $payload = '"><script>alert(9271)</script>';

        foreach (['/games?q=', '/admin/users?q=', '/admin/orders?q=', '/admin/audit?q='] as $url) {
            $this->actingAs($this->staff('admin'))->get($url.urlencode($payload))
                ->assertDontSee('<script>alert(9271)</script>', false);
        }
    }

    // ---------------------------------------------------------------- nothing internal leaks to customers

    public function test_customer_pages_never_show_provider_details(): void
    {
        $po = $this->reviewOrder();
        $order = Order::findOrFail($po->order_id);
        $secrets = ['ord-9002', 'offer_60uc', 'cat_pubgm_1', 'The provider could not be reached', 'test-key-123'];

        $page = $this->actingAs($this->customer)->get('/order/'.$order->order_number)->assertOk();
        $json = $this->actingAs($this->customer)->getJson('/order/'.$order->order_number.'/status')->assertOk();
        $list = $this->actingAs($this->customer)->get('/orders')->assertOk();

        foreach ([$page, $json, $list] as $response) {
            foreach ($secrets as $secret) {
                $response->assertDontSee($secret, false);
            }
        }

        foreach (['provider_cost', 'provider_order_id', 'idempotency', 'last_error', 'profit'] as $key) {
            $json->assertDontSee($key, false);
        }
    }

    public function test_the_payment_link_is_never_shown_to_the_customer(): void
    {
        config(['payway_link.url' => 'https://link.payway.example/pay-secret-9271']);

        $location = $this->actingAs($this->customer)->post('/wallet/deposit', ['amount' => '10'])->assertRedirect()->headers->get('Location');
        $deposit = WalletDeposit::firstOrFail();

        $this->actingAs($this->customer)->get($location)->assertOk()->assertDontSee('pay-secret-9271', false);
        $this->actingAs($this->customer)->getJson('/wallet/deposit/'.$deposit->deposit_number.'/status')->assertOk()->assertDontSee('pay-secret-9271', false);
        $this->actingAs($this->customer)->get('/wallet/deposit')->assertOk()->assertDontSee('pay-secret-9271', false);
    }
}