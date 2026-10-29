<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Enums\WalletTxType;
use App\Models\Game;
use App\Models\GameField;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private function addField(Game $game, string $name, string $label, string $type = 'text', ?array $options = null, int $sort = 0): GameField
    {
        $field = new GameField([
            'provider_field_name' => $name,
            'label' => $label,
            'type' => $type,
            'required' => true,
            'options' => $options,
            'sort_order' => $sort,
        ]);
        $field->game_id = $game->id;
        $field->save();

        return $field;
    }

    // ---------------------------------------------------------------- listing

    public function test_home_and_games_pages_list_only_active_games(): void
    {
        $this->makeGame(['name' => 'Active Game', 'slug' => 'active-game']);
        $this->makeGame(['name' => 'Hidden Game', 'slug' => 'hidden-game', 'status' => 'inactive']);

        $this->get('/')->assertOk()->assertSee('Active Game')->assertDontSee('Hidden Game');
        $this->get('/games')->assertOk()->assertSee('Active Game')->assertDontSee('Hidden Game');
    }

    public function test_starting_price_ignores_inactive_and_unavailable_packages(): void
    {
        $game = $this->makeGame(['name' => 'Priced Game', 'slug' => 'priced-game']);
        $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20');                                   // 1.00 available
        $this->makeProduct($game, '0.30', MarkupType::FixedMarkup, '0.20', ['is_available' => false]);        // 0.50 unavailable
        $this->makeProduct($game, '0.00', MarkupType::FixedMarkup, '0.10', ['status' => 'inactive']);         // 0.10 inactive

        $this->get('/games')->assertOk()->assertSee('From')->assertSee('$1.00')->assertDontSee('$0.50')->assertDontSee('$0.10');
    }

    public function test_search_works_is_noindexed_and_escapes_wildcards(): void
    {
        $this->makeGame(['name' => 'Free Fire', 'slug' => 'free-fire']);
        $this->makeGame(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);

        $this->get('/games?q=Free')->assertOk()->assertSee('Free Fire')->assertDontSee('Mobile Legends')->assertSee('noindex');
        $this->get('/games?q=%25')->assertOk()->assertDontSee('Free Fire')->assertDontSee('Mobile Legends');
        $this->get('/games')->assertOk()->assertDontSee('noindex');
    }

    // ---------------------------------------------------------------- game page

    public function test_game_page_shows_only_active_packages_with_database_prices(): void
    {
        $game = $this->makeGame(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'description' => 'Best MOBA']);
        $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20', ['name' => '86 Diamonds']);
        $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20', ['name' => 'Hidden Pack', 'status' => 'inactive']);
        $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20', ['name' => 'Sold Out Pack', 'is_available' => false]);

        $this->get('/games/mobile-legends')
            ->assertOk()
            ->assertSee('86 Diamonds')
            ->assertSee('$1.00')
            ->assertSee('Sold Out Pack')
            ->assertSee('Unavailable')
            ->assertDontSee('Hidden Pack');
    }

    public function test_game_page_has_complete_seo_tags(): void
    {
        $this->makeGame([
            'name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'description' => 'Best MOBA',
        ]);

        $this->get('/games/mobile-legends')
            ->assertOk()
            ->assertSee('<title>Mobile Legends Top-Up | '.e(config('app.name')).'</title>', false)
            ->assertSee('<meta name="description" content="Best MOBA">', false)
            ->assertSee('<link rel="canonical" href="'.url('/games/mobile-legends').'">', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:description"', false)
            ->assertSee('property="og:url"', false)
            ->assertDontSee('noindex');
    }

    public function test_custom_meta_title_and_description_win(): void
    {
        $this->makeGame([
            'name' => 'Free Fire', 'slug' => 'free-fire',
            'meta_title' => 'Buy Free Fire Diamonds', 'meta_description' => 'Cheapest Free Fire diamonds in Cambodia.',
        ]);

        $this->get('/games/free-fire')
            ->assertSee('<title>Buy Free Fire Diamonds | '.e(config('app.name')).'</title>', false)
            ->assertSee('content="Cheapest Free Fire diamonds in Cambodia."', false);
    }

    public function test_user_supplied_text_is_escaped_everywhere(): void
    {
        $this->makeGame(['name' => '<script>alert(1)</script>', 'slug' => 'xss-game', 'description' => '<img src=x onerror=alert(2)>']);

        foreach (['/games', '/games/xss-game', '/games/xss-game/top-up'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<img src=x onerror=alert(2)>', false);
        }

        $this->get('/games/xss-game')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_inactive_or_unknown_games_are_not_found(): void
    {
        $this->makeGame(['name' => 'Hidden Game', 'slug' => 'hidden-game', 'status' => 'inactive']);

        $this->get('/games/hidden-game')->assertNotFound();
        $this->get('/games/hidden-game/top-up')->assertNotFound();
        $this->get('/games/does-not-exist')->assertNotFound();
        $this->get('/games/Bad_Slug!')->assertNotFound();
    }

    // ---------------------------------------------------------------- top-up page

    public function test_top_up_page_renders_dynamic_fields_and_remembers_the_visit_for_guests(): void
    {
        $game = $this->makeGame(['name' => 'Genshin Impact', 'slug' => 'genshin-impact']);
        $this->addField($game, 'uid', 'UID', 'text', null, 1);
        $this->addField($game, 'server', 'Server', 'select', ['Asia', 'Europe'], 2);
        $product = $this->makeProduct($game, '0.99', MarkupType::PercentMarkup, '15', ['name' => '60 Genesis Crystals']);

        $this->get("/games/genshin-impact/top-up?product={$product->id}")
            ->assertOk()
            ->assertSee('UID')
            ->assertSee('name="fields[uid]"', false)
            ->assertSee('name="fields[server]"', false)
            ->assertSee('Asia')
            ->assertSee('Europe')
            ->assertSee('60 Genesis Crystals')
             ->assertSee('$1.14')
            ->assertSee('Pay with QR')
            ->assertDontSee('to place an order')
            ->assertSessionHas('url.intended', url("/games/genshin-impact/top-up?product={$product->id}"));
    }

    public function test_top_up_page_shows_wallet_balance_and_both_payment_options(): void
    {
        $game = $this->makeGame(['slug' => 'any-game']);
        $this->addField($game, 'player_id', 'Player ID');
        $this->makeProduct($game);

        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->createWallet($user), '12.50', 'site:test:1', WalletTxType::Bonus);

        $this->actingAs($user)->get('/games/any-game/top-up')
            ->assertOk()
            ->assertSee('$12.50')
            ->assertSee('Deposit money')
            ->assertSee('Pay with wallet')
            ->assertDontSee('Pay with QR')
            ->assertDontSee('to place an order');
    }

    public function test_suspended_users_see_a_notice_on_the_top_up_page(): void
    {
        $this->makeGame(['slug' => 'any-game']);

        $this->actingAs(User::factory()->suspended()->create())
            ->get('/games/any-game/top-up')
            ->assertOk()
            ->assertSee('You cannot place orders');
    }

    // ---------------------------------------------------------------- sitemap and robots

    public function test_sitemap_lists_only_active_games_and_is_valid_xml(): void
    {
        $this->makeGame(['slug' => 'active-game']);
        $this->makeGame(['slug' => 'hidden-game', 'status' => 'inactive']);

        $response = $this->get('/sitemap.xml')->assertOk();
        $xml = $response->getContent();

        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString(route('home'), $xml);
        $this->assertStringContainsString(route('games.index'), $xml);
        $this->assertStringContainsString(route('games.show', 'active-game'), $xml);
        $this->assertStringContainsString(route('games.topup', 'active-game'), $xml);
        $this->assertStringNotContainsString('hidden-game', $xml);
    }

    public function test_robots_txt_blocks_private_areas_and_points_to_the_sitemap(): void
    {
        $response = $this->get('/robots.txt')->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        foreach (['Disallow: /admin', 'Disallow: /wallet', 'Disallow: /dashboard', 'Disallow: /webhooks'] as $line) {
            $this->assertStringContainsString($line, $body);
        }
        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $body);
        $this->assertStringNotContainsString('Disallow: /games', $body);
    }
}