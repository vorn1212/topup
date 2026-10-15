<?php

namespace Database\Seeders;

use App\Enums\MarkupType;
use App\Models\Game;
use App\Models\GameField;
use App\Models\Product;
use App\Services\Pricing\PricingService;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA for local testing. Costs are made up. The FazerCards sync (Phase 6) replaces them.
 */
class CatalogSeeder extends Seeder
{
    public function run(PricingService $pricing): void
    {
        $catalog = [
            [
                'name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'sku' => 'MLBB',
                'type' => MarkupType::FixedMarkup, 'value' => '0.20',
                'fields' => [
                    ['player_id', 'Player ID', 'text', 'digits_between:5,15', null],
                    ['server_id', 'Server ID', 'text', 'digits_between:1,6', null],
                ],
                'products' => [['86 Diamonds', '0.80'], ['172 Diamonds', '1.60'], ['257 Diamonds', '2.40'], ['344 Diamonds', '3.20']],
            ],
            [
                'name' => 'Free Fire', 'slug' => 'free-fire', 'sku' => 'FF',
                'type' => MarkupType::PercentMarkup, 'value' => '25',
                'fields' => [
                    ['player_id', 'Player ID', 'text', 'digits_between:6,15', null],
                ],
                'products' => [['100 Diamonds', '0.85'], ['310 Diamonds', '2.55'], ['520 Diamonds', '4.25']],
            ],
            [
                'name' => 'Genshin Impact', 'slug' => 'genshin-impact', 'sku' => 'GI',
                'type' => MarkupType::PercentMarkup, 'value' => '15',
                'fields' => [
                    ['uid', 'UID', 'text', 'digits_between:9,10', null],
                    ['server', 'Server', 'select', null, ['America', 'Europe', 'Asia', 'TW/HK/MO']],
                ],
                'products' => [['60 Genesis Crystals', '0.99'], ['330 Genesis Crystals', '4.95']],
            ],
        ];

        foreach ($catalog as $index => $entry) {
            $game = Game::query()->firstOrNew(['slug' => $entry['slug']]);
            $game->forceFill([
                'name' => $entry['name'],
                'description' => "Sample game for testing: {$entry['name']} top-up.",
                'status' => 'active',
                'sort_order' => $index + 1,
            ])->save();

            foreach ($entry['fields'] as $order => [$name, $label, $type, $rules, $options]) {
                GameField::query()->firstOrCreate(
                    ['game_id' => $game->id, 'provider_field_name' => $name],
                    [
                        'label' => $label,
                        'type' => $type,
                        'required' => true,
                        'validation_rules' => $rules,
                        'options' => $options,
                        'sort_order' => $order + 1,
                    ]
                );
            }

            foreach ($entry['products'] as $order => [$name, $cost]) {
                $sku = $entry['sku'].'-'.(int) preg_replace('/\D/', '', $name);

                if (Product::query()->where('sku', $sku)->exists()) {
                    continue;
                }

                $product = new Product(['name' => $name, 'status' => 'active', 'sort_order' => $order + 1]);
                $product->forceFill(['game_id' => $game->id, 'sku' => $sku, 'currency' => 'USD'])->save();

                $pricing->update($product, $cost, $entry['type'], $entry['value'], null, 'Seeded sample price');
            }
        }

        $this->command?->info('Sample catalog seeded: 3 games, 9 products.');
    }
}