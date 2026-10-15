<?php

namespace App\Services\TopUp;

use App\Exceptions\ProviderException;
use App\Models\Game;
use App\Models\Product;
use App\Models\ProviderCategory;
use App\Models\ProviderOffer;
use App\Services\AuditService;
use App\Services\Pricing\PricingService;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mirrors the provider catalog locally and keeps linked products' costs and availability current.
 *
 * Only categories linked to a game (games.provider_category_id) are fetched in detail, one call at
 * a time with a pause between them. Pages never call the provider: they read this local data.
 */
class CatalogSyncService
{
    private const PROVIDER = 'fazercards';

    public function __construct(
        private readonly FazerCardsService $provider,
        private readonly PricingService $pricing,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{categories:int,linked_categories:int,offers:int,price_changes:int,products_unavailable:int,skipped_offers:int,errors:list<string>}
     *
     * @throws RuntimeException when another sync is running
     * @throws ProviderException on an authentication problem or when the category list cannot be read
     */
    public function sync(): array
    {
        $lock = Cache::lock('fazercards:catalog-sync', 900);

        if (! $lock->get()) {
            throw new RuntimeException('A FazerCards catalog sync is already running.');
        }

        try {
            $summary = $this->run();
        } finally {
            $lock->release();
        }

        Cache::put('fazercards:last_sync', ['at' => now()->toIso8601String()] + $summary, now()->addDays(2));

        $this->audit->log('catalog.synced', null, [], array_merge($summary, [
            'errors' => array_slice($summary['errors'], 0, 20),
        ]));

        return $summary;
    }

        /**
     * Refresh ONE linked category right now (used by the admin buttons).
     *
     * @return array{categories:int,linked_categories:int,offers:int,price_changes:int,products_unavailable:int,skipped_offers:int,errors:list<string>}
     *
     * @throws RuntimeException when a sync is already running
     * @throws ProviderException when the provider cannot be read
     */
    public function syncCategoryNow(string $categoryId): array
    {
        $lock = Cache::lock('fazercards:catalog-sync', 900);

        if (! $lock->get()) {
            throw new RuntimeException('A FazerCards catalog sync is already running. Try again in a minute.');
        }

        try {
            $summary = [
                'categories' => 0,
                'linked_categories' => 1,
                'offers' => 0,
                'price_changes' => 0,
                'products_unavailable' => 0,
                'skipped_offers' => 0,
                'errors' => [],
            ];

            $this->syncCategory($categoryId, $summary);

            return $summary;
        } finally {
            $lock->release();
        }
    }

    private function run(): array
    {
        $summary = [
            'categories' => 0,
            'linked_categories' => 0,
            'offers' => 0,
            'price_changes' => 0,
            'products_unavailable' => 0,
            'skipped_offers' => 0,
            'errors' => [],
        ];

        $now = now();
        $categories = $this->provider->getCategories();
        $summary['categories'] = count($categories);

        foreach (array_chunk($categories, 200) as $chunk) {
            ProviderCategory::upsert(
                array_map(fn (array $category) => [
                    'provider' => self::PROVIDER,
                    'provider_category_id' => $category['category_id'],
                    'name' => $category['name'],
                    'note' => $category['note'] !== '' ? $category['note'] : null,
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['provider', 'provider_category_id'],
                ['name', 'note', 'synced_at', 'updated_at']
            );
        }

        $linked = Game::query()
            ->where('provider', self::PROVIDER)
            ->whereNotNull('provider_category_id')
            ->where('provider_category_id', '!=', '')
            ->distinct()
            ->pluck('provider_category_id')
            ->all();

        $summary['linked_categories'] = count($linked);

        foreach ($linked as $index => $categoryId) {
            if ($index > 0) {
                $this->pause();
            }

            try {
                $this->syncCategory((string) $categoryId, $summary);
            } catch (ProviderException $e) {
                if ($e->isAuthError()) {
                    throw $e;
                }

                $summary['errors'][] = "{$categoryId}: ".$e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function syncCategory(string $categoryId, array &$summary): void
    {
        $data = $this->provider->getOffers($categoryId);
        $summary['skipped_offers'] += $data['skipped'];

        if ($data['offers'] === []) {
            // A glitch must never mark everything unavailable: leave this category untouched.
            $summary['errors'][] = "{$categoryId}: the provider returned no offers, so nothing was changed.";

            return;
        }

        $now = now();
        $byId = array_column($data['offers'], null, 'offer_id');

        DB::transaction(function () use ($categoryId, $data, $byId, $now, &$summary) {
            $category = ProviderCategory::query()->firstOrNew([
                'provider' => self::PROVIDER,
                'provider_category_id' => $categoryId,
            ]);
            $category->name = $data['name'];
            $category->fields = $data['fields'];
            $category->synced_at = $now;
            $category->save();

            foreach (array_chunk($data['offers'], 200) as $chunk) {
                ProviderOffer::upsert(
                    array_map(fn (array $offer) => [
                        'provider' => self::PROVIDER,
                        'provider_category_id' => $categoryId,
                        'provider_offer_id' => $offer['offer_id'],
                        'name' => $offer['name'],
                        'provider_cost' => $offer['price_usd'],
                        'currency' => 'USD',
                        'is_available' => true,
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['provider', 'provider_category_id', 'provider_offer_id'],
                    ['name', 'provider_cost', 'currency', 'is_available', 'synced_at', 'updated_at']
                );
            }

            ProviderOffer::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_category_id', $categoryId)
                ->whereNotIn('provider_offer_id', array_keys($byId))
                ->where('is_available', true)
                ->update(['is_available' => false, 'updated_at' => $now]);

            $summary['offers'] += count($data['offers']);
        });

        $this->syncProducts($categoryId, $byId, $summary);
    }

    /**
     * @param  array<string,array{offer_id:string,name:string,price_usd:string}>  $byId
     * @param  array<string,mixed>  $summary
     */
    private function syncProducts(string $categoryId, array $byId, array &$summary): void
    {
        $products = Product::query()
            ->where('provider', self::PROVIDER)
            ->whereNotNull('provider_offer_id')
            ->whereHas('game', fn ($query) => $query->where('provider_category_id', $categoryId))
            ->orderBy('id')
            ->get();

        foreach ($products as $product) {
            $offer = $byId[$product->provider_offer_id] ?? null;

            if ($offer === null) {
                // The offer vanished at the provider: stop selling it until it returns.
                if ($product->is_available) {
                    $product->forceFill(['is_available' => false])->save();
                    $summary['products_unavailable']++;
                }

                continue;
            }

            $oldCost = Money::normalize((string) $product->provider_cost);

            try {
                $updated = $this->pricing->syncCost($product, $offer['price_usd']);
            } catch (InvalidArgumentException $e) {
                $summary['errors'][] = "{$product->sku}: ".$e->getMessage();

                continue;
            }

            if (Money::cmp($oldCost, (string) $updated->provider_cost) !== 0) {
                $summary['price_changes']++;
            }

            if (! $updated->is_available) {
                $updated->forceFill(['is_available' => true])->save();
            }
        }
    }

    private function pause(): void
    {
        $ms = (int) config('fazercards.sync.delay_ms', 700);

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}