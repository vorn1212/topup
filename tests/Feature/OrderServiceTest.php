<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Enums\WalletTxType;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OrderRejectedException;
use App\Exceptions\PriceChangedException;
use App\Jobs\ProcessFazerCardsOrderJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\Pricing\PricingService;
use App\Services\TopUp\OrderService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private User $user;

    private Game $game;

    private Product $product;

    private OrderService $orders;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fazercards.api_key' => 'test-key-123', 'fazercards.fake' => false]);
        Queue::fake();

        $this->orders = app(OrderService::class);
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

    private function place(array $fields = ['player_id' => '123456789'], ?string $token = null, ?string $expected = null, ?User $user = null): Order
    {
        return $this->orders->create($user ?? $this->user, 'pubg-mobile', $this->product->id, $fields, $token, $expected, '203.0.113.5');
    }

    private function balance(?User $user = null): string
    {
        return $this->wallets->getBalance($user ?? $this->user);
    }

        private function restoreCatalog(): void
    {
        $this->game->forceFill(['status' => 'active', 'provider_category_id' => 'cat_pubgm_1'])->save();
        $this->product->forceFill(['status' => 'active', 'is_available' => true, 'provider_offer_id' => 'offer_60uc'])->save();
        app(PricingService::class)->update($this->product->fresh(), '0.80', MarkupType::FixedMarkup, '0.20', null, 'Restore');
    }

    public function test_an_order_debits_the_wallet_and_snapshots_everything(): void
    {
        $order = $this->place(token: null);

        $this->assertSame('pending', $order->status->value);
        $this->assertSame('1.0000', $order->selling_price);
        $this->assertSame('0.8000', $order->provider_cost);
        $this->assertSame('0.2000', $order->profit);
        $this->assertSame('PUBG Mobile', $order->game_name);
        $this->assertSame('60 UC', $order->product_name);
        $this->assertSame(['player_id' => '123456789'], $order->player_fields);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-Z0-9]{6}$/', $order->order_number);
        $this->assertSame('19.0000', $this->balance());

        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $order->wallet_transaction_id,
            'type' => 'purchase',
            'direction' => 'debit',
            'amount' => '1.0000',
            'idempotency_key' => 'purchase:order:'.$order->id,
            'reference_type' => 'order',
            'reference_id' => $order->id,
        ]);

        $po = ProviderOrder::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('queued', $po->status->value);
        $this->assertSame('cat_pubgm_1', $po->category_id);
        $this->assertSame('offer_60uc', $po->offer_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/', $po->idempotency_key);

        Queue::assertPushed(ProcessFazerCardsOrderJob::class, fn ($job) => $job->providerOrderId === $po->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.created', 'subject_id' => $order->id]);
    }

    public function test_a_later_price_change_never_alters_an_existing_order(): void
    {
        $order = $this->place();

        app(PricingService::class)->update($this->product->fresh(), null, MarkupType::FixedPrice, '5.00', null, 'Price change');

        $this->assertSame('1.0000', $order->fresh()->selling_price);
    }

    public function test_a_price_the_customer_did_not_see_cancels_the_order(): void
    {
        foreach (['0.50', '1.50', 'abc'] as $shown) {
            try {
                $this->place(expected: $shown);
                $this->fail("Displayed price [{$shown}] must be refused.");
            } catch (PriceChangedException $e) {
                $this->assertSame('1.0000', $e->newPrice);
            }
        }

        $this->assertSame(0, Order::count());
        $this->assertSame('20.0000', $this->balance());

        $this->assertSame('1.0000', $this->place(expected: '1.00')->selling_price);
    }

    public function test_insufficient_balance_creates_nothing(): void
    {
        $poor = User::factory()->create();
        $this->fund($poor, '0.50');

        try {
            $this->place(user: $poor);
            $this->fail('Not enough money must be refused.');
        } catch (InsufficientBalanceException) {
            $this->assertSame(0, Order::count());
            $this->assertSame(0, ProviderOrder::count());
            $this->assertSame('0.5000', $this->balance($poor));
            Queue::assertNothingPushed();
        }
    }

    public function test_two_orders_cannot_spend_the_same_balance(): void
    {
        $poor = User::factory()->create();
        $this->fund($poor, '1.50');

        $this->place(user: $poor);

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->place(user: $poor);
        } finally {
            $this->assertSame('0.5000', $this->balance($poor));
            $this->assertSame(1, Order::where('user_id', $poor->id)->count());
        }
    }

    public function test_unavailable_things_are_refused_without_charging(): void
    {
        $cases = [
            'inactive game' => fn () => $this->game->forceFill(['status' => 'inactive'])->save(),
            'game not linked to the provider' => fn () => $this->game->forceFill(['provider_category_id' => null])->save(),
            'inactive package' => fn () => $this->product->forceFill(['status' => 'inactive'])->save(),
            'unavailable package' => fn () => $this->product->forceFill(['is_available' => false])->save(),
            'package without a provider offer' => fn () => $this->product->forceFill(['provider_offer_id' => null])->save(),
            'price below cost' => function () {
                $pricing = app(PricingService::class);
                $pricing->update($this->product->fresh(), null, MarkupType::FixedPrice, '1.00', null, 'Fixed price');
                $pricing->syncCost($this->product->fresh(), '2.00');
            },
        ];

        foreach ($cases as $name => $break) {
            $break();

            try {
                $this->place();
                $this->fail("{$name}: must be refused.");
            } catch (OrderRejectedException) {
                $this->assertSame('20.0000', $this->balance(), $name);
                $this->assertSame(0, Order::count(), $name);
            }

            $this->restoreCatalog();
        }
    }

    public function test_suspended_users_and_a_missing_provider_key_are_refused(): void
    {
        try {
            $this->place(user: User::factory()->suspended()->create());
            $this->fail('A suspended user must be refused.');
        } catch (OrderRejectedException) {
            $this->assertTrue(true);
        }

        config(['fazercards.api_key' => '']);

        $this->expectException(OrderRejectedException::class);
        $this->place();
    }

    public function test_invalid_player_details_are_refused_without_charging(): void
    {
        foreach ([['player_id' => 'abc'], ['player_id' => '12'], []] as $fields) {
            try {
                $this->place($fields);
                $this->fail('Invalid details must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('fields.player_id', $e->errors());
            }
        }

        $this->assertSame('20.0000', $this->balance());
        $this->assertSame(0, Order::count());
    }

    public function test_a_repeated_request_returns_the_same_order_and_charges_once(): void
    {
        $token = '8f3a2c9e-1b4d-4f7a-9e2c-5b6a8c1d2e3f';

        $first = $this->place(token: $token);
        $second = $this->place(token: $token);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
        $this->assertSame('19.0000', $this->balance());
        Queue::assertPushed(ProcessFazerCardsOrderJob::class, 1);
    }

    public function test_the_same_token_from_another_customer_is_a_separate_order(): void
    {
        $other = User::factory()->create();
        $this->fund($other, '5.00');
        $token = '8f3a2c9e-1b4d-4f7a-9e2c-5b6a8c1d2e3f';

        $mine = $this->place(token: $token);
        $theirs = $this->place(token: $token, user: $other);

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame('4.0000', $this->balance($other));
    }

    public function test_every_order_gets_its_own_idempotency_key(): void
    {
        $a = $this->place();
        $b = $this->place();

        $keys = ProviderOrder::pluck('idempotency_key')->all();

        $this->assertCount(2, array_unique($keys));
        $this->assertNotSame($a->id, $b->id);
    }
}