<?php

namespace App\Services\TopUp;

use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Models\Game;
use App\Models\GameField;
use App\Models\Product;
use App\Models\ProviderCategory;
use App\Models\ProviderOffer;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Pricing\PricingService;
use App\Support\NumberGenerator;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns the mirrored provider catalog into this site's player fields and products.
 * Never overwrites: existing fields and products are skipped.
 */
class ProviderImportService
{
    private const PROVIDER = 'fazercards';

    public function __construct(
        private readonly PricingService $pricing,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{created:int,skipped:int}
     *
     * @throws DomainException when the game is not linked or nothing is mirrored yet
     */
    public function importFields(Game $game): array
    {
        $this->assertLinked($game);

        $category = ProviderCategory::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_category_id', $game->provider_category_id)
            ->first();

        $fields = $category?->fields;

        if (! is_array($fields) || $fields === []) {
            throw new DomainException('No player fields are known for this category yet. Use "Refresh from provider" first.');
        }

        $existing = GameField::query()->where('game_id', $game->id)->pluck('provider_field_name')->all();
        $sort = (int) GameField::query()->where('game_id', $game->id)->max('sort_order');
        $created = 0;
        $skipped = 0;

        foreach ($fields as $definition) {
            if (in_array($definition['key'], $existing, true)) {
                $skipped++;

                continue;
            }

            $field = new GameField([
                'provider_field_name' => $definition['key'],
                'label' => $definition['label'],
                // The provider does not describe select options, so a select is imported as text.
                'type' => $definition['type'] === 'select' ? 'text' : $definition['type'],
                'required' => true,
                'validation_rules' => null,
                'options' => null,
                'sort_order' => ++$sort,
            ]);
            $field->game_id = $game->id;
            $field->save();
            $created++;
        }

        $this->audit->log('provider.fields_imported', $game, [], ['created' => $created, 'skipped' => $skipped]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array{created:int,skipped:int,failed:list<string>}
     *
     * @throws DomainException when the game is not linked or nothing is mirrored yet
     */
    public function importOffers(Game $game, MarkupType $type, string $value, bool $active, User $admin): array
    {
        $this->assertLinked($game);

        $offers = ProviderOffer::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_category_id', $game->provider_category_id)
            ->where('is_available', true)
            ->orderBy('provider_cost')
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($offers->isEmpty()) {
            throw new DomainException('No offers are mirrored for this category yet. Use "Refresh from provider" first.');
        }

        $existing = array_flip(
            Product::query()
                ->where('game_id', $game->id)
                ->where('provider', self::PROVIDER)
                ->whereNotNull('provider_offer_id')
                ->pluck('provider_offer_id')
                ->all()
        );

        $sort = (int) Product::query()->where('game_id', $game->id)->max('sort_order');
        $created = 0;
        $skipped = 0;
        $failed = [];

        foreach ($offers as $offer) {
            if (isset($existing[$offer->provider_offer_id])) {
                $skipped++;

                continue;
            }

            $position = ++$sort;

            try {
                DB::transaction(function () use ($game, $offer, $type, $value, $active, $admin, $position) {
                    $product = new Product([
                        'name' => $offer->name,
                        'status' => $active ? 'active' : 'inactive',
                        'sort_order' => $position,
                    ]);
                    $product->game_id = $game->id;
                    $product->sku = $this->sku($game);
                    $product->provider_offer_id = $offer->provider_offer_id;
                    $product->is_available = true;
                    $product->currency = (string) config('topup.currency', 'USD');
                    $product->save();

                    // Prices start at zero and are set only through PricingService (history + below-cost guard).
                    $this->pricing->update($product, (string) $offer->provider_cost, $type, $value, $admin, 'Imported from provider');
                });

                $created++;
            } catch (PriceBelowCostException|InvalidArgumentException $e) {
                $failed[] = $offer->name.': '.$e->getMessage();
            }
        }

        $this->audit->log('provider.offers_imported', $game, [], [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => count($failed),
            'markup_type' => $type->value,
            'markup_value' => $value,
        ], $admin);

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function assertLinked(Game $game): void
    {
        if (blank($game->provider_category_id)) {
            throw new DomainException('Link this game to a provider category first.');
        }
    }

    private function sku(Game $game): string
    {
        $prefix = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $game->slug), 0, 8)) ?: 'PRD';

        do {
            $sku = $prefix.'-'.NumberGenerator::random(6);
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }
}