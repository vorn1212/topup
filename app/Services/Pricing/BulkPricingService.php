<?php

namespace App\Services\Pricing;

use App\Enums\BulkOperation;
use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Exceptions\StalePreviewException;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bulk price changes: preview first, then apply exactly what was previewed.
 *
 * - preview() writes nothing and returns a signed fingerprint of the result.
 * - apply() locks every product, recomputes, refuses if the fingerprint no longer matches,
 *   then changes everything in ONE transaction with one shared batch id and ONE audit entry.
 */
class BulkPricingService
{
    public const MAX_PRODUCTS = 200;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly AuditService $audit,
    ) {
    }

    // ---------------------------------------------------------------- validation

    /**
     * @throws InvalidArgumentException
     */
    public static function assertValue(BulkOperation $operation, string $value): void
    {
        // Short-circuit order matters: Money::cmp() only runs on strings the regex accepted.
        $valid = match ($operation) {
            BulkOperation::AddAmount => preg_match('/^-?\d{1,6}(\.\d{1,4})?$/', $value) === 1
                && Money::cmp($value, '0') !== 0,
            BulkOperation::AddPercent => preg_match('/^-?\d{1,3}(\.\d{1,2})?$/', $value) === 1
                && Money::cmp($value, '0') !== 0
                && Money::cmp($value, '-100') > 0,
            BulkOperation::SetMarkupPercent => preg_match('/^\d{1,4}(\.\d{1,2})?$/', $value) === 1,
            BulkOperation::SetMarkupAmount, BulkOperation::SetFixedPrice => preg_match('/^\d{1,10}(\.\d{1,4})?$/', $value) === 1,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Enter a valid value for this action ('.$operation->hint().').');
        }
    }

    // ---------------------------------------------------------------- pure planning

    /**
     * What would happen to one product. $value must already pass assertValue().
     *
     * state: ok | unchanged | below_cost | invalid
     *
     * @return array{state:string,message:?string,old_price:string,old_cost:string,old_type:MarkupType,old_value:string,new_type:?MarkupType,new_value:?string,new_price:?string}
     */
    public static function plan(Product $product, BulkOperation $operation, string $value): array
    {
        $cost = Money::normalize((string) $product->provider_cost);
        $oldPrice = Money::normalize((string) $product->selling_price);
        $oldType = $product->markup_type;
        $oldValue = Money::normalize((string) $product->markup_value);

        $plan = [
            'state' => 'invalid',
            'message' => null,
            'old_price' => $oldPrice,
            'old_cost' => $cost,
            'old_type' => $oldType,
            'old_value' => $oldValue,
            'new_type' => null,
            'new_value' => null,
            'new_price' => null,
        ];

        try {
            [$type, $newValue] = match ($operation) {
                BulkOperation::AddAmount => PricingService::shifted($product, $value),
                BulkOperation::AddPercent => PricingService::retarget(
                    $product,
                    PricingService::percentOf($oldPrice, bcadd('100', $value, 4))
                ),
                BulkOperation::SetMarkupPercent => [MarkupType::PercentMarkup, $value],
                BulkOperation::SetMarkupAmount => [MarkupType::FixedMarkup, $value],
                BulkOperation::SetFixedPrice => [MarkupType::FixedPrice, $value],
            };

            $newValue = Money::normalize($newValue);
            $newPrice = PricingService::calculate($cost, $type, $newValue);
        } catch (InvalidArgumentException $e) {
            $plan['message'] = $e->getMessage();

            return $plan;
        }

        $plan['new_type'] = $type;
        $plan['new_value'] = $newValue;
        $plan['new_price'] = $newPrice;

        if (Money::isNegative($newPrice)) {
            $plan['message'] = 'The price would be negative.';

            return $plan;
        }

        if (Money::cmp($newPrice, $oldPrice) === 0 && $type === $oldType && Money::cmp($newValue, $oldValue) === 0) {
            $plan['state'] = 'unchanged';

            return $plan;
        }

        if (Money::cmp($newPrice, $cost) < 0) {
            $plan['state'] = 'below_cost';
            $plan['message'] = 'Below the provider cost of '.Money::display($cost).'.';

            return $plan;
        }

        $plan['state'] = 'ok';

        return $plan;
    }

    // ---------------------------------------------------------------- preview

    /**
     * @param  array<int|string>  $productIds
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>,fingerprint:string}
     */
    public function preview(array $productIds, BulkOperation $operation, string $value): array
    {
        self::assertValue($operation, $value);
        $ids = $this->cleanIds($productIds);

        $products = Product::query()
            ->with('game')
            ->whereIn('id', $ids)
            ->orderBy('game_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($products->count() !== count($ids)) {
            throw new InvalidArgumentException('Some selected products no longer exist.');
        }

        $plans = $this->plans($products, $operation, $value);

        $summary = ['total' => 0, 'ok' => 0, 'unchanged' => 0, 'below_cost' => 0, 'invalid' => 0, 'writes' => 0];
        $rows = [];

        foreach ($products as $product) {
            $plan = $plans[$product->id];
            $rows[] = ['product' => $product] + $plan;
            $summary['total']++;
            $summary[$plan['state']]++;
        }
        $summary['writes'] = $summary['ok'] + $summary['below_cost'];

        return [
            'rows' => $rows,
            'summary' => $summary,
            'fingerprint' => $this->fingerprint($operation, $value, $products, $plans),
        ];
    }

    // ---------------------------------------------------------------- apply

    /**
     * @param  array<int|string>  $productIds
     * @return array{batch_id:string,changed:int,unchanged:int}
     *
     * @throws StalePreviewException when anything changed since the preview
     * @throws PriceBelowCostException when a loss was not confirmed by someone allowed to
     * @throws InvalidArgumentException on invalid input or invalid resulting prices
     */
    public function apply(
        array $productIds,
        BulkOperation $operation,
        string $value,
        User $admin,
        string $reason,
        string $fingerprint,
        bool $confirmLoss = false,
    ): array {
        self::assertValue($operation, $value);

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('A reason is required for a bulk price change.');
        }

        $ids = $this->cleanIds($productIds);
        $batchId = (string) Str::uuid();

        return DB::transaction(function () use ($ids, $operation, $value, $admin, $reason, $fingerprint, $confirmLoss, $batchId) {
            // Fixed lock order (by id) so two bulk updates cannot deadlock each other.
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            if ($products->count() !== count($ids)) {
                throw new InvalidArgumentException('Some selected products no longer exist.');
            }

            $plans = $this->plans($products, $operation, $value);

            if (! hash_equals($this->fingerprint($operation, $value, $products, $plans), $fingerprint)) {
                throw new StalePreviewException;
            }

            $invalid = count(array_filter($plans, fn (array $p) => $p['state'] === 'invalid'));
            if ($invalid > 0) {
                throw new InvalidArgumentException("{$invalid} product(s) would get an invalid price. Nothing was changed.");
            }

            $belowCost = count(array_filter($plans, fn (array $p) => $p['state'] === 'below_cost'));
            $lossAllowed = $confirmLoss && $admin->can('pricing.allow_loss');
            if ($belowCost > 0 && ! $lossAllowed) {
                throw new PriceBelowCostException(
                    "{$belowCost} product(s) would be sold below cost. Nothing was changed. Only a super admin can confirm a loss-making price."
                );
            }

            $changed = 0;
            $unchanged = 0;
            $items = [];

            foreach ($products as $product) {
                $plan = $plans[$product->id];

                if ($plan['state'] === 'unchanged') {
                    $unchanged++;

                    continue;
                }

                $updated = $this->pricing->update(
                    $product,
                    null,
                    $plan['new_type'],
                    $plan['new_value'],
                    $admin,
                    $reason,
                    $lossAllowed,
                    $batchId,
                    false, // one audit entry for the whole batch, below
                );

                $changed++;
                $items[] = [
                    'product_id' => $product->id,
                    'old_price' => $plan['old_price'],
                    'new_price' => Money::normalize((string) $updated->selling_price),
                ];
            }

            if ($changed === 0) {
                throw new InvalidArgumentException('Nothing to change: every selected product already has these values.');
            }

            $this->audit->log('price.bulk_changed', null, [], [
                'batch_id' => $batchId,
                'operation' => $operation->value,
                'value' => $value,
                'reason' => $reason,
                'changed' => $changed,
                'unchanged' => $unchanged,
                'loss_confirmed' => $lossAllowed && $belowCost > 0,
                'products' => $items,
            ], $admin);

            return ['batch_id' => $batchId, 'changed' => $changed, 'unchanged' => $unchanged];
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  Collection<int,Product>  $products
     * @return array<int,array<string,mixed>> keyed by product id
     */
    private function plans(Collection $products, BulkOperation $operation, string $value): array
    {
        $plans = [];

        foreach ($products as $product) {
            $plans[$product->id] = self::plan($product, $operation, $value);
        }

        return $plans;
    }

    /**
     * Signed hash of exactly what the admin saw: product, old price, new price, mode and value.
     * Any change in between makes the hash differ.
     *
     * @param  Collection<int,Product>  $products
     * @param  array<int,array<string,mixed>>  $plans
     */
    private function fingerprint(BulkOperation $operation, string $value, Collection $products, array $plans): string
    {
        $items = [];

        foreach ($products->sortBy('id') as $product) {
            $plan = $plans[$product->id];
            $items[] = [
                $product->id,
                $plan['old_price'],
                $plan['old_cost'],
                $plan['old_type']->value,
                $plan['old_value'],
                $plan['state'],
                $plan['new_type']?->value,
                $plan['new_value'],
                $plan['new_price'],
            ];
        }

        return hash_hmac(
            'sha256',
            json_encode([$operation->value, Money::normalize($value), $items]),
            (string) config('app.key')
        );
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    private function cleanIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));

        if ($clean === []) {
            throw new InvalidArgumentException('Select at least one product.');
        }

        if (count($clean) > self::MAX_PRODUCTS) {
            throw new InvalidArgumentException('You can change at most '.self::MAX_PRODUCTS.' products at once.');
        }

        sort($clean);

        return $clean;
    }
}