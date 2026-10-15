<?php

namespace Tests\Feature;

use App\Contracts\TopUpProvider;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTimeoutException;
use App\Services\TopUp\FakeTopUpProvider;
use App\Services\TopUp\FazerCardsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FakeTopUpProviderTest extends TestCase
{
    private FakeTopUpProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->fake = new FakeTopUpProvider;
    }

    public function test_the_same_key_returns_the_same_order(): void
    {
        $a = $this->fake->createOrder('cat', 'offer', ['player_id' => '123456789'], 'key-1');
        $b = $this->fake->createOrder('cat', 'offer', ['player_id' => '123456789'], 'key-1');
        $c = $this->fake->createOrder('cat', 'offer', ['player_id' => '123456789'], 'key-2');

        $this->assertSame($a['id'], $b['id']);
        $this->assertNotSame($a['id'], $c['id']);
        $this->assertSame('processing', $a['status']);
    }

    public function test_player_ids_decide_the_outcome(): void
    {
        try {
            $this->fake->createOrder('cat', 'offer', ['player_id' => '123000'], 'key-3');
            $this->fail('000 must be refused.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isDefinitiveRejection());
        }

        try {
            $this->fake->createOrder('cat', 'offer', ['player_id' => '123111'], 'key-4');
            $this->fail('111 must time out the first time.');
        } catch (ProviderTimeoutException) {
            $this->assertFalse(false);
        }

        // The order WAS created: the retry with the same key returns it.
        $retry = $this->fake->createOrder('cat', 'offer', ['player_id' => '123111'], 'key-4');
        $this->assertMatchesRegularExpression(FazerCardsService::ORDER_ID_PATTERN, $retry['id']);
    }

    public function test_orders_finish_after_a_delay(): void
    {
        $order = $this->fake->createOrder('cat', 'offer', ['player_id' => '123456789'], 'key-5');
        $this->assertSame('processing', $this->fake->getOrder($order['id'])['status']);

        $this->travel(20)->seconds();
        $this->assertSame('completed', $this->fake->getOrder($order['id'])['status']);

        $failing = $this->fake->createOrder('cat', 'offer', ['player_id' => '123999'], 'key-6');
        $this->travel(20)->seconds();
        $this->assertSame('failed', $this->fake->getOrder($failing['id'])['status']);
    }

    public function test_the_fake_is_never_used_unless_asked_for(): void
    {
        config(['fazercards.fake' => false]);
        $this->assertInstanceOf(FazerCardsService::class, app(TopUpProvider::class));

        config(['fazercards.fake' => true]);
        $this->assertInstanceOf(FakeTopUpProvider::class, app(TopUpProvider::class));
    }
}