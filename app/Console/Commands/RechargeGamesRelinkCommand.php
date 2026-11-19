<?php

namespace App\Console\Commands;

use App\Exceptions\ProviderException;
use App\Models\Game;
use App\Models\Product;
use App\Models\ProviderOffer;
use App\Services\Pricing\PricingService;
use App\Services\TopUp\CatalogSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RechargeGamesRelinkCommand extends Command
{
    protected $signature = 'rechargegames:relink {game : the game slug} {category : RechargeGames category, like free-fire/brazil}';

    protected $description = 'Link a game to a RechargeGames category and re-attach its existing products by matching names (prices and markups are kept).';

    public function handle(CatalogSyncService $sync, PricingService $pricing): int
    {
        $game = Game::query()->where('slug', (string) $this->argument('game'))->first();
        $category = (string) $this->argument('category');

        if (! $game) {
            $this->error('No game with that slug.');

            return self::FAILURE;
        }

        if (filled($game->provider_category_id) && $game->provider_category_id !== $category) {
            $this->error("This game is already linked to \"{$game->provider_category_id}\".");

            return self::FAILURE;
        }

        if (Game::query()->where('provider_category_id', $category)->where('id', '!=', $game->id)->exists()) {
            $this->error('Another game already uses this category.');

            return self::FAILURE;
        }

        $game->forceFill(['provider' => 'rechargegames', 'provider_category_id' => $category])->save();

        try {
            $sync->syncCategoryNow($category);
        } catch (ProviderException|RuntimeException $e) {
            $this->error('Could not read the category: '.$e->getMessage());

            return self::FAILURE;
        }

        $offers = ProviderOffer::query()
            ->where('provider', 'rechargegames')
            ->where('provider_category_id', $category)
            ->where('is_available', true)
            ->get()
            ->keyBy(fn (ProviderOffer $offer) => $this->normalize($offer->name));

        $matched = 0;
        $unmatched = [];

        $products = Product::query()
            ->where('game_id', $game->id)
            ->where('provider', '!=', 'rechargegames')
            ->orderBy('id')
            ->get();

        foreach ($products as $product) {
            $offer = $offers->get($this->normalize($product->name));

            if ($offer === null) {
                $unmatched[] = $product->name;

                continue;
            }

            try {
                $product->forceFill([
                    'provider' => 'rechargegames',
                    'provider_offer_id' => $offer->provider_offer_id,
                    'is_available' => true,
                ])->save();

                $pricing->syncCost($product->refresh(), (string) $offer->provider_cost);
                $matched++;
            } catch (InvalidArgumentException|Throwable $e) {
                $unmatched[] = $product->name.' ('.$e->getMessage().')';
            }
        }

        $this->info("Linked {$game->name} to {$category}. Products matched: {$matched}.");

        foreach ($unmatched as $name) {
            $this->warn("Not matched (left unavailable): {$name}");
        }

        if ($unmatched !== []) {
            $this->line('Use Admin > Games > this game > Import offers for the new packages, then delete the old unmatched ones.');
        }

        return self::SUCCESS;
    }

    private function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
    }
}
