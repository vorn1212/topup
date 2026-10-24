<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class PlayerAutoCheckPageTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fazercards.api_key' => 'test-key-123', 'fazercards.fake' => false]);
    }

    private function game(array $attributes = []): Game
    {
        $game = $this->makeGame(array_merge([
            'slug' => 'pubg-mobile',
            'provider_category_id' => 'cat_pubgm_1',
            'validation_category_id' => 'pubg_mobile',
        ], $attributes));

        $this->makeField($game);
        $this->makeProduct($game, attributes: ['provider_offer_id' => 'offer_60uc']);

        return $game;
    }

    public function test_customers_get_the_automatic_check_under_the_text_boxes(): void
    {
        $this->game();

        $this->actingAs(User::factory()->create())->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertSee('id="player-check"', false)
            ->assertSee('id="player-result"', false)
            ->assertSee('data-enabled="1"', false)
            ->assertDontSee('id="check-player"', false); // the old button is gone
    }

    public function test_guests_also_get_the_automatic_check(): void
    {
        $this->game();

        $this->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertSee('data-enabled="1"', false)
            ->assertDontSee('Log in to verify your player account automatically');
    }

    public function test_games_without_a_check_category_show_nothing(): void
    {
        $this->game(['validation_category_id' => null]);

        $this->actingAs(User::factory()->create())->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertDontSee('id="player-result"', false);
    }

    public function test_nothing_is_shown_without_a_provider_key(): void
    {
        $this->game();
        config(['fazercards.api_key' => '']);

        $this->actingAs(User::factory()->create())->get('/games/pubg-mobile/top-up')
            ->assertOk()
            ->assertDontSee('id="player-result"', false);
    }
}