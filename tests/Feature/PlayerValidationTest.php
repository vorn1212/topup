<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

/**
 * RechargeGames has no general Player ID check, so the site reports "not supported" and never blocks the customer.
 */
class PlayerValidationTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private Game $game;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rechargegames.api_key' => 'gv_live_test-key-123',
            'rechargegames.base_url' => 'https://api.rechargegame.games',
            'rechargegames.retry_sleep_ms' => 0,
        ]);

        $this->game = $this->makeGame([
            'slug' => 'pubg-mobile',
            'provider_category_id' => 'pubg-mobile/global',
            'validation_category_id' => 'pubg_mobile',
        ]);
        $this->addField($this->game, 'player_id', 'Player ID', 'digits_between:5,15');

        $this->user = User::factory()->create();
    }

    private function addField(Game $game, string $name, string $label, ?string $rules = null): void
    {
        $field = new GameField([
            'provider_field_name' => $name,
            'label' => $label,
            'type' => 'text',
            'required' => true,
            'validation_rules' => $rules,
            'sort_order' => 0,
        ]);
        $field->game_id = $game->id;
        $field->save();
    }

    private function check(array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/games/pubg-mobile/validate-player', ['fields' => $fields]);
    }

    public function test_guests_get_not_supported_and_no_provider_call_is_made(): void
    {
        Http::fake();

        $this->postJson('/games/pubg-mobile/validate-player', ['fields' => ['player_id' => '123456789']])
            ->assertOk()
            ->assertJsonPath('data.supported', false);

        Http::assertNothingSent();
    }

    public function test_customers_get_not_supported_without_any_provider_text(): void
    {
        Http::fake();

        $response = $this->check(['player_id' => '123456789'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.supported', false);

        $this->assertArrayNotHasKey('detail', $response->json('data'));
        Http::assertNothingSent();
    }

    public function test_invalid_input_is_rejected_without_calling_the_provider(): void
    {
        Http::fake();

        $response = $this->check(['player_id' => 'abc'])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertArrayHasKey('fields.player_id', $response->json('errors'));
        Http::assertNothingSent();
    }

    public function test_a_game_without_a_check_category_is_not_supported(): void
    {
        Http::fake();
        $plain = $this->makeGame(['slug' => 'plain-game']);
        $this->addField($plain, 'uid', 'UID');

        $this->actingAs($this->user)->postJson('/games/plain-game/validate-player', ['fields' => ['uid' => '12345']])
            ->assertOk()
            ->assertJsonPath('data.supported', false);

        Http::assertNothingSent();
    }

    public function test_suspended_users_and_inactive_games_are_blocked(): void
    {
        Http::fake();

        $this->check(['player_id' => '123456789'], User::factory()->suspended()->create())->assertForbidden();

        $this->game->forceFill(['status' => 'inactive'])->save();
        $this->check(['player_id' => '123456789'])->assertNotFound();

        Http::assertNothingSent();
    }
}
