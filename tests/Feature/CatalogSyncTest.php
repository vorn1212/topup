<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Exceptions\ProviderException;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProviderCategory;
use App\Services\TopUp\CatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private const K60 = 'pubgm_60';

    private const K325 = 'pubgm_325';

    private $game;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hariki.api_key' => 'hrk_live_test-key-123',
            'hariki.base_url' => 'https://api.harikitopup.com',
            'hariki.retry_sleep_ms' => 0,
            'hariki.sync.delay_ms' => 0,
            'hariki.fee_per_order' => '0',
        ]);

        $this->game = $this->makeGame(['slug' => 'pubg-mobile', 'provider_category_id' => 'pubgm']);
    }

    private function productsBody(?array $products = null): array
    {
        $products ??= [
            ['game_code' => 'pubgm', 'product_code' => self::K60, 'name' => '60 UC', 'price' => 0.9, 'currency' => 'USD', 'status' => 'active'],
            ['game_code' => 'pubgm', 'product_code' => self::K325, 'name' => '325 UC', 'price' => 4.5, 'currency' => 'USD', 'status' => 'active'],
        ];

        return ['success' => true, 'data' => ['products' => array_merge($products, [
            ['game_code' => 'other', 'product_code' => 'other_10', 'name' => '10 Gems', 'price' => 0.1, 'currency' => 'USD', 'status' => 'active'],
        ])]];
    }

    private function fakeCatalog(?array $products = null): void
    {
        // Start from a clean fake so a second call really replaces the first one.
        Http::swap(new HttpFactory);

        Http::fake(['*/api/v1/products*' => Http::response($this->productsBody($products))]);
    }

    private function sync(): array
    {
        return app(CatalogSyncService::class)->sync();
    }

    private function linkedProduct(string $offerId, string $cost, MarkupType $type, string $value, array $attributes = []): Product
    {
        return $this->makeProduct($this->game, $cost, $type, $value, ['provider_offer_id' => $offerId] + $attributes);
    }

    public function test_sync_mirrors_categories_offers_and_fields(): void
    {
        $this->fakeCatalog();

        $summary = $this->sync();

        $this->assertSame(2, $summary['categories']);
        $this->assertSame(1, $summary['linked_categories']);
        $this->assertSame(2, $summary['offers']);
        $this->assertSame([], $summary['errors']);

        $this->assertDatabaseHas('provider_categories', ['provider_category_id' => 'pubgm', 'name' => 'PUBGM']);
        $this->assertDatabaseHas('provider_categories', ['provider_category_id' => 'other']);
        $this->assertDatabaseHas('provider_offers', [
            'provider_category_id' => 'pubgm', 'provider_offer_id' => self::K60, 'provider_cost' => '0.9000', 'is_available' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.synced']);

        $category = ProviderCategory::where('provider_category_id', 'pubgm')->firstOrFail();
        $this->assertSame('player_id', $category->fields[0]['key']);
    }

    public function test_only_linked_categories_are_fetched_in_detail(): void
    {
        $this->fakeCatalog();

        $this->sync();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'game=other'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'game=pubgm'));
    }

    public function test_markup_products_follow_the_new_cost_and_history_is_written(): void
    {
        $fixed = $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');      // 1.00
        $percent = $this->linkedProduct(self::K325, '4.00', MarkupType::PercentMarkup, '25');   // 5.00
        $this->fakeCatalog();

        $summary = $this->sync();

        $this->assertSame(2, $summary['price_changes']);

        $fixed = $fixed->fresh();
        $this->assertSame('0.9000', $fixed->provider_cost);
        $this->assertSame('1.1000', $fixed->selling_price);

        $percent = $percent->fresh();
        $this->assertSame('4.5000', $percent->provider_cost);
        $this->assertSame('5.6300', $percent->selling_price);

        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $fixed->id,
            'old_cost' => '0.8000',
            'new_cost' => '0.9000',
            'old_price' => '1.0000',
            'new_price' => '1.1000',
            'changed_by' => null,
            'reason' => 'Provider cost sync',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'price.cost_synced', 'subject_id' => $fixed->id]);
    }

    public function test_a_fixed_price_stays_but_is_flagged_when_the_cost_rises_above_it(): void
    {
        $product = $this->linkedProduct(self::K325, '0.80', MarkupType::FixedPrice, '1.00');
        $this->fakeCatalog();

        $this->sync();

        $product = $product->fresh();
        $this->assertSame('1.0000', $product->selling_price);
        $this->assertSame('4.5000', $product->provider_cost);
        $this->assertTrue($product->isBelowCost());
    }

    public function test_a_second_identical_sync_changes_nothing(): void
    {
        $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');
        $this->fakeCatalog();

        $first = $this->sync();
        $histories = ProductPriceHistory::count();
        $second = $this->sync();

        $this->assertSame(1, $first['price_changes']);
        $this->assertSame(0, $second['price_changes']);
        $this->assertSame($histories, ProductPriceHistory::count());
    }

    public function test_a_product_whose_offer_disappeared_becomes_unavailable_and_returns_later(): void
    {
        $gone = $this->linkedProduct('pubgm_gone', '0.80', MarkupType::FixedMarkup, '0.20');
        $hidden = $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20', ['is_available' => false]);
        $this->fakeCatalog();

        $summary = $this->sync();

        $this->assertSame(1, $summary['products_unavailable']);
        $this->assertFalse($gone->fresh()->is_available);
        $this->assertTrue($hidden->fresh()->is_available);
    }

    public function test_an_out_of_stock_product_becomes_unavailable(): void
    {
        $product = $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');
        $this->fakeCatalog([
            ['game_code' => 'pubgm', 'product_code' => self::K60, 'name' => '60 UC', 'price' => 0.9, 'status' => 'inactive'],
            ['game_code' => 'pubgm', 'product_code' => self::K325, 'name' => '325 UC', 'price' => 4.5, 'status' => 'active'],
        ]);

        $this->sync();

        $this->assertFalse($product->fresh()->is_available);
    }

    public function test_offers_missing_from_the_response_are_marked_unavailable_in_the_mirror(): void
    {
        $this->fakeCatalog();
        $this->sync();

        $this->fakeCatalog([['game_code' => 'pubgm', 'product_code' => self::K60, 'name' => '60 UC', 'price' => 0.9, 'status' => 'active']]);
        $this->sync();

        $this->assertDatabaseHas('provider_offers', ['provider_offer_id' => self::K60, 'is_available' => 1]);
        $this->assertDatabaseHas('provider_offers', ['provider_offer_id' => self::K325, 'is_available' => 0]);
    }

    public function test_an_empty_offer_list_changes_nothing(): void
    {
        $product = $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');
        $this->fakeCatalog([]);

        $summary = $this->sync();

        $this->assertCount(1, $summary['errors']);
        $product = $product->fresh();
        $this->assertSame('0.8000', $product->provider_cost);
        $this->assertTrue($product->is_available);
    }

    public function test_one_failing_category_does_not_stop_the_others(): void
    {
        $this->makeGame(['slug' => 'broken-game', 'provider_category_id' => 'bad-game']);
        $product = $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');

        Http::fake([
            '*game=bad-game*' => Http::response(['success' => false, 'message' => 'Game not found'], 404),
            '*/api/v1/products*' => Http::response($this->productsBody()),
        ]);

        $summary = $this->sync();

        $this->assertCount(1, $summary['errors']);
        $this->assertStringContainsString('bad-game', $summary['errors'][0]);
        $this->assertSame('0.9000', $product->fresh()->provider_cost);
    }

    public function test_an_authentication_error_stops_the_whole_sync(): void
    {
        $product = $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid API key'], 401)]);

        try {
            $this->sync();
            $this->fail('A 401 must abort the sync.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->isAuthError());
            $this->assertSame('0.8000', $product->fresh()->provider_cost);
        }
    }

    public function test_the_sync_command_runs(): void
    {
        $this->linkedProduct(self::K60, '0.80', MarkupType::FixedMarkup, '0.20');
        $this->fakeCatalog();

        $this->artisan('hariki:sync')->assertExitCode(0);

        $this->assertSame('0.9000', Product::where('provider_offer_id', self::K60)->firstOrFail()->provider_cost);
    }

    public function test_the_sync_command_needs_a_key(): void
    {
        config(['hariki.api_key' => '']);

        $this->artisan('hariki:sync')->assertExitCode(1);
    }

    public function test_the_status_command_shows_the_balance(): void
    {
        Http::fake([
            '*/api/v1/profile' => Http::response(['success' => true, 'data' => ['email' => 'partner1@example.com', 'balance' => 100, 'currency' => 'USD', 'subscription' => ['active' => true, 'plan' => 'Monthly Plan']]]),
        ]);

        $this->artisan('hariki:status')
            ->expectsOutputToContain('Provider balance: 100.0000 USD')
            ->assertExitCode(0);
    }

    public function test_the_status_command_reports_a_rejected_key(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid API key'], 401)]);

        $this->artisan('hariki:status')->assertExitCode(1);
    }
}
