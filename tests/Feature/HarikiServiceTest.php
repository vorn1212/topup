<?php

namespace Tests\Feature;

use App\Exceptions\ProviderException;
use App\Services\TopUp\HarikiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HarikiServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://api.harikitopup.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hariki.api_key' => 'hrk_live_test_key',
            'hariki.base_url' => self::HOST,
            'hariki.retry_sleep_ms' => 0,
            'hariki.fake' => false,
            'hariki.fee_per_order' => '0.02',
            'hariki.poll_guard' => true,
            'hariki.callback_url' => 'https://keotopup.com/webhooks/hariki',
        ]);
    }

    private function products(): array
    {
        return ['success' => true, 'data' => ['products' => [
            ['game_code' => 'mlbb', 'product_code' => 'mlbb_275', 'name' => '275', 'price' => 3.93, 'currency' => 'USD', 'status' => 'active'],
            ['game_code' => 'mlbb', 'product_code' => 'mlbb_257', 'name' => '257', 'price' => 3.71, 'currency' => 'USD', 'status' => 'inactive'],
            ['game_code' => 'ff', 'product_code' => 'ff_100', 'name' => '100', 'price' => 1.0, 'currency' => 'USD', 'status' => 'active'],
        ]]];
    }

    public function test_the_balance_and_profile_are_read(): void
    {
        Http::fake(['*/api/v1/profile' => Http::response([
            'success' => true,
            'data' => ['email' => 'a@b.c', 'balance' => 60.01, 'currency' => 'USD', 'subscription' => ['active' => true, 'plan' => 'Monthly Plan']],
        ])]);

        $api = app(HarikiService::class);

        $this->assertSame(['balance' => '60.0100', 'currency' => 'USD'], $api->getBalance());
        $this->assertTrue($api->getProfile()['subscriptionActive']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'hrk_live_test_key'));
    }

    public function test_categories_are_the_game_codes(): void
    {
        Http::fake(['*/api/v1/products*' => Http::response($this->products())]);

        $ids = array_column(app(HarikiService::class)->getCategories(), 'category_id');

        $this->assertEqualsCanonicalizing(['mlbb', 'ff'], $ids);
    }

    public function test_offers_skip_inactive_products_and_add_the_order_fee(): void
    {
        Http::fake(['*/api/v1/products*' => Http::response($this->products())]);

        $data = app(HarikiService::class)->getOffers('mlbb');

        $this->assertCount(1, $data['offers']);
        $this->assertSame('mlbb_275', $data['offers'][0]['offer_id']);
        $this->assertSame('3.9500', $data['offers'][0]['price_usd']);
        $this->assertSame(['player_id', 'server_id'], array_column($data['fields'], 'key'));
    }

    public function test_an_order_is_sent_with_our_reference_and_the_callback_url(): void
    {
        Http::fake(['*/api/v1/order' => Http::response(['success' => true, 'data' => ['order_id' => 'H-1', 'status' => 'PENDING']])]);

        $result = app(HarikiService::class)->createOrder('mlbb', 'mlbb_275', ['player_id' => '411865236', 'server_id' => '3864'], 'key-abc-123');

        $this->assertSame('key-abc-123', $result['id']);
        $this->assertSame('processing', $result['status']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['game_code'] === 'mlbb'
            && $r['product_code'] === 'mlbb_275'
            && $r['user_id'] === '411865236'
            && $r['server_id'] === '3864'
            && $r['ref_order'] === 'key-abc-123'
            && $r['callback_url'] === 'https://keotopup.com/webhooks/hariki');
    }

    public function test_an_order_is_never_retried_on_a_server_error(): void
    {
        Http::fake(['*/api/v1/order' => Http::response(['success' => false, 'message' => 'boom'], 500)]);

        try {
            app(HarikiService::class)->createOrder('mlbb', 'mlbb_275', ['player_id' => '1'], 'k1');
            $this->fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            $this->assertSame(500, $e->httpStatus);
        }

        Http::assertSentCount(1);
    }

    public function test_a_refusal_with_http_200_is_a_clear_refusal(): void
    {
        Http::fake(['*/api/v1/order' => Http::response(['success' => false, 'message' => 'Insufficient balance'], 200)]);

        try {
            app(HarikiService::class)->createOrder('mlbb', 'mlbb_275', ['player_id' => '1'], 'k2');
            $this->fail('Expected a ProviderException.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isDefinitiveRejection());
        }
    }

    public function test_status_mapping(): void
    {
        $api = app(HarikiService::class);

        $this->assertSame('completed', $api->status('SUCCESS'));
        $this->assertSame('failed', $api->status('FAILED'));
        $this->assertSame('processing', $api->status('PENDING'));
        $this->assertSame('processing', $api->status('something-new'));
    }

    public function test_orders_are_not_polled_unless_a_webhook_woke_them(): void
    {
        Cache::flush();
        Http::fake(['*/api/v1/order/*' => Http::response(['success' => true, 'data' => ['status' => 'SUCCESS']])]);
        $api = app(HarikiService::class);

        // First look is allowed (safety net), a second one right after is not.
        $this->assertSame('completed', $api->getOrder('ref-1')['status']);
        $this->assertSame('processing', $api->getOrder('ref-1')['status']);
        Http::assertSentCount(1);

        // A webhook wake-up lets one more lookup through.
        Cache::put('hariki:wake:ref-1', true, 300);
        $this->assertSame('completed', $api->getOrder('ref-1')['status']);
        Http::assertSentCount(2);
    }

    public function test_the_player_check(): void
    {
        Http::fake(['*/api/v1/check-player' => Http::response(['success' => true, 'data' => ['valid' => true, 'name' => 'YuricccZzz', 'player_id' => '411865236']])]);

        $result = app(HarikiService::class)->validatePlayer('mlbb', ['player_id' => '411865236', 'server_id' => '3864']);

        $this->assertTrue($result['valid']);
        $this->assertSame('YuricccZzz', $result['player_name']);
        Http::assertSent(fn (Request $r) => $r['game'] === 'mlbb' && $r['user_id'] === '411865236' && $r['server_id'] === '3864');
    }

    public function test_an_unknown_player_is_not_an_error(): void
    {
        Http::fake(['*/api/v1/check-player' => Http::response(['success' => false, 'message' => 'Player not found'], 404)]);

        $this->assertFalse(app(HarikiService::class)->validatePlayer('mlbb', ['player_id' => '1'])['valid']);
    }

    // ---------------------------------------------------------------- webhook

    private function webhook(string $body, ?int $timestamp = null, string $signWith = 'whsec_test_secret_123', ?string $digest = null)
    {
        config(['hariki.webhook_secret' => 'whsec_test_secret_123']);

        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $signWith);

        return $this->call('POST', '/webhooks/hariki', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HARIKI_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_HARIKI_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_X_HARIKI_CONTENT_DIGEST' => $digest ?? 'sha256='.hash('sha256', $body),
        ], $body);
    }

    private function body(): string
    {
        return json_encode(['event' => 'order.updated', 'data' => ['order_id' => 'H-1', 'ref_order' => 'nobody-1', 'status' => 'SUCCESS']]);
    }

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        $this->webhook($this->body())->assertOk()->assertJson(['ok' => true, 'result' => 'ignored']);
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $this->webhook($this->body(), null, 'another-secret')->assertStatus(401);
    }

    public function test_a_wrong_digest_is_refused(): void
    {
        $this->webhook($this->body(), null, 'whsec_test_secret_123', 'sha256='.str_repeat('0', 64))->assertStatus(401);
    }

    public function test_an_old_timestamp_is_refused(): void
    {
        $this->webhook($this->body(), now()->subMinutes(20)->getTimestamp())->assertStatus(401);
    }

    public function test_without_a_secret_the_endpoint_does_not_exist(): void
    {
        config(['hariki.webhook_secret' => null]);

        $this->postJson('/webhooks/hariki', ['event' => 'x'])->assertNotFound();
    }
}
