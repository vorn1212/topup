<?php

namespace Tests\Feature;

use App\Exceptions\ProviderException;
use App\Services\TopUp\RechargeGamesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RechargeGamesServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://api.rechargegame.games';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rechargegames.api_key' => 'gv_live_test_key',
            'rechargegames.base_url' => self::HOST,
            'rechargegames.retry_sleep_ms' => 0,
            'rechargegames.fake' => false,
            'rechargegames.test_orders' => false,
        ]);
    }

    private function catalog(): array
    {
        return [
            'currency' => 'USDT',
            'categories' => [[
                'slug' => 'free-fire',
                'name' => 'Free Fire',
                'kind' => 'topup',
                'groups' => [[
                    'slug' => 'brazil',
                    'products' => [
                        ['name' => '100 Diamonds', 'price' => '0.89', 'delivery' => 'player_id', 'product_key' => 'topup/free-fire/brazil/100 Diamonds'],
                        ['name' => '310 Diamonds', 'price' => 2.5, 'delivery' => 'player_id', 'product_key' => 'topup/free-fire/brazil/310 Diamonds', 'out_of_stock' => true],
                        ['name' => 'Broken', 'price' => 'free', 'delivery' => 'player_id', 'product_key' => 'topup/free-fire/brazil/Broken'],
                    ],
                ]],
            ]],
        ];
    }

    public function test_balance_is_read_with_the_api_key_header(): void
    {
        Http::fake([self::HOST.'/v1/balance' => Http::response(['balance_usdt' => '121.41', 'balance_micro' => 121410000, 'ledger' => []])]);

        $balance = app(RechargeGamesService::class)->getBalance();

        $this->assertSame('USD', $balance['currency']);
        $this->assertSame(0, \App\Support\Money::cmp($balance['balance'], '121.41'));

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'gv_live_test_key'));
    }

    public function test_categories_are_category_and_region_pairs(): void
    {
        Http::fake([self::HOST.'/v1/products*' => Http::response($this->catalog())]);

        $categories = app(RechargeGamesService::class)->getCategories();

        $this->assertSame('free-fire/brazil', $categories[0]['category_id']);
        $this->assertSame('Free Fire (Brazil)', $categories[0]['name']);
    }

    public function test_offers_use_the_product_key_skip_bad_rows_and_hide_out_of_stock(): void
    {
        Http::fake([self::HOST.'/v1/products*' => Http::response($this->catalog())]);

        $data = app(RechargeGamesService::class)->getOffers('free-fire/brazil');

        $this->assertCount(1, $data['offers']);
        $this->assertSame('topup/free-fire/brazil/100 Diamonds', $data['offers'][0]['offer_id']);
        $this->assertSame(1, $data['skipped']);
        $this->assertSame('player_id', $data['fields'][0]['key']);
    }

    public function test_an_order_is_sent_with_buyer_ref_and_product_key(): void
    {
        Http::fake([self::HOST.'/v1/orders' => Http::response(['order_id' => 'ORD-7K3M9Q', 'status' => 'pending'], 201)]);

        $result = app(RechargeGamesService::class)->createOrder(
            'free-fire/brazil',
            'topup/free-fire/brazil/100 Diamonds',
            ['player_id' => '12345678'],
            'uuid-key-1'
        );

        $this->assertSame('ORD-7K3M9Q', $result['id']);
        $this->assertSame('pending', $result['status']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === self::HOST.'/v1/orders'
                && $data['product_key'] === 'topup/free-fire/brazil/100 Diamonds'
                && $data['product'] === '100 Diamonds'
                && $data['buyer_ref'] === 'uuid-key-1'
                && $data['region'] === 'brazil'
                && $data['player_id'] === '12345678'
                && ! isset($data['test']);
        });
    }

    public function test_test_mode_marks_orders_as_free_test_orders(): void
    {
        config(['rechargegames.test_orders' => true]);
        Http::fake([self::HOST.'/v1/orders' => Http::response(['order_id' => 'ORD-AAAA11', 'status' => 'pending'], 201)]);

        app(RechargeGamesService::class)->createOrder('free-fire/brazil', 'topup/free-fire/brazil/100 Diamonds', ['player_id' => '1'], 'k');

        Http::assertSent(fn ($request) => ($request->data()['test'] ?? false) === true);
    }

    public function test_out_of_stock_and_low_provider_balance_are_clear_refusals(): void
    {
        Http::fake([self::HOST.'/v1/orders' => Http::sequence()
            ->push(['error' => ['code' => 'out_of_stock', 'message' => 'Sold out']], 409)
            ->push(['error' => ['code' => 'insufficient_balance', 'message' => 'Top up']], 402)
            ->push(['error' => ['code' => 'rate_limited', 'message' => 'Slow down']], 429),
        ]);

        $api = app(RechargeGamesService::class);
        $call = fn () => $api->createOrder('free-fire/brazil', 'topup/free-fire/brazil/100 Diamonds', ['player_id' => '1'], 'k');

        foreach ([true, true, false] as $definitive) {
            try {
                $call();
                $this->fail('A ProviderException was expected.');
            } catch (ProviderException $e) {
                $this->assertSame($definitive, $e->isDefinitiveRejection());
            }
        }
    }

    public function test_get_order_returns_the_status(): void
    {
        Http::fake([self::HOST.'/v1/orders/ORD-7K3M9Q' => Http::response(['order_id' => 'ORD-7K3M9Q', 'status' => 'delivered'])]);

        $this->assertSame('delivered', app(RechargeGamesService::class)->getOrder('ORD-7K3M9Q')['status']);
    }

    public function test_an_old_style_category_id_is_refused_clearly(): void
    {
        $this->expectException(ProviderException::class);

        app(RechargeGamesService::class)->getOffers('free-fire');
    }

    // ---------------------------------------------------------------- webhook

    private function webhook(string $body, ?int $timestamp = null, ?string $secretOverride = null): \Illuminate\Testing\TestResponse
    {
        $raw = 'test-secret-bytes-1234567890';
        config(['rechargegames.webhook_secret' => 'whsec_'.base64_encode($raw)]);

        $id = 'msg_123';
        $timestamp ??= now()->getTimestamp();
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $secretOverride ?? $raw, true));

        return $this->call('POST', '/webhooks/rechargegames', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => 'v1,'.$signature,
        ], $body);
    }

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        $body = json_encode(['type' => 'order.delivered', 'id' => 'evt_1', 'data' => ['order_id' => 'ORD-UNKNOWN1', 'status' => 'delivered']]);

        $this->webhook($body)->assertOk()->assertJson(['ok' => true, 'result' => 'ignored']);
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $this->webhook('{"type":"order.delivered"}', null, 'another-secret')->assertStatus(401);
    }

    public function test_an_old_timestamp_is_refused(): void
    {
        $this->webhook('{"type":"order.delivered"}', now()->subMinutes(20)->getTimestamp())->assertStatus(401);
    }

    public function test_without_a_secret_the_endpoint_does_not_exist(): void
    {
        config(['rechargegames.webhook_secret' => null]);

        $this->postJson('/webhooks/rechargegames', ['type' => 'webhook.test'])->assertNotFound();
    }
}
