<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class PlayerValidationTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private Game $game;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fazercards.api_key' => 'test-key-123',
            'fazercards.base_url' => 'https://api.fzr.cards/api/v2',
            'fazercards.retry_sleep_ms' => 0,
        ]);

        $this->game = $this->makeGame([
            'slug' => 'pubg-mobile',
            'provider_category_id' => 'cat_pubgm_1',
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

    private function fakeProvider(array $listedFields = ['player_id']): void
    {
        Http::fake(['*/topups/validate-id*' => function (Request $request) use ($listedFields) {
            if ($request->method() === 'GET') {
                return Http::response(['ok' => true, 'items' => [[
                    'category_id' => 'pubg_mobile',
                    'name' => 'PUBG Mobile',
                    'fields' => array_map(fn ($key) => ['key' => $key, 'label' => $key, 'type' => 'text'], $listedFields),
                ]]]);
            }

            return Http::response(['ok' => true, 'category_id' => 'pubg_mobile', 'valid' => true, 'player_name' => 'PlayerNick', 'region' => 'SEA']);
        }]);
    }

    private function check(array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/games/pubg-mobile/validate-player', ['fields' => $fields]);
    }

    public function test_guests_cannot_use_the_check(): void
    {
        $this->post('/games/pubg-mobile/validate-player', ['fields' => ['player_id' => '123456789']])->assertRedirect('/login');
    }

    public function test_a_valid_player_is_reported_with_the_name(): void
    {
        $this->fakeProvider();

        $this->check(['player_id' => '123456789'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.player_name', 'PlayerNick')
            ->assertJsonPath('data.region', 'SEA');
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

    public function test_a_provider_failure_gives_a_friendly_message_and_no_provider_text(): void
    {
        Http::fake(['*/topups/validate-id*' => function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['ok' => true, 'items' => [['category_id' => 'pubg_mobile', 'name' => 'PUBG', 'fields' => [['key' => 'player_id', 'label' => 'P', 'type' => 'text']]]]])
                : Http::response(['ok' => false, 'error' => 'secret internal failure'], 500);
        }]);

        $response = $this->check(['player_id' => '123456789'])->assertStatus(503);

        $this->assertStringContainsString('You can still continue', $response->json('message'));
        $this->assertStringNotContainsString('secret internal failure', $response->getContent());
    }

    public function test_identical_checks_are_cached(): void
    {
        $this->fakeProvider();

        $this->check(['player_id' => '123456789'])->assertOk();
        $this->check(['player_id' => '123456789'])->assertOk();

        // One request for the provider's game list and ONE validation call in total.
        Http::assertSentCount(2);
    }

    public function test_only_fields_the_provider_expects_are_forwarded(): void
    {
        $this->addField($this->game, 'server_id', 'Server ID');
        $this->fakeProvider(['player_id']);

        $this->check(['player_id' => '123456789', 'server_id' => '2001', 'junk' => 'x'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && array_keys((array) $r['fields']) === ['player_id']);
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