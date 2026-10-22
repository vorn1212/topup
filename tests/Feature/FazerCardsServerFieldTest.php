<?php

namespace Tests\Feature;

use App\Services\TopUp\FazerCardsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FazerCardsServerFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['fazercards.api_key' => 'test-key', 'fazercards.base_url' => 'https://fazer.test/api']);

        Http::fake(['*' => Http::response(['ok' => true, 'order' => ['id' => 'ord-123', 'status' => 'processing']], 200)]);
    }

    /** The "fields" object that was really sent to FazerCards. */
    private function sentFields(): array
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded, 'Exactly one request should have been sent.');

        $request = $recorded->first()[0];
        $this->assertStringEndsWith('/topups/order', $request->url());

        $body = json_decode($request->body(), true);
        $this->assertIsArray($body, 'The request body should be JSON: '.$request->body());

        return (array) ($body['fields'] ?? []);
    }

    public function test_mobile_legends_orders_send_the_server_as_server_id(): void
    {
        $order = app(FazerCardsService::class)->createOrder(
            'mobile_legends_global',
            'offer-1',
            ['player_id' => '292315248', 'zone_id' => '9531'],
            'key-1'
        );

        $this->assertSame('ord-123', $order['id']);
        $this->assertEquals(['player_id' => '292315248', 'server_id' => '9531'], $this->sentFields());
    }

    public function test_an_existing_server_id_is_never_overwritten(): void
    {
        app(FazerCardsService::class)->createOrder(
            'mobile_legends_special',
            'offer-1',
            ['player_id' => '1', 'server_id' => '9', 'zone_id' => '2'],
            'key-2'
        );

        $this->assertEquals(['player_id' => '1', 'server_id' => '9', 'zone_id' => '2'], $this->sentFields());
    }

    public function test_other_games_keep_their_field_names(): void
    {
        app(FazerCardsService::class)->createOrder(
            'free_fire',
            'offer-1',
            ['player_id' => '1', 'zone_id' => '2'],
            'key-3'
        );

        $this->assertEquals(['player_id' => '1', 'zone_id' => '2'], $this->sentFields());
    }
}
