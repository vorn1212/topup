<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Exceptions\ProviderException;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProviderCategory;
use App\Services\TopUp\CatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;
use Illuminate\Http\Client\Factory as HttpFactory;

class CatalogSyncTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private $game;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fazercards.api_key' => 'test-key-123',
            'fazercards.base_url' => 'https://api.fzr.cards/api/v2',
            'fazercards.retry_sleep_ms' => 0,
            'fazercards.sync.delay_ms' => 0,
        ]);

        $this->game = $this->makeGame(['slug' => 'pubg-mobile', 'provider_category_id' => 'cat_pubgm_1']);
    }

    private function offersBody(array $offers = null): array
    {
        return [
            'ok' => true,
            'category_id' => 'cat_pubgm_1',
            'name' => 'PUBG Mobile',
            'offers' => $offers ?? [
                ['offer_id' => 'offer_60uc', 'name' => '60 UC', 'price_usd' => '0.9000'],
                ['offer_id' => 'offer_325uc', 'name' => '325 UC', 'price_usd' => '4.50'],
            ],
            'fields' => [['key' => 'player_id', 'label' => 'Player ID', 'type' => 'text']],
        ];
    }

    private function listBody(): array
    {
        return [
            'ok' => true,
            'items' => [
                ['category_id' => 'cat_pubgm_1', 'name' => 'PUBG Mobile'],
                ['category_id' => 'cat_other', 'name' => 'Other Game'],
            ],
            'meta' => ['total' => 2, 'limit' => 100, 'next_cursor' => null, 'has_more' => false],
        ];
    }

    private function fakeCatalog(?array $offers = null): void
    {
        // Start from a clean fake so a second call really replaces the first one.
        Http::swap(new HttpFactory);

        Http::fake([
            '*/topups/offers*' => Http::response($this->offersBody($offers)),
            '*/topups*' => Http::response($this->listBody()),
        ]);
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

        $this->assertDatabaseHas('provider_categories', ['provider_category_id' => 'cat_pubgm_1', 'name' => 'PUBG Mobile']);
        $this->assertDatabaseHas('provider_categories', ['provider_category_id' => 'cat_other']);
        $this->assertDatabaseHas('provider_offers', [
            'provider_category_id' => 'cat_pubgm_1', 'provider_offer_id' => 'offer_60uc', 'provider_cost' => '0.9000', 'is_available' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.synced']);

        $category = ProviderCategory::where('provider_category_id', 'cat_pubgm_1')->firstOrFail();
        $this->assertSame('player_id', $category->fields[0]['key']);
    }

    public function test_only_linked_categories_are_fetched_in_detail(): void
    {
        $this->fakeCatalog();

        $this->sync();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'category_id=cat_other'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'category_id=cat_pubgm_1'));
    }

    public function test_markup_products_follow_the_new_cost_and_history_is_written(): void
    {
        $fixed = $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20');      // 1.00
        $percent = $this->linkedProduct('offer_325uc', '4.00', MarkupType::PercentMarkup, '25');   // 5.00
        $this->fakeCatalog();

        $summary = $this->sync();

        $this->assertSame(2, $summary['price_changes']);

        $fixed = $fixed->fresh();
        $this->assertSame('0.9000', $fixed->provider_cost);
        $this->assertSame('1.1000', $fixed->selling_price);

        $percent = $percent->fresh();
        $this->assertSame('4.5000', $percent->provider_cost);
        $this->assertSame('5.6250', $percent->selling_price);

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
        $product = $this->linkedProduct('offer_325uc', '0.80', MarkupType::FixedPrice, '1.00');
        $this->fakeCatalog();

        $this->sync();

        $product = $product->fresh();
        $this->assertSame('1.0000', $product->selling_price);
        $this->assertSame('4.5000', $product->provider_cost);
        $this->assertTrue($product->isBelowCost());
    }

    public function test_a_second_identical_sync_changes_nothing(): void
    {
        $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20');
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
        $gone = $this->linkedProduct('offer_gone', '0.80', MarkupType::FixedMarkup, '0.20');
        $hidden = $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20', ['is_available' => false]);
        $this->fakeCatalog();

        $summary = $this->sync();

        $this->assertSame(1, $summary['products_unavailable']);
        $this->assertFalse($gone->fresh()->is_available);
        $this->assertTrue($hidden->fresh()->is_available);
    }

    public function test_offers_missing_from_the_response_are_marked_unavailable_in_the_mirror(): void
    {
        $this->fakeCatalog();
        $this->sync();

        $this->fakeCatalog([['offer_id' => 'offer_60uc', 'name' => '60 UC', 'price_usd' => '0.9000']]);
        $this->sync();

        $this->assertDatabaseHas('provider_offers', ['provider_offer_id' => 'offer_60uc', 'is_available' => 1]);
        $this->assertDatabaseHas('provider_offers', ['provider_offer_id' => 'offer_325uc', 'is_available' => 0]);
    }

    public function test_an_empty_offer_list_changes_nothing(): void
    {
        $product = $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20');
        $this->fakeCatalog([]);

        $summary = $this->sync();

        $this->assertCount(1, $summary['errors']);
        $product = $product->fresh();
        $this->assertSame('0.8000', $product->provider_cost);
        $this->assertTrue($product->is_available);
    }

    public function test_one_failing_category_does_not_stop_the_others(): void
    {
        $this->makeGame(['slug' => 'broken-game', 'provider_category_id' => 'cat_bad']);
        $product = $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20');

        Http::fake([
            '*/topups/offers?category_id=cat_bad*' => Http::response(['ok' => false, 'error' => 'Category not found'], 404),
            '*/topups/offers*' => Http::response($this->offersBody()),
            '*/topups*' => Http::response($this->listBody()),
        ]);

        $summary = $this->sync();

        $this->assertCount(1, $summary['errors']);
        $this->assertStringContainsString('cat_bad', $summary['errors'][0]);
        $this->assertSame('0.9000', $product->fresh()->provider_cost);
    }

    public function test_an_authentication_error_stops_the_whole_sync(): void
    {
        $product = $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20');
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid API key'], 401)]);

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
        $this->linkedProduct('offer_60uc', '0.80', MarkupType::FixedMarkup, '0.20');
        $this->fakeCatalog();

        $this->artisan('fazercards:sync')->assertExitCode(0);

        $this->assertSame('0.9000', Product::where('provider_offer_id', 'offer_60uc')->firstOrFail()->provider_cost);
    }

    public function test_the_sync_command_needs_a_key(): void
    {
        config(['fazercards.api_key' => '']);

        $this->artisan('fazercards:sync')->assertExitCode(1);
    }

    public function test_the_status_command_shows_the_balance(): void
    {
        Http::fake([
            '*/me' => Http::response(['ok' => true, 'login' => 'partner1', 'plan' => 'gold', 'subscriptionActive' => true]),
            '*/balance' => Http::response(['ok' => true, 'balance' => '100.0000', 'currency' => 'USD']),
        ]);

        $this->artisan('fazercards:status')
            ->expectsOutputToContain('Provider balance: 100.0000 USD')
            ->assertExitCode(0);
    }

    public function test_the_status_command_reports_a_rejected_key(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'Invalid API key'], 401)]);

        $this->artisan('fazercards:status')->assertExitCode(1);
    }
}