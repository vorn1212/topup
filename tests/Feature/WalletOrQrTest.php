<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Models\Order;
use App\Models\WalletDeposit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class WalletOrQrTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    public function test_visitors_see_only_the_qr_button(): void
    {
        $this->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertSee('Pay with QR')
            ->assertSee('action="'.route('checkout.store').'"', false)
            ->assertDontSee('Pay with wallet');
    }

    public function test_signed_in_customers_see_only_the_wallet_button(): void
    {
        $this->actingAs($this->customer)->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertSee('Pay with wallet')
            ->assertSee('action="'.route('orders.store').'"', false)
            ->assertDontSee('Pay with QR')
            ->assertSee('$20.00');
    }

    public function test_paying_from_the_wallet_places_the_order_without_a_qr(): void
    {
        $this->actingAs($this->customer)->post('/orders', [
            'game' => 'pubg-mobile',
            'product_id' => $this->product->id,
            'fields' => ['player_id' => '123456789'],
        ])->assertRedirect();

        $this->assertSame(1, Order::count());
        $this->assertSame(0, WalletDeposit::count(), 'no QR payment is needed');
    }

    public function test_a_wallet_that_is_too_small_gets_the_insufficient_funds_message(): void
    {
        $dear = $this->makeProduct($this->product->game, '30.00', MarkupType::FixedMarkup, '0.20', ['provider_offer_id' => 'offer_big']);

        $this->actingAs($this->customer)->post('/orders', [
            'game' => 'pubg-mobile',
            'product_id' => $dear->id,
            'fields' => ['player_id' => '123456789'],
        ])->assertSessionHas('error', 'Insufficient funds. Please add funds.');

        $this->assertSame(0, Order::count());
    }
}
