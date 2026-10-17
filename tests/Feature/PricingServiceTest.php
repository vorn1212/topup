<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\Pricing\PricingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(PricingService::class);
    }

    public function test_the_three_pricing_modes(): void
    {
        $this->assertSame('1.0000', PricingService::calculate('0.80', MarkupType::FixedPrice, '1.00'));
        $this->assertSame('1.0000', PricingService::calculate('0.80', MarkupType::FixedMarkup, '0.20'));
        $this->assertSame('1.0000', PricingService::calculate('0.80', MarkupType::PercentMarkup, '25'));
        $this->assertSame('0.3700', PricingService::calculate('0.3333', MarkupType::PercentMarkup, '10')); // rounds half up to cents
        $this->assertSame('0.7200', PricingService::calculate('0.80', MarkupType::PercentMarkup, '-10'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        foreach ([
            ['0.80', MarkupType::FixedPrice, '-1'],
            ['0.80', MarkupType::FixedPrice, 'abc'],
            ['0.80', MarkupType::FixedMarkup, '1e3'],
            ['-1', MarkupType::FixedMarkup, '1'],
            ['0.80', MarkupType::FixedMarkup, '0.12345'],
        ] as [$cost, $type, $value]) {
            try {
                PricingService::calculate($cost, $type, $value);
                $this->fail("Should reject {$cost} / {$type->value} / {$value}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_setup_price_is_calculated_and_recorded(): void
    {
        $product = $this->makeProduct();

        $this->assertSame('1.0000', $product->selling_price);
        $this->assertSame('0.2000', $product->profit());
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id, 'old_price' => '0.0000', 'new_price' => '1.0000', 'reason' => 'Test setup',
        ]);
    }

    public function test_update_records_history_and_audit(): void
    {
        $product = $this->makeProduct();
        $admin = User::factory()->admin()->create();

        $updated = $this->pricing->update($product, null, MarkupType::FixedPrice, '1.20', $admin, 'FazerCards cost increase');

        $this->assertSame('1.2000', $updated->selling_price);
        $this->assertSame(MarkupType::FixedPrice, $updated->markup_type);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id,
            'old_price' => '1.0000',
            'new_price' => '1.2000',
            'old_markup' => '0.2000',
            'new_markup' => '0.4000',
            'changed_by' => $admin->id,
            'reason' => 'FazerCards cost increase',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'price.changed', 'subject_id' => $product->id, 'actor_id' => $admin->id]);
    }

    public function test_a_reason_is_required(): void
    {
        $product = $this->makeProduct();

        $this->expectException(InvalidArgumentException::class);
        $this->pricing->update($product, null, MarkupType::FixedPrice, '2.00', User::factory()->admin()->create(), '  ');
    }

    public function test_unchanged_price_writes_no_history(): void
    {
        $product = $this->makeProduct();
        $before = ProductPriceHistory::count();

        $this->pricing->update($product, '0.80', MarkupType::FixedMarkup, '0.20', User::factory()->admin()->create(), 'No change');

        $this->assertSame($before, ProductPriceHistory::count());
    }

    public function test_percent_mode_follows_a_cost_change(): void
    {
        $product = $this->makeProduct(null, '0.80', MarkupType::PercentMarkup, '25');
        $this->assertSame('1.0000', $product->selling_price);

        $updated = $this->pricing->update($product, '1.00', MarkupType::PercentMarkup, '25', User::factory()->admin()->create(), 'Cost went up');

        $this->assertSame('1.2500', $updated->selling_price);
    }

    public function test_below_cost_is_blocked_unless_a_super_admin_confirms(): void
    {
        $product = $this->makeProduct(); // cost 0.80, selling 1.00
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();

        foreach ([[$admin, true], [$super, false]] as [$user, $confirm]) {
            try {
                $this->pricing->update($product, null, MarkupType::FixedPrice, '0.70', $user, 'Promo price', $confirm);
                $this->fail('Below-cost price must be blocked.');
            } catch (PriceBelowCostException) {
                $this->assertSame('1.0000', Product::find($product->id)->selling_price);
            }
        }

        $updated = $this->pricing->update($product, null, MarkupType::FixedPrice, '0.70', $super, 'Promo price', true);
        $this->assertSame('0.7000', $updated->selling_price);
        $this->assertTrue($updated->isBelowCost());
    }

    public function test_quick_adjust_keeps_fixed_markup_mode(): void
    {
        $product = $this->makeProduct(); // 0.80 + 0.20
        $admin = User::factory()->admin()->create();

        $updated = $this->pricing->adjust($product, '0.10', $admin);

        $this->assertSame(MarkupType::FixedMarkup, $updated->markup_type);
        $this->assertSame('0.3000', $updated->markup_value);
        $this->assertSame('1.1000', $updated->selling_price);
        $this->assertDatabaseHas('product_price_histories', ['product_id' => $product->id, 'reason' => 'Quick adjust +0.10']);

        $down = $this->pricing->adjust($updated, '-0.10', $admin);
        $this->assertSame('1.0000', $down->selling_price);
    }

    public function test_quick_adjust_converts_percent_and_fixed_price_to_fixed_price(): void
    {
        $admin = User::factory()->admin()->create();

        $percent = $this->makeProduct(null, '0.80', MarkupType::PercentMarkup, '25');
        $updated = $this->pricing->adjust($percent, '0.10', $admin);
        $this->assertSame(MarkupType::FixedPrice, $updated->markup_type);
        $this->assertSame('1.1000', $updated->selling_price);

        $fixed = $this->makeProduct(null, '0.80', MarkupType::FixedPrice, '2.00');
        $updated = $this->pricing->adjust($fixed, '-0.50', $admin);
        $this->assertSame('1.5000', $updated->selling_price);
    }

    public function test_adjust_cannot_make_a_negative_price(): void
    {
        $product = $this->makeProduct(null, '0.00', MarkupType::FixedPrice, '1.00');

        $this->expectException(InvalidArgumentException::class);
        $this->pricing->adjust($product, '-1.50', User::factory()->superAdmin()->create());
    }

    public function test_database_refuses_a_negative_selling_price(): void
    {
        $product = $this->makeProduct();

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['selling_price' => '-1.0000']);
    }

    public function test_price_history_is_immutable(): void
    {
        $this->makeProduct();
        $history = ProductPriceHistory::firstOrFail();

        $history->reason = 'tampered';

        $this->expectException(LogicException::class);
        $history->save();
    }
}