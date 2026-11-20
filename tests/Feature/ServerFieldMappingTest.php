<?php

namespace Tests\Feature;

use App\Services\TopUp\RechargeGamesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerFieldMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rechargegames.api_key' => 'gv_live_test_key',
            'rechargegames.base_url' => 'https://api.rechargegame.games',
            'rechargegames.retry_sleep_ms' => 0,
            'rechargegames.fake' => false,
            'rechargegames.test_orders' => false,
        ]);
    }

    private function order(array $fields): void
    {
        Http::fake(['*/v1/orders' => Http::response(['order_id' => 'ORD-7K3M9Q', 'status' => 'pending'], 201)]);

        app(RechargeGamesService::class)->createOrder(
            'mobile-legends/global', 'topup/mobile-legends/global/Weekly Pass', $fields, 'key-1'
        );
    }

    public function test_the_old_zone_id_field_is_sent_as_server_id(): void
    {
        $this->order(['player_id' => '411865236', 'zone_id' => '3864']);

        Http::assertSent(fn (Request $r) => $r['player_id'] === '411865236' && $r['server_id'] === '3864' && ! isset($r['zone_id']));
    }

    public function test_a_server_id_field_is_still_sent_as_it_is(): void
    {
        $this->order(['player_id' => '411865236', 'server_id' => '3864']);

        Http::assertSent(fn (Request $r) => $r['server_id'] === '3864');
    }

    public function test_server_id_wins_over_zone_id(): void
    {
        $this->order(['player_id' => '1', 'server_id' => '111', 'zone_id' => '222']);

        Http::assertSent(fn (Request $r) => $r['server_id'] === '111');
    }

    public function test_games_without_a_server_send_none(): void
    {
        $this->order(['player_id' => '411865236']);

        Http::assertSent(fn (Request $r) => $r['player_id'] === '411865236' && ! isset($r['server_id']));
    }
}
