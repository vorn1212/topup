<?php

namespace Tests\Feature;

use App\Jobs\PlaceCheckoutOrderJob;
use App\Jobs\RunPayWayLinkSessionJob;
use App\Models\Order;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Models\WalletDeposit;
use App\Services\Payment\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
        config(['payway_link.url' => 'https://payway.test/pay']);
    }

    private function checkout(array $extra = [])
    {
        return $this->post('/checkout', array_merge([
            'game' => 'pubg-mobile',
            'product_id' => $this->product->id,
            'fields' => ['player_id' => '123456789'],
        ], $extra));
    }

    private function deposit(): WalletDeposit
    {
        return WalletDeposit::query()->latest('id')->firstOrFail();
    }

    private function url(WalletDeposit $deposit, string $page = ''): string
    {
        return '/pay/'.$deposit->deposit_number.'/'.$deposit->metadata['checkout']['token'].$page;
    }

    public function test_a_visitor_without_an_account_starts_a_qr_payment_for_the_exact_price(): void
    {
        $response = $this->checkout();
        $deposit = $this->deposit();

        $response->assertRedirect($this->url($deposit));
        $this->assertSame('1.0000', (string) $deposit->amount);
        $this->assertTrue(User::findOrFail($deposit->user_id)->isGuest());
        $this->assertSame(0, Order::count(), 'no order before the payment is confirmed');

        Queue::assertPushed(RunPayWayLinkSessionJob::class);
        Queue::assertPushed(PlaceCheckoutOrderJob::class);

        $this->get($this->url($deposit))->assertOk()->assertSee('123456789');
    }

    public function test_a_wrong_token_finds_nothing(): void
    {
        $this->checkout();
        $deposit = $this->deposit();

        $this->get('/pay/'.$deposit->deposit_number.'/'.str_repeat('a', 40))->assertNotFound();
        $this->getJson('/pay/'.$deposit->deposit_number.'/'.str_repeat('a', 40).'/status')->assertNotFound();
    }

    public function test_the_order_starts_once_the_payment_is_confirmed(): void
    {
        $this->checkout();
        $deposit = $this->deposit();

        $first = $this->getJson($this->url($deposit, '/status'))->assertOk()->json('data');
        $this->assertNull($first['order']);

        app(DepositService::class)->markPaid($deposit->id, 'ABA-TEST-1');

        $paid = $this->getJson($this->url($deposit, '/status'))->assertOk()->json('data');
        $this->assertSame('paid', $paid['status']);
        $this->assertNotNull($paid['order']);

        $this->getJson($this->url($deposit, '/status'))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, ProviderOrder::count());
        $this->assertSame($deposit->user_id, Order::firstOrFail()->user_id);
    }

    public function test_a_price_rise_after_payment_is_reported_and_nothing_is_ordered(): void
    {
        $this->checkout();
        $deposit = $this->deposit();

        \App\Models\Product::query()->whereKey($this->product->id)->update(['selling_price' => '2.0000']);
        app(DepositService::class)->markPaid($deposit->id, 'ABA-TEST-2');

        $json = $this->getJson($this->url($deposit, '/status'))->assertOk()->json('data');

        $this->assertTrue($json['problem']);
        $this->assertSame(0, Order::count());
        $this->assertNotEmpty($this->deposit()->metadata['checkout']['error']);
    }

    public function test_a_changed_price_is_refused_before_any_payment_starts(): void
    {
        $this->checkout(['expected_price' => '9.99'])->assertSessionHas('error');

        $this->assertSame(0, WalletDeposit::count());
    }

    public function test_a_signed_in_customer_pays_into_their_own_account(): void
    {
        $this->actingAs($this->customer);
        $this->checkout();

        $this->assertSame($this->customer->id, $this->deposit()->user_id);
    }

    public function test_one_address_cannot_open_many_payments(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->checkout()->assertSessionMissing('error');
        }

        $this->checkout()->assertSessionHas('error');
        $this->assertSame(3, WalletDeposit::count());
    }

    public function test_guest_accounts_never_get_e_mail(): void
    {
        $guest = new User(['email' => 'abc@'.User::GUEST_DOMAIN]);

        $this->assertNull($guest->routeNotificationFor('mail'));
        $this->assertSame($this->customer->email, $this->customer->routeNotificationFor('mail'));
    }

    public function test_the_top_up_page_offers_qr_payment_to_visitors(): void
    {
        $this->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertSee('Pay with QR')
            ->assertSee(route('checkout.store'), false)
            ->assertDontSee('to place an order.');
    }
}
