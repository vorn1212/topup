<?php

namespace Tests\Feature;

use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTimeoutException;
use App\Services\TopUp\FazerCardsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class FazerCardsServiceTest extends TestCase
{
    private FazerCardsService $api;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fazercards.api_key' => 'test-key-123',
            'fazercards.base_url' => 'https://api.fzr.cards/api/v2',
            'fazercards.retries' => 3,
            'fazercards.retry_sleep_ms' => 0,
        ]);

        $this->api = app(FazerCardsService::class);
    }

    public function test_requests_are_authenticated_and_balance_is_parsed(): void
    {
        Http::fake(['api.fzr.cards/api/v2/balance' => Http::response(['ok' => true, 'balance' => '100.0000', 'currency' => 'USD'])]);

        $balance = $this->api->getBalance();

        $this->assertSame('100.0000', $balance['balance']);
        $this->assertSame('USD', $balance['currency']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'test-key-123')
            && $r->url() === 'https://api.fzr.cards/api/v2/balance');
    }

    public function test_an_invalid_balance_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'balance' => 'lots', 'currency' => 'USD'])]);

        $this->expectException(ProviderException::class);
        $this->api->getBalance();
    }

    public function test_missing_key_fails_before_any_request(): void
    {
        config(['fazercards.api_key' => '']);
        Http::fake();

        try {
            $this->api->getBalance();
            $this->fail('A missing key must fail.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_provider_errors_are_classified_and_never_leak_the_key(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['ok' => false, 'error' => 'Invalid API key'], 401)
            ->push(['ok' => false, 'error' => 'Validation failed', 'code' => 'bad_offer'], 400)]);

        try {
            $this->api->getProfile();
            $this->fail('A 401 must throw.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isAuthError());
            $this->assertFalse($e->isDefinitiveRejection());
            $this->assertStringNotContainsString('test-key-123', $e->getMessage());
        }

        try {
            $this->api->getProfile();
            $this->fail('A 400 must throw.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isDefinitiveRejection());
            $this->assertSame('bad_offer', $e->providerCode);
            $this->assertSame('Validation failed', $e->getMessage());
        }
    }

    public function test_get_requests_are_retried_after_a_429(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['ok' => false, 'error' => 'Too many requests'], 429)
            ->push(['ok' => true, 'balance' => '5.0000', 'currency' => 'USD'])]);

        $this->assertSame('5.0000', $this->api->getBalance()['balance']);
        Http::assertSentCount(2);
    }

       public function test_a_temporarily_unavailable_provider_is_retried_then_gives_up(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'unavailable'], 503)]);

        try {
            $this->api->getBalance();
            $this->fail('A 503 must throw.');
        } catch (ProviderException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertFalse($e->isDefinitiveRejection());
        }

        Http::assertSentCount(3);
    }

    public function test_a_plain_500_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'boom'], 500)]);

        try {
            $this->api->getBalance();
            $this->fail('A 500 must throw.');
        } catch (ProviderException $e) {
            $this->assertSame(500, $e->httpStatus);
            $this->assertFalse($e->isDefinitiveRejection());
        }

        Http::assertSentCount(1);
    }

    public function test_connection_failures_become_a_timeout_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timed out');
        });

        try {
            $this->api->getBalance();
            $this->fail('A connection failure must throw.');
        } catch (ProviderTimeoutException $e) {
            $this->assertFalse($e->isDefinitiveRejection());
            $this->assertNull($e->httpStatus);
        }
    }

    public function test_categories_follow_the_cursor_and_deduplicate(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['ok' => true, 'items' => [['category_id' => 'a', 'name' => 'A'], ['category_id' => 'b', 'name' => 'B']], 'meta' => ['has_more' => true, 'next_cursor' => 'abc']])
            ->push(['ok' => true, 'items' => [['category_id' => 'c', 'name' => 'C'], ['category_id' => 'a', 'name' => 'A']], 'meta' => ['has_more' => false, 'next_cursor' => null]])]);

        $categories = $this->api->getCategories();

        $this->assertSame(['a', 'b', 'c'], array_column($categories, 'category_id'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cursor=abc'));
        Http::assertSentCount(2);
    }

    public function test_a_repeating_cursor_cannot_loop_forever(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'items' => [['category_id' => 'a', 'name' => 'A']], 'meta' => ['has_more' => true, 'next_cursor' => 'same']])]);

        $this->assertCount(1, $this->api->getCategories());
        Http::assertSentCount(2);
    }

    public function test_offers_and_fields_are_parsed_and_malformed_entries_skipped(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true,
            'category_id' => 'cat_pubgm_1',
            'name' => 'PUBG Mobile',
            'offers' => [
                ['offer_id' => 'offer_60uc', 'name' => '60 UC', 'price_usd' => '0.9900'],
                ['offer_id' => 'offer_bad', 'name' => 'Bad', 'price_usd' => 'free'],
                ['name' => 'No id', 'price_usd' => '1.00'],
            ],
            'fields' => [
                ['key' => 'player_id', 'label' => 'Player ID', 'type' => 'text'],
                ['key' => 'bad key!', 'label' => 'Nope', 'type' => 'text'],
                ['key' => 'server', 'label' => 'Server', 'type' => 'weird'],
            ],
        ])]);

        $data = $this->api->getOffers('cat_pubgm_1');

        $this->assertCount(1, $data['offers']);
        $this->assertSame('0.9900', $data['offers'][0]['price_usd']);
        $this->assertSame(2, $data['skipped']);
        $this->assertSame(['player_id', 'server'], array_column($data['fields'], 'key'));
        $this->assertSame('text', $data['fields'][1]['type']);
    }

    public function test_create_order_sends_the_idempotency_key_and_body(): void
    {
        Http::fake(['*/topups/order' => Http::response(['ok' => true, 'order' => ['id' => 'ord-9002', 'kind' => 'topup', 'status' => 'Processing']])]);

        $order = $this->api->createOrder('cat_pubgm_1', 'offer_60uc', ['player_id' => '123456789'], 'purchase:order:1');

        $this->assertSame('ord-9002', $order['id']);
        $this->assertSame('processing', $order['status']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->hasHeader('Idempotency-Key', 'purchase:order:1')
            && $r->hasHeader('X-API-Key', 'test-key-123')
            && $r['category_id'] === 'cat_pubgm_1'
            && $r['offer_id'] === 'offer_60uc'
            && ((array) $r['fields'])['player_id'] === '123456789');
    }

    public function test_create_order_is_never_retried_inside_the_service(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'boom'], 500)]);

        try {
            $this->api->createOrder('cat', 'offer', ['player_id' => '1'], 'key-1');
            $this->fail('A 500 must throw.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->isDefinitiveRejection());
        }

        Http::assertSentCount(1);
    }

    public function test_a_refused_order_is_a_definitive_rejection(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid player id'], 400)]);

        try {
            $this->api->createOrder('cat', 'offer', ['player_id' => 'x'], 'key-2');
            $this->fail('A 400 must throw.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isDefinitiveRejection());
        }
    }

    public function test_a_success_without_an_order_id_is_an_unknown_state_not_a_refusal(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'order' => ['status' => 'processing']])]);

        try {
            $this->api->createOrder('cat', 'offer', ['player_id' => '1'], 'key-3');
            $this->fail('A missing order id must throw.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->isDefinitiveRejection());
        }
    }

    public function test_the_idempotency_key_is_validated(): void
    {
        Http::fake();

        foreach (['', str_repeat('k', 256)] as $bad) {
            try {
                $this->api->createOrder('cat', 'offer', [], $bad);
                $this->fail('Bad key must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        Http::assertNothingSent();
    }

    public function test_get_order_validates_the_id_before_calling_the_provider(): void
    {
        Http::fake();

        foreach (['ord_123', 'ord-1/../me', 'tx1001', ''] as $bad) {
            try {
                $this->api->getOrder($bad);
                $this->fail("Order id [{$bad}] must be rejected.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        Http::assertNothingSent();
    }

    public function test_get_order_returns_the_normalised_status(): void
    {
        Http::fake(['*/orders/ord-9001' => Http::response(['ok' => true, 'order' => ['id' => 'ord-9001', 'kind' => 'topup', 'status' => 'COMPLETED']])]);

        $order = $this->api->getOrder('ord-9001');

        $this->assertSame('ord-9001', $order['id']);
        $this->assertSame('completed', $order['status']);
    }

    public function test_player_validation_is_mapped(): void
    {
        Http::fake(['*/topups/validate-id' => Http::sequence()
            ->push(['ok' => true, 'category_id' => 'pubg_mobile', 'valid' => true, 'player_name' => 'PlayerNick', 'region' => 'SEA'])
            ->push(['ok' => true, 'category_id' => 'pubg_mobile', 'valid' => false])]);

        $valid = $this->api->validatePlayer('pubg_mobile', ['player_id' => '123456789']);
        $this->assertTrue($valid['valid']);
        $this->assertSame('PlayerNick', $valid['player_name']);
        $this->assertSame('SEA', $valid['region']);

        $invalid = $this->api->validatePlayer('pubg_mobile', ['player_id' => '1']);
        $this->assertFalse($invalid['valid']);
        $this->assertNull($invalid['player_name']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['category_id'] === 'pubg_mobile'
            && ((array) $r['fields'])['player_id'] === '123456789');
    }
}