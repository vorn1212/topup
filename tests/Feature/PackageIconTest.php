<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageIconTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_ICON = 'games/product-icons/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';

    private const GAME_ICON = 'games/package-icons/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

    private function game(array $extra = []): Game
    {
        return Game::forceCreate(array_merge(['name' => 'Mobile Legends', 'slug' => 'mobile-legends'], $extra));
    }

    private function product(Game $game, array $extra = []): Product
    {
        return Product::forceCreate(array_merge([
            'game_id' => $game->id,
            'sku' => 'MLBB-'.random_int(100000, 999999),
            'name' => '86 Diamonds',
            'selling_price' => '1.2500',
        ], $extra));
    }

    public function test_a_product_without_any_icon_has_no_url(): void
    {
        $product = $this->product($this->game());

        $this->assertFalse($product->hasOwnIcon());
        $this->assertNull($product->iconUrl());
    }

    public function test_the_game_default_icon_is_used_when_the_product_has_none(): void
    {
        $game = $this->game(['package_icon' => self::GAME_ICON]);
        $product = $this->product($game);

        $this->assertStringEndsWith(self::GAME_ICON, (string) $product->iconUrl($game));
        $this->assertStringEndsWith(self::GAME_ICON, (string) $product->iconUrl());
    }

    public function test_the_product_icon_wins_over_the_game_icon(): void
    {
        $game = $this->game(['package_icon' => self::GAME_ICON]);
        $product = $this->product($game, ['icon' => self::PRODUCT_ICON]);

        $this->assertTrue($product->hasOwnIcon());
        $this->assertStringEndsWith(self::PRODUCT_ICON, (string) $product->iconUrl($game));
    }

    public function test_a_tampered_icon_path_is_ignored(): void
    {
        $game = $this->game(['package_icon' => '../../.env']);
        $product = $this->product($game, ['icon' => 'games/product-icons/../../.env']);

        $this->assertFalse($product->hasOwnIcon());
        $this->assertNull($product->iconUrl($game));
    }

    public function test_the_game_page_shows_each_icon_or_the_built_in_diamond(): void
    {
        $game = $this->game();
        $this->product($game, ['name' => 'Own Icon Pack', 'icon' => self::PRODUCT_ICON]);
        $this->product($game, ['name' => 'Plain Pack']);

        $this->get('/games/'.$game->slug)
            ->assertOk()
            ->assertSee('storage/'.self::PRODUCT_ICON, false)
            ->assertSee('data-package-icon="default"', false)
            ->assertSee('Own Icon Pack')
            ->assertSee('Plain Pack');
    }

    public function test_the_game_default_icon_appears_on_the_game_and_top_up_pages(): void
    {
        $game = $this->game(['package_icon' => self::GAME_ICON]);
        $this->product($game);

        $this->get('/games/'.$game->slug)->assertOk()->assertSee('storage/'.self::GAME_ICON, false);
        $this->get('/games/'.$game->slug.'/top-up')->assertOk()->assertSee('storage/'.self::GAME_ICON, false);
    }
}
