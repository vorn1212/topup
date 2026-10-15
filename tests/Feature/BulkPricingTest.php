<?php

namespace Tests\Feature;

use App\Enums\BulkOperation;
use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Exceptions\StalePreviewException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use App\Services\Pricing\BulkPricingService;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class BulkPricingTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private BulkPricingService $bulk;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bulk = app(BulkPricingService::class);
        $this->admin = User::factory()->admin()->create();
    }

    /** @return array{0:Product,1:Product,2:Product} selling 1.00 / 2.00 / 3.00 (costs 0.80 / 1.60 / 2.40) */
    private function trio(): array
    {
        $game = $this->makeGame();

        return [
            $this->makeProduct($game, '0.80', MarkupType::FixedMarkup, '0.20'),
            $this->makeProduct($game, '1.60', MarkupType::FixedMarkup, '0.40'),
            $this->makeProduct($game, '2.40', MarkupType::FixedMarkup, '0.60'),
        ];
    }

    private function ids(array $products): array
    {
        return array_map(fn (Product $p) => $p->id, $products);
    }

    private function price(Product $product): string
    {
        return Product::findOrFail($product->id)->selling_price;
    }

    // ---------------------------------------------------------------- planning

    public function test_plan_for_each_operation(): void
    {
        $fixedMarkup = $this->makeProduct(null, '0.80', MarkupType::FixedMarkup, '0.20'); // 1.00
        $percent = $this->makeProduct(null, '0.80', MarkupType::PercentMarkup, '25');      // 1.00

        $plan = BulkPricingService::plan($fixedMarkup, BulkOperation::AddAmount, '0.10');
        $this->assertSame('ok', $plan['state']);
        $this->assertSame('1.1000', $plan['new_price']);
        $this->assertSame(MarkupType::FixedMarkup, $plan['new_type']);
        $this->assertSame('0.3000', $plan['new_value']);

        $plan = BulkPricingService::plan($percent, BulkOperation::AddAmount, '0.10');
        $this->assertSame(MarkupType::FixedPrice, $plan['new_type']);
        $this->assertSame('1.1000', $plan['new_price']);

        $plan = BulkPricingService::plan($fixedMarkup, BulkOperation::AddPercent, '5');
        $this->assertSame('1.0500', $plan['new_price']);
        $this->assertSame(MarkupType::FixedMarkup, $plan['new_type']);
        $this->assertSame('0.2500', $plan['new_value']);

        $plan = BulkPricingService::plan($percent, BulkOperation::AddPercent, '-5');
        $this->assertSame('0.9500', $plan['new_price']);
        $this->assertSame(MarkupType::FixedPrice, $plan['new_type']);
        $this->assertSame('ok', $plan['state']);

        $plan = BulkPricingService::plan($fixedMarkup, BulkOperation::SetMarkupPercent, '20');
        $this->assertSame(MarkupType::PercentMarkup, $plan['new_type']);
        $this->assertSame('0.9600', $plan['new_price']);

        $plan = BulkPricingService::plan($fixedMarkup, BulkOperation::SetMarkupAmount, '0.50');
        $this->assertSame(MarkupType::FixedMarkup, $plan['new_type']);
        $this->assertSame('1.3000', $plan['new_price']);

        $plan = BulkPricingService::plan($fixedMarkup, BulkOperation::SetFixedPrice, '1.50');
        $this->assertSame(MarkupType::FixedPrice, $plan['new_type']);
        $this->assertSame('1.5000', $plan['new_price']);
    }

    public function test_plan_states(): void
    {
        $product = $this->makeProduct(null, '0.80', MarkupType::FixedMarkup, '0.20'); // 1.00

        $this->assertSame('below_cost', BulkPricingService::plan($product, BulkOperation::SetFixedPrice, '0.70')['state']);
        $this->assertSame('invalid', BulkPricingService::plan($product, BulkOperation::AddAmount, '-5')['state']);
        $this->assertSame('unchanged', BulkPricingService::plan($product, BulkOperation::SetMarkupAmount, '0.20')['state']);
        $this->assertSame('ok', BulkPricingService::plan($product, BulkOperation::SetMarkupAmount, '0.30')['state']);
    }

    public function test_values_are_validated_per_operation(): void
    {
        $cases = [
            [BulkOperation::AddAmount, ['abc', '0', '1e2', '', '0.12345']],
            [BulkOperation::AddPercent, ['abc', '0', '-100', '-150', '1000.5']],
            [BulkOperation::SetMarkupPercent, ['-5', 'abc', '10000']],
            [BulkOperation::SetMarkupAmount, ['-0.10', 'abc']],
            [BulkOperation::SetFixedPrice, ['-1', 'abc', '1.23456']],
        ];

        foreach ($cases as [$operation, $values]) {
            foreach ($values as $value) {
                try {
                    BulkPricingService::assertValue($operation, $value);
                    $this->fail("{$operation->value} should reject [{$value}]");
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        }

        BulkPricingService::assertValue(BulkOperation::AddAmount, '-0.10');
        BulkPricingService::assertValue(BulkOperation::AddPercent, '5');
        BulkPricingService::assertValue(BulkOperation::SetMarkupPercent, '20');
        BulkPricingService::assertValue(BulkOperation::SetFixedPrice, '1.00');
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------- preview

    public function test_preview_changes_nothing(): void
    {
        [$a, $b, $c] = $this->trio();
        $histories = ProductPriceHistory::count();

        $preview = $this->bulk->preview($this->ids([$a, $b, $c]), BulkOperation::AddAmount, '0.10');

        $this->assertSame(3, $preview['summary']['total']);
        $this->assertSame(3, $preview['summary']['writes']);
        $this->assertSame('1.1000', $preview['rows'][0]['new_price']);
        $this->assertSame(64, strlen($preview['fingerprint']));

        $this->assertSame($histories, ProductPriceHistory::count());
        $this->assertSame('1.0000', $this->price($a));
    }

    public function test_preview_rejects_empty_unknown_and_oversized_selections(): void
    {
        foreach ([[], [999999], range(1, BulkPricingService::MAX_PRODUCTS + 1)] as $ids) {
            try {
                $this->bulk->preview($ids, BulkOperation::AddAmount, '0.10');
                $this->fail('Selection should be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    // ---------------------------------------------------------------- apply

    public function test_apply_changes_everything_in_one_batch(): void
    {
        [$a, $b, $c] = $this->trio();
        $ids = $this->ids([$a, $b, $c]);
        $singleAudits = AuditLog::where('action', 'price.changed')->count();

        $preview = $this->bulk->preview($ids, BulkOperation::AddAmount, '0.10');
        $result = $this->bulk->apply($ids, BulkOperation::AddAmount, '0.10', $this->admin, 'Supplier price increase', $preview['fingerprint']);

        $this->assertSame(3, $result['changed']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame('1.1000', $this->price($a));
        $this->assertSame('2.1000', $this->price($b));
        $this->assertSame('3.1000', $this->price($c));

        // Every history row shares the batch id and carries reason and admin.
        $this->assertSame(3, ProductPriceHistory::where('batch_id', $result['batch_id'])->count());
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $a->id,
            'batch_id' => $result['batch_id'],
            'old_price' => '1.0000',
            'new_price' => '1.1000',
            'reason' => 'Supplier price increase',
            'changed_by' => $this->admin->id,
        ]);

        // ONE audit entry for the batch, none per product.
        $this->assertSame(1, AuditLog::where('action', 'price.bulk_changed')->count());
        $this->assertSame($singleAudits, AuditLog::where('action', 'price.changed')->count());
        $this->assertSame($result['batch_id'], AuditLog::where('action', 'price.bulk_changed')->firstOrFail()->new_values['batch_id']);
    }

    public function test_apply_requires_a_reason(): void
    {
        [$a] = $this->trio();
        $preview = $this->bulk->preview([$a->id], BulkOperation::AddAmount, '0.10');

        $this->expectException(InvalidArgumentException::class);
        $this->bulk->apply([$a->id], BulkOperation::AddAmount, '0.10', $this->admin, '  ', $preview['fingerprint']);
    }

    public function test_apply_is_refused_when_prices_changed_after_the_preview(): void
    {
        [$a, $b, $c] = $this->trio();
        $ids = $this->ids([$a, $b, $c]);

        $preview = $this->bulk->preview($ids, BulkOperation::AddAmount, '0.10');

        // Someone else changes a price in the meantime.
        app(PricingService::class)->update($a, null, MarkupType::FixedPrice, '5.00', $this->admin, 'Edited elsewhere');

        try {
            $this->bulk->apply($ids, BulkOperation::AddAmount, '0.10', $this->admin, 'Bulk change', $preview['fingerprint']);
            $this->fail('A stale preview must be refused.');
        } catch (StalePreviewException) {
            $this->assertSame('5.0000', $this->price($a));
            $this->assertSame('2.0000', $this->price($b));
            $this->assertSame('3.0000', $this->price($c));
        }
    }

    public function test_a_forged_fingerprint_is_refused(): void
    {
        [$a] = $this->trio();

        $this->expectException(StalePreviewException::class);
        $this->bulk->apply([$a->id], BulkOperation::AddAmount, '0.10', $this->admin, 'Bulk change', str_repeat('a', 64));
    }

    public function test_changing_the_value_after_the_preview_is_refused(): void
    {
        [$a] = $this->trio();
        $preview = $this->bulk->preview([$a->id], BulkOperation::AddAmount, '0.10');

        $this->expectException(StalePreviewException::class);
        $this->bulk->apply([$a->id], BulkOperation::AddAmount, '5.00', $this->admin, 'Sneaky', $preview['fingerprint']);
    }

    public function test_below_cost_blocks_the_whole_batch_for_an_admin(): void
    {
        [$a, $b, $c] = $this->trio();
        $ids = $this->ids([$a, $b, $c]);

        $preview = $this->bulk->preview($ids, BulkOperation::SetFixedPrice, '1.00');
        $this->assertSame(2, $preview['summary']['below_cost']); // b and c cost more than 1.00

        try {
            $this->bulk->apply($ids, BulkOperation::SetFixedPrice, '1.00', $this->admin, 'Promo', $preview['fingerprint'], true);
            $this->fail('An admin must not be able to sell below cost.');
        } catch (PriceBelowCostException) {
            $this->assertSame('1.0000', $this->price($a));
            $this->assertSame('2.0000', $this->price($b));
            $this->assertSame('3.0000', $this->price($c));
            $this->assertSame(MarkupType::FixedMarkup, Product::find($a->id)->markup_type); // rolled back completely
        }
    }

    public function test_super_admin_can_confirm_a_loss_but_only_explicitly(): void
    {
        [$a, $b, $c] = $this->trio();
        $ids = $this->ids([$a, $b, $c]);
        $super = User::factory()->superAdmin()->create();

        $preview = $this->bulk->preview($ids, BulkOperation::SetFixedPrice, '1.00');

        try {
            $this->bulk->apply($ids, BulkOperation::SetFixedPrice, '1.00', $super, 'Clearance', $preview['fingerprint'], false);
            $this->fail('A loss needs explicit confirmation.');
        } catch (PriceBelowCostException) {
            $this->assertSame('2.0000', $this->price($b));
        }

        $result = $this->bulk->apply($ids, BulkOperation::SetFixedPrice, '1.00', $super, 'Clearance', $preview['fingerprint'], true);

        $this->assertSame(3, $result['changed']);
        $this->assertSame('1.0000', $this->price($b));
        $this->assertTrue(Product::find($b->id)->isBelowCost());
        $this->assertTrue(AuditLog::where('action', 'price.bulk_changed')->firstOrFail()->new_values['loss_confirmed']);
    }

    public function test_an_invalid_row_blocks_the_whole_batch(): void
    {
        [$a, $b, $c] = $this->trio();
        $ids = $this->ids([$a, $b, $c]);
        $super = User::factory()->superAdmin()->create();

        $preview = $this->bulk->preview($ids, BulkOperation::AddAmount, '-2.50'); // a and b would go negative
        $this->assertGreaterThan(0, $preview['summary']['invalid']);

        try {
            $this->bulk->apply($ids, BulkOperation::AddAmount, '-2.50', $super, 'Bad idea', $preview['fingerprint'], true);
            $this->fail('Invalid prices must block the batch even for a super admin.');
        } catch (InvalidArgumentException) {
            $this->assertSame('1.0000', $this->price($a));
            $this->assertSame('3.0000', $this->price($c));
        }
    }

    public function test_nothing_to_change_is_reported(): void
    {
        [$a] = $this->trio();
        $preview = $this->bulk->preview([$a->id], BulkOperation::SetMarkupAmount, '0.20'); // already 0.20

        $this->assertSame(0, $preview['summary']['writes']);

        $this->expectException(InvalidArgumentException::class);
        $this->bulk->apply([$a->id], BulkOperation::SetMarkupAmount, '0.20', $this->admin, 'No-op', $preview['fingerprint']);
    }

    // ---------------------------------------------------------------- HTTP

    public function test_only_pricing_managers_can_use_the_bulk_endpoints(): void
    {
        [$a] = $this->trio();
        $payload = ['product_ids' => [$a->id], 'operation' => 'add_amount', 'value' => '0.10'];

        foreach ([User::factory()->create(), User::factory()->support()->create()] as $user) {
            $this->actingAs($user)->post('/admin/products/bulk-price/preview', $payload)->assertForbidden();
            $this->actingAs($user)->post('/admin/products/bulk-price/apply', $payload + ['reason' => 'x', 'confirm' => '1', 'fingerprint' => str_repeat('a', 64)])->assertForbidden();
        }

        $this->assertSame('1.0000', $this->price($a));
    }

    public function test_full_flow_over_http(): void
    {
        [$a, $b, $c] = $this->trio();
        $ids = $this->ids([$a, $b, $c]);
        $payload = ['product_ids' => $ids, 'operation' => 'add_percent', 'value' => '10'];

        $this->actingAs($this->admin);

        $this->post('/admin/products/bulk-price/preview', $payload)
            ->assertOk()
            ->assertSee('$1.10')
            ->assertSee('$2.20')
            ->assertSee('$3.30');
        $this->assertSame('1.0000', $this->price($a));

        $fingerprint = $this->bulk->preview($ids, BulkOperation::AddPercent, '10')['fingerprint'];

        $this->post('/admin/products/bulk-price/apply', $payload + [
            'reason' => 'Supplier increase', 'confirm' => '1', 'fingerprint' => $fingerprint,
        ])->assertRedirect('/admin/products')->assertSessionHas('status');

        $this->assertSame('1.1000', $this->price($a));
        $this->assertSame('2.2000', $this->price($b));
        $this->assertSame('3.3000', $this->price($c));
    }

    public function test_http_validation(): void
    {
        [$a] = $this->trio();
        $this->actingAs($this->admin);

        $this->post('/admin/products/bulk-price/preview', ['operation' => 'add_amount', 'value' => '0.10'])
            ->assertSessionHasErrors('product_ids');

        $this->post('/admin/products/bulk-price/preview', ['product_ids' => [$a->id], 'operation' => 'add_amount', 'value' => 'abc'])
            ->assertSessionHasErrors('value');

        $this->post('/admin/products/bulk-price/preview', ['product_ids' => [999999], 'operation' => 'add_amount', 'value' => '0.10'])
            ->assertSessionHasErrors('product_ids.0');

        $this->post('/admin/products/bulk-price/preview', ['product_ids' => [$a->id], 'operation' => 'explode', 'value' => '1'])
            ->assertSessionHasErrors('operation');

        $base = ['product_ids' => [$a->id], 'operation' => 'add_amount', 'value' => '0.10', 'fingerprint' => str_repeat('a', 64)];

        $this->post('/admin/products/bulk-price/apply', $base + ['confirm' => '1'])->assertSessionHasErrors('reason');
        $this->post('/admin/products/bulk-price/apply', $base + ['reason' => 'Reason here'])->assertSessionHasErrors('confirm');

        $this->assertSame('1.0000', $this->price($a));
    }

    public function test_stale_preview_over_http_changes_nothing(): void
    {
        [$a] = $this->trio();

        $this->actingAs($this->admin)->post('/admin/products/bulk-price/apply', [
            'product_ids' => [$a->id], 'operation' => 'add_amount', 'value' => '0.10',
            'reason' => 'Reason here', 'confirm' => '1', 'fingerprint' => str_repeat('b', 64),
        ])->assertRedirect('/admin/products')->assertSessionHas('error');

        $this->assertSame('1.0000', $this->price($a));
    }
}