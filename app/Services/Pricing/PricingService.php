<?php

namespace App\Services\Pricing;

use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The ONLY class allowed to compute or change a product's selling price.
 * Every change: row lock, below-cost guard, history row, audit log. Money is bcmath strings only.
 */
class PricingService
{
    public const DECIMAL = '/^\d{1,14}(\.\d{1,4})?$/';

    public const SIGNED_DECIMAL = '/^-?\d{1,14}(\.\d{1,4})?$/';

    public function __construct(private readonly AuditService $audit)
    {
    }

    // ---------------------------------------------------------------- pure calculation

        /**
     * selling = f(cost, mode, value), rounded half-up to WHOLE CENTS (stored with 4 decimals, e.g. 1.0600).
     *   fixed_price    : value
     *   fixed_markup   : cost + value
     *   percent_markup : cost * (1 + value / 100)
     */
    public static function calculate(string $cost, MarkupType $type, string $value): string
    {
        self::assertCost($cost);
        self::assertValue($type, $value);

        $price = match ($type) {
            MarkupType::FixedPrice => Money::normalize($value),
            MarkupType::FixedMarkup => Money::add($cost, $value),
            MarkupType::PercentMarkup => self::percentOf($cost, bcadd('100', $value, 4)),
        };

        // Customers pay whole cents, so what they see is exactly what is charged.
        return Money::normalize(Money::round($price, 2));
    }

    /** amount * factor / 100, rounded half-up to 4 decimals. (factor 125 means +25%) */
    public static function percentOf(string $amount, string $factor): string
    {
        return Money::round(bcdiv(bcmul($amount, $factor, 8), '100', 8), 4);
    }

    /**
     * New (mode, value) after moving the price by $delta (positive or negative).
     * fixed_markup keeps its mode; fixed_price and percent_markup become fixed_price.
     *
     * @return array{0:MarkupType,1:string}
     */
    public static function shifted(Product $product, string $delta): array
    {
        if (! preg_match(self::SIGNED_DECIMAL, $delta) || Money::cmp($delta, '0') === 0) {
            throw new InvalidArgumentException('Invalid price adjustment.');
        }

        if ($product->markup_type === MarkupType::FixedMarkup) {
            return [MarkupType::FixedMarkup, Money::add((string) $product->markup_value, $delta)];
        }

        return [MarkupType::FixedPrice, Money::add((string) $product->selling_price, $delta)];
    }

        /**
     * New (mode, value) that produces $newSelling.
     * fixed_markup keeps its mode (markup = new price - cost); other modes become fixed_price.
     *
     * @return array{0:MarkupType,1:string}
     */
    public static function retarget(Product $product, string $newSelling): array
    {
        if ($product->markup_type === MarkupType::FixedMarkup) {
            return [MarkupType::FixedMarkup, Money::sub($newSelling, (string) $product->provider_cost)];
        }

        return [MarkupType::FixedPrice, Money::normalize($newSelling)];
    }

    // ---------------------------------------------------------------- changes

    /**
     * Set cost (optional) and pricing mode/value, recompute the selling price, record history.
     * Returns the product unchanged (and writes nothing) when nothing differs.
     *
     * @throws PriceBelowCostException when selling < cost without permission and confirmation
     * @throws InvalidArgumentException on invalid input or a negative selling price
     */
    public function update(
        Product $product,
        ?string $cost,
        MarkupType $type,
        string $value,
        ?User $admin,
        string $reason,
        bool $confirmLoss = false,
        ?string $batchId = null,
        bool $audit = true,
    ): Product {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('A reason is required for a price change.');
        }

        if ($cost !== null) {
            self::assertCost($cost);
        }
        self::assertValue($type, $value);

        return DB::transaction(function () use ($product, $cost, $type, $value, $admin, $reason, $confirmLoss, $batchId, $audit) {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $oldCost = Money::normalize((string) $locked->provider_cost);
            $oldSelling = Money::normalize((string) $locked->selling_price);
            $oldType = $locked->markup_type;
            $oldValue = Money::normalize((string) $locked->markup_value);

            $newCost = $cost === null ? $oldCost : Money::normalize($cost);
            $newValue = Money::normalize($value);
            $newSelling = self::calculate($newCost, $type, $newValue);

            if (Money::isNegative($newSelling)) {
                throw new InvalidArgumentException('Selling price cannot be negative.');
            }

            $this->assertLossAllowed($admin, $confirmLoss, $newSelling, $newCost);

            if (Money::cmp($oldCost, $newCost) === 0
                && Money::cmp($oldSelling, $newSelling) === 0
                && $oldType === $type
                && Money::cmp($oldValue, $newValue) === 0) {
                return $locked;
            }

            $locked->forceFill([
                'provider_cost' => $newCost,
                'markup_type' => $type,
                'markup_value' => $newValue,
                'selling_price' => $newSelling,
            ])->save();

            $history = new ProductPriceHistory;
            $history->forceFill([
                'product_id' => $locked->id,
                'old_price' => $oldSelling,
                'new_price' => $newSelling,
                'old_markup' => Money::sub($oldSelling, $oldCost),
                'new_markup' => Money::sub($newSelling, $newCost),
                'old_cost' => $oldCost,
                'new_cost' => $newCost,
                'old_markup_type' => $oldType->value,
                'new_markup_type' => $type->value,
                'old_markup_value' => $oldValue,
                'new_markup_value' => $newValue,
                'changed_by' => $admin?->getKey(),
                'reason' => Str::limit($reason, 255, ''),
                'batch_id' => $batchId,
            ])->save();

            if ($audit) {
                $this->audit->log('price.changed', $locked, [
                    'provider_cost' => $oldCost,
                    'markup_type' => $oldType->value,
                    'markup_value' => $oldValue,
                    'selling_price' => $oldSelling,
                ], [
                    'provider_cost' => $newCost,
                    'markup_type' => $type->value,
                    'markup_value' => $newValue,
                    'selling_price' => $newSelling,
                    'reason' => $reason,
                ], $admin);
            }

            return $locked;
        });
    }

    /**
     * Quick +/- adjustment. The base price is read under the row lock, so two admins
     * clicking at once cannot overwrite each other.
     */
    public function adjust(Product $product, string $delta, ?User $admin, ?string $reason = null, bool $confirmLoss = false): Product
    {
        return DB::transaction(function () use ($product, $delta, $admin, $reason, $confirmLoss) {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            [$type, $value] = self::shifted($locked, $delta);

            $reason = trim((string) $reason) !== ''
                ? (string) $reason
                : 'Quick adjust '.(str_starts_with($delta, '-') ? '' : '+').$delta;

            return $this->update($locked, null, $type, $value, $admin, $reason, $confirmLoss);
        });
    }


        /**
     * Apply a new provider cost reported by the supplier.
     *
     * Never throws for a loss: the cost is a fact. Markup modes follow the cost; a fixed price
     * stays as it is and may end up below cost (flagged in admin, and refused at order time).
     */
    public function syncCost(Product $product, string $cost, string $reason = 'Provider cost sync'): Product
    {
        self::assertCost($cost);

        return DB::transaction(function () use ($product, $cost, $reason) {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $oldCost = Money::normalize((string) $locked->provider_cost);
            $newCost = Money::normalize($cost);

            if (Money::cmp($oldCost, $newCost) === 0) {
                return $locked;
            }

            $oldSelling = Money::normalize((string) $locked->selling_price);
            $type = $locked->markup_type;
            $value = Money::normalize((string) $locked->markup_value);
            $newSelling = self::calculate($newCost, $type, $value);

            if (Money::isNegative($newSelling)) {
                throw new InvalidArgumentException('The new provider cost would make the selling price negative.');
            }

            $locked->forceFill([
                'provider_cost' => $newCost,
                'selling_price' => $newSelling,
            ])->save();

            $history = new ProductPriceHistory;
            $history->forceFill([
                'product_id' => $locked->id,
                'old_price' => $oldSelling,
                'new_price' => $newSelling,
                'old_markup' => Money::sub($oldSelling, $oldCost),
                'new_markup' => Money::sub($newSelling, $newCost),
                'old_cost' => $oldCost,
                'new_cost' => $newCost,
                'old_markup_type' => $type->value,
                'new_markup_type' => $type->value,
                'old_markup_value' => $value,
                'new_markup_value' => $value,
                'changed_by' => null,
                'reason' => Str::limit($reason, 255, ''),
                'batch_id' => null,
            ])->save();

            $this->audit->log('price.cost_synced', $locked, [
                'provider_cost' => $oldCost,
                'selling_price' => $oldSelling,
            ], [
                'provider_cost' => $newCost,
                'selling_price' => $newSelling,
            ]);

            return $locked;
        });
    }

    // ---------------------------------------------------------------- guards

    private function assertLossAllowed(?User $admin, bool $confirmLoss, string $selling, string $cost): void
    {
        if (Money::cmp($selling, $cost) >= 0) {
            return;
        }

        if ($confirmLoss && $admin && $admin->can('pricing.allow_loss')) {
            return;
        }

        throw new PriceBelowCostException(sprintf(
            'Selling price %s is below the provider cost %s. Only a super admin can confirm a loss-making price.',
            Money::display($selling),
            Money::display($cost)
        ));
    }

    private static function assertCost(string $cost): void
    {
        if (! preg_match(self::DECIMAL, $cost)) {
            throw new InvalidArgumentException('Provider cost must be a positive decimal with at most 4 decimal places.');
        }
    }

    private static function assertValue(MarkupType $type, string $value): void
    {
        if ($type === MarkupType::FixedPrice) {
            if (preg_match(self::SIGNED_DECIMAL, $value) && Money::isNegative($value)) {
                throw new InvalidArgumentException('Selling price cannot be negative.');
            }

            if (! preg_match(self::DECIMAL, $value)) {
                throw new InvalidArgumentException('Invalid selling price.');
            }

            return;
        }

        if (! preg_match(self::SIGNED_DECIMAL, $value)) {
            throw new InvalidArgumentException('Invalid markup value.');
        }
    }
}