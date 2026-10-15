<?php

namespace Tests\Concerns;

use App\Enums\MarkupType;
use App\Enums\OrderStatus;
use App\Enums\ProviderOrderStatus;
use App\Enums\WalletTxType;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\TopUp\OrderService;
use App\Services\TopUp\OrderStateMachine;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Helpers for tests that need a customer with money, a game, a package and orders in a given state.
 * Use together with MakesCatalog.
 */
trait MakesOrders
{
    protected User $customer;

    protected Product $product;

    protected function setUpShop(string $funds = '20.00'): void
    {
        config([
            'fazercards.api_key' => 'test-key-123',
            'fazercards.fake' => false,
            'fazercards.base_url' => 'https://api.fzr.cards/api/v2',
            'fazercards.retry_sleep_ms' => 0,
        ]);
        Queue::fake();

        $this->customer = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->createWallet($this->customer), $funds, 'test:fund:'.Str::random(10), WalletTxType::Bonus);

        $game = $this->makeGame(['slug' => 'pubg-mobile', 'provider_category_id' => 'cat_pubgm_1']);
        $this->makeField($game);
        $this->product = $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20', ['provider_offer_id' => 'offer_60uc']);
    }

    protected function placeOrder(): ProviderOrder
    {
        $order = app(OrderService::class)->create($this->customer, 'pubg-mobile', $this->product->id, ['player_id' => '123456789']);

        return ProviderOrder::where('order_id', $order->id)->firstOrFail();
    }

    /** An order the provider is working on: processing, with a provider order id. */
    protected function processingOrder(string $providerId = 'ord-9002'): ProviderOrder
    {
        $po = $this->placeOrder();
        $this->moveOrder($po, OrderStatus::Processing);
        $po->forceFill([
            'status' => ProviderOrderStatus::Processing,
            'provider_order_id' => $providerId,
            'attempts' => 1,
            'next_check_at' => now()->addMinute(),
        ])->save();

        return $po->fresh();
    }

    /** An order that stopped automatic handling. */
    protected function reviewOrder(bool $withProviderId = true): ProviderOrder
    {
        $po = $this->placeOrder();
        $this->moveOrder($po, OrderStatus::Processing);
        $po->forceFill([
            'status' => ProviderOrderStatus::Review,
            'provider_order_id' => $withProviderId ? 'ord-9002' : null,
            'attempts' => 8,
            'last_error' => 'The provider could not be reached.',
            'next_check_at' => null,
        ])->save();

        return $po->fresh();
    }

    protected function moveOrder(ProviderOrder $po, OrderStatus $to): void
    {
        OrderStateMachine::transition(Order::findOrFail($po->order_id), $to);
    }

    protected function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'status' => 'active'])->save();

        return $user;
    }

    protected function customerBalance(): string
    {
        return app(WalletService::class)->getBalance($this->customer);
    }
}