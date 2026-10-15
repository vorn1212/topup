<?php

namespace Tests\Concerns;

use App\Enums\MarkupType;
use App\Models\Game;
use App\Models\GameField;
use App\Models\Product;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Str;

trait MakesCatalog
{
    protected function makeGame(array $attributes = []): Game
    {
        $game = new Game;
        $game->forceFill(array_merge([
            'name' => 'Test Game '.Str::random(5),
            'slug' => 'test-game-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'sort_order' => 0,
        ], $attributes))->save();

        return $game;
    }

    protected function makeProduct(
        ?Game $game = null,
        string $cost = '0.80',
        MarkupType $type = MarkupType::FixedMarkup,
        string $value = '0.20',
        array $attributes = [],
    ): Product {
        $game ??= $this->makeGame();

        $product = new Product;
        $product->forceFill(array_merge([
            'game_id' => $game->id,
            'name' => 'Pack '.Str::random(4),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'currency' => 'USD',
            'status' => 'active',
            'sort_order' => 0,
        ], $attributes))->save();

        return app(PricingService::class)->update($product, $cost, $type, $value, null, 'Test setup');
    }

    protected function makeField(Game $game, string $name = 'player_id', ?string $rules = 'digits_between:5,15'): GameField
    {
        $field = new GameField([
            'provider_field_name' => $name,
            'label' => ucwords(str_replace('_', ' ', $name)),
            'type' => 'text',
            'required' => true,
            'validation_rules' => $rules,
            'sort_order' => 0,
        ]);
        $field->game_id = $game->id;
        $field->save();

        return $field;
    }
}