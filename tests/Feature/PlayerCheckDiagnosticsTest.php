<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class PlayerCheckDiagnosticsTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fazercards.api_key' => 'test-key-123', 'fazercards.base_url' => 'https://api.fzr.cards/api/v2', 'fazercards.retry_sleep_ms' => 0]);

        $this->game = $this->makeGame(['slug' => 'mobile-legends', 'validation_category_id' => 'mlbb']);
        $this->makeField($this->game, 'player_id');
    }

    private function fakeProvider(array $expectedKeys, int $postStatus = 200): void
    {
        Http::fake(['*/topups/validate-id*' => function (Request $request) use ($expectedKeys, $postStatus) {
            if ($request->method() === 'GET') {
                return Http::response(['ok' => true, 'items' => [[
                    'category_id' => 'mlbb',
                    'name' => 'Mobile Legends',
                    'fields' => array_map(fn ($key) => ['key' => $key, 'label' => $key, 'type' => 'text'], $expectedKeys),
                ]]]);
            }

            return $postStatus === 200
                ? Http::response(['ok' => true, 'valid' => true, 'player_name' => 'PlayerNick'])
                : Http::response(['ok' => false, 'error' => 'secret internal failure'], $postStatus);
        }]);
    }

    private function check(User $user)
    {
        return $this->actingAs($user)->postJson('/games/mobile-legends/validate-player', ['fields' => ['player_id' => '123456789']]);
    }

    public function test_mismatched_field_names_are_explained_to_staff_only_and_the_provider_is_not_asked(): void
    {
        $this->fakeProvider(['user_id', 'zone_id']);

        $staff = $this->check(User::factory()->admin()->create())->assertOk()->assertJsonPath('data.supported', false);
        $this->assertStringContainsString('user_id, zone_id', $staff->json('data.detail'));
        $this->assertStringContainsString('player_id', $staff->json('data.detail'));

        $customer = $this->check(User::factory()->create())->assertOk()->assertJsonPath('data.supported', false);
        $this->assertArrayNotHasKey('detail', $customer->json('data'));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_provider_failure_shows_the_real_reason_to_staff_but_never_to_customers(): void
    {
        $this->fakeProvider(['player_id'], 500);

        $staff = $this->check(User::factory()->admin()->create())->assertStatus(503);
        $this->assertStringContainsString('HTTP 500', $staff->json('errors.detail'));
        $this->assertStringContainsString('secret internal failure', $staff->json('errors.detail'));

        $customer = $this->check(User::factory()->create())->assertStatus(503);
        $this->assertStringNotContainsString('secret internal failure', $customer->getContent());
        $this->assertArrayNotHasKey('detail', (array) $customer->json('errors'));
    }

    public function test_an_unknown_check_category_is_reported_to_staff(): void
    {
        $this->game->forceFill(['validation_category_id' => 'gone'])->save();
        $this->fakeProvider(['player_id']);

        $this->check(User::factory()->admin()->create())
            ->assertOk()
            ->assertJsonPath('data.supported', false)
            ->assertJsonPath('data.detail', fn (string $detail) => str_contains($detail, 'gone'));
    }

    public function test_a_partly_matching_set_of_fields_is_explained_to_staff_and_not_sent(): void
    {
        $this->fakeProvider(['player_id', 'zone_id']);

        $staff = $this->check(User::factory()->admin()->create())->assertOk()->assertJsonPath('data.supported', false);
        $this->assertStringContainsString('player_id, zone_id', $staff->json('data.detail'));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }
}