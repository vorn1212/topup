<?php

namespace Tests\Feature;

use App\Enums\MarkupType;
use App\Models\Game;
use App\Models\GameField;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

class CatalogAdminTest extends TestCase
{
    use MakesCatalog, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    // ---------------------------------------------------------------- access

    public function test_only_admins_can_reach_catalog_pages(): void
    {
        $game = $this->makeGame();
        $product = $this->makeProduct($game);

        foreach ([User::factory()->create(), User::factory()->support()->create()] as $user) {
            $this->actingAs($user)->get('/admin/games')->assertForbidden();
            $this->actingAs($user)->get('/admin/products')->assertForbidden();
            $this->actingAs($user)->post('/admin/games', ['name' => 'X', 'status' => 'active'])->assertForbidden();
            $this->actingAs($user)->patch("/admin/products/{$product->id}/price", [])->assertForbidden();
            $this->actingAs($user)->post("/admin/products/{$product->id}/price/adjust", ['delta' => '0.10'])->assertForbidden();
        }

        $this->assertSame('1.0000', Product::find($product->id)->selling_price);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/games')->assertRedirect('/login');
        $this->get('/admin/products')->assertRedirect('/login');
    }

    public function test_admin_pages_render(): void
    {
        $game = $this->makeGame(['name' => 'Render Game']);
        $product = $this->makeProduct($game, attributes: ['name' => 'Render Pack']);

        $this->actingAs($this->admin);

        $this->get('/admin/games')->assertOk()->assertSee('Render Game');
        $this->get('/admin/games/create')->assertOk();
        $this->get("/admin/games/{$game->id}/edit")->assertOk()->assertSee('Player fields');
        $this->get('/admin/products')->assertOk()->assertSee('Render Pack');
        $this->get("/admin/products?game_id={$game->id}&q=Render&status=active")->assertOk()->assertSee('Render Pack');
        $this->get('/admin/products/create')->assertOk();
        $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee('Edit price');
        $this->get("/admin/products/{$product->id}/history")->assertOk()->assertSee('Test setup');
    }

    // ---------------------------------------------------------------- games

    public function test_create_game_with_logo_stores_a_reencoded_png(): void
    {
        $this->actingAs($this->admin)->post('/admin/games', [
            'name' => 'Mobile Legends',
            'status' => 'active',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertRedirect();

        $game = Game::firstOrFail();

        $this->assertSame('mobile-legends', $game->slug);
        $this->assertMatchesRegularExpression('#^games/logos/[A-Za-z0-9]{40}\.png$#', $game->logo);
        Storage::disk('public')->assertExists($game->logo);
        $this->assertDatabaseHas('audit_logs', ['action' => 'game.created', 'subject_id' => $game->id]);
    }

    public function test_bad_uploads_are_rejected(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/games', ['name' => 'A', 'status' => 'active', 'logo' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')])
            ->assertSessionHasErrors('logo');

        $this->post('/admin/games', ['name' => 'B', 'status' => 'active', 'logo' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1;')])
            ->assertSessionHasErrors('logo');

        $this->post('/admin/games', ['name' => 'C', 'status' => 'active', 'logo' => UploadedFile::fake()->image('tiny.png', 10, 10)])
            ->assertSessionHasErrors('logo');

        $this->post('/admin/games', ['name' => 'D', 'status' => 'active', 'banner' => UploadedFile::fake()->image('small.jpg', 100, 50)])
            ->assertSessionHasErrors('banner');

        $this->assertSame(0, Game::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_slugs_are_unique_and_generated_when_blank(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/games', ['name' => 'Free Fire', 'status' => 'active']);
        $this->post('/admin/games', ['name' => 'Free Fire', 'status' => 'active']);

        $this->assertEqualsCanonicalizing(['free-fire', 'free-fire-2'], Game::pluck('slug')->all());

        $this->post('/admin/games', ['name' => 'Another', 'slug' => 'free-fire', 'status' => 'active'])
            ->assertSessionHasErrors('slug');

        $this->post('/admin/games', ['name' => 'Another', 'slug' => 'Bad Slug!', 'status' => 'active'])
            ->assertSessionHasErrors('slug');
    }

    public function test_update_and_toggle_a_game(): void
    {
        $game = $this->makeGame(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($this->admin)->put("/admin/games/{$game->id}", [
            'name' => 'New Name', 'slug' => 'old-name', 'status' => 'active', 'sort_order' => 5,
        ])->assertRedirect();

        $this->assertSame('New Name', $game->fresh()->name);
        $this->assertSame(5, $game->fresh()->sort_order);

        $this->actingAs($this->admin)->patch("/admin/games/{$game->id}/toggle")->assertRedirect();
        $this->assertSame('inactive', $game->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'game.toggled', 'subject_id' => $game->id]);
    }

    public function test_a_game_with_products_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $withProducts = $this->makeGame();
        $this->makeProduct($withProducts);

        $this->actingAs($this->admin)->delete("/admin/games/{$withProducts->id}")->assertSessionHas('error');
        $this->assertNotNull(Game::find($withProducts->id));

        $this->actingAs($this->admin)->post('/admin/games', [
            'name' => 'Disposable', 'status' => 'active', 'logo' => UploadedFile::fake()->image('l.png', 100, 100),
        ]);
        $empty = Game::where('slug', 'disposable')->firstOrFail();
        $logo = $empty->logo;
        Storage::disk('public')->assertExists($logo);

        $this->actingAs($this->admin)->delete("/admin/games/{$empty->id}")->assertRedirect('/admin/games');

        $this->assertNull(Game::find($empty->id));
        Storage::disk('public')->assertMissing($logo);
    }

    // ---------------------------------------------------------------- game fields

    public function test_game_fields_can_be_added_updated_and_removed(): void
    {
        $game = $this->makeGame();
        $this->actingAs($this->admin);

        $this->post("/admin/games/{$game->id}/fields", [
            'label' => 'Player ID', 'provider_field_name' => 'player_id', 'type' => 'text',
            'required' => '1', 'validation_rules' => 'digits_between:5,15', 'sort_order' => 1,
        ])->assertRedirect();

        $field = GameField::firstOrFail();
        $this->assertTrue($field->required);
        $this->assertSame('digits_between:5,15', $field->validation_rules);

        $this->post("/admin/games/{$game->id}/fields", [
            'label' => 'Server', 'provider_field_name' => 'server', 'type' => 'select', 'options' => 'Asia, Europe, Asia',
        ])->assertRedirect();
        $this->assertSame(['Asia', 'Europe'], GameField::where('provider_field_name', 'server')->firstOrFail()->options);

        $this->patch("/admin/games/{$game->id}/fields/{$field->id}", [
            'label' => 'UID', 'provider_field_name' => 'uid', 'type' => 'text',
        ])->assertRedirect();
        $this->assertSame('UID', $field->fresh()->label);
        $this->assertFalse($field->fresh()->required);

        $this->delete("/admin/games/{$game->id}/fields/{$field->id}")->assertRedirect();
        $this->assertNull(GameField::find($field->id));
    }

    public function test_game_field_validation(): void
    {
        $game = $this->makeGame();
        $this->actingAs($this->admin);

        $this->post("/admin/games/{$game->id}/fields", [
            'label' => 'X', 'provider_field_name' => 'x', 'type' => 'text', 'validation_rules' => 'exists:users,email',
        ])->assertSessionHasErrors(['validation_rules'], null, 'field');

        $this->post("/admin/games/{$game->id}/fields", [
            'label' => 'X', 'provider_field_name' => 'bad name!', 'type' => 'text',
        ])->assertSessionHasErrors(['provider_field_name'], null, 'field');

        $this->post("/admin/games/{$game->id}/fields", [
            'label' => 'S', 'provider_field_name' => 'srv', 'type' => 'select',
        ])->assertSessionHasErrors(['options'], null, 'field');

        $this->post("/admin/games/{$game->id}/fields", ['label' => 'A', 'provider_field_name' => 'dup', 'type' => 'text']);
        $this->post("/admin/games/{$game->id}/fields", ['label' => 'B', 'provider_field_name' => 'dup', 'type' => 'text'])
            ->assertSessionHasErrors(['provider_field_name'], null, 'field');

        $this->assertSame(1, GameField::count());
    }

    public function test_a_field_cannot_be_changed_through_another_game(): void
    {
        $a = $this->makeGame();
        $b = $this->makeGame();

        $field = new GameField(['provider_field_name' => 'pid', 'label' => 'Player', 'type' => 'text', 'required' => true, 'sort_order' => 0]);
        $field->game_id = $a->id;
        $field->save();

        $this->actingAs($this->admin)
            ->delete("/admin/games/{$b->id}/fields/{$field->id}")
            ->assertNotFound();

        $this->assertNotNull(GameField::find($field->id));
    }

    // ---------------------------------------------------------------- products

    public function test_create_product_calculates_price_and_writes_history(): void
    {
        $game = $this->makeGame(['slug' => 'mobile-legends']);

        $this->actingAs($this->admin)->post('/admin/products', [
            'game_id' => $game->id,
            'name' => '86 Diamonds',
            'status' => 'active',
            'provider_cost' => '0.80',
            'markup_type' => 'fixed_markup',
            'markup_value' => '0.20',
            'selling_price' => '999', // must be ignored
        ])->assertRedirect();

        $product = Product::firstOrFail();

        $this->assertSame('1.0000', $product->selling_price);
        $this->assertMatchesRegularExpression('/^MOBILELE-[A-Z0-9]{6}$/', $product->sku);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id, 'new_price' => '1.0000', 'reason' => 'Product created', 'changed_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'subject_id' => $product->id]);
    }

    public function test_create_product_below_cost_is_rejected_for_an_admin(): void
    {
        $game = $this->makeGame();

        $this->actingAs($this->admin)->post('/admin/products', [
            'game_id' => $game->id, 'name' => 'Loss', 'status' => 'active',
            'provider_cost' => '1.00', 'markup_type' => 'fixed_price', 'markup_value' => '0.50',
        ])->assertSessionHasErrors('markup_value');

        $this->assertSame(0, Product::count());
    }

    public function test_product_details_update_cannot_change_prices(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin)->patch("/admin/products/{$product->id}", [
            'name' => 'Renamed', 'status' => 'active',
            'selling_price' => '0.01', 'provider_cost' => '0.01', 'markup_value' => '0',
        ])->assertRedirect();

        $fresh = Product::find($product->id);
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('1.0000', $fresh->selling_price);
        $this->assertSame('0.8000', $fresh->provider_cost);
    }

    public function test_price_update_requires_a_reason_and_records_it(): void
    {
        $product = $this->makeProduct();
        $this->actingAs($this->admin);

        $payload = ['provider_cost' => '0.90', 'markup_type' => 'fixed_markup', 'markup_value' => '0.30'];

        $this->patch("/admin/products/{$product->id}/price", $payload)->assertSessionHasErrors('reason');

        $this->patch("/admin/products/{$product->id}/price", $payload + ['reason' => 'FazerCards cost increase'])
            ->assertRedirect("/admin/products/{$product->id}/edit");

        $this->assertSame('1.2000', Product::find($product->id)->selling_price);
        $this->assertDatabaseHas('product_price_histories', [
            'product_id' => $product->id, 'new_price' => '1.2000', 'reason' => 'FazerCards cost increase', 'changed_by' => $this->admin->id,
        ]);
    }

    public function test_below_cost_price_update_needs_a_super_admin_and_confirmation(): void
    {
        $product = $this->makeProduct();
        $payload = ['provider_cost' => '0.80', 'markup_type' => 'fixed_price', 'markup_value' => '0.70', 'reason' => 'Clearance', 'confirm_loss' => '1'];

        $this->actingAs($this->admin)->patch("/admin/products/{$product->id}/price", $payload)->assertSessionHasErrors('price');
        $this->assertSame('1.0000', Product::find($product->id)->selling_price);

        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->patch("/admin/products/{$product->id}/price", $payload)->assertSessionHasNoErrors();
        $this->assertSame('0.7000', Product::find($product->id)->selling_price);
    }

    public function test_quick_adjust_buttons(): void
    {
        $product = $this->makeProduct(); // cost 0.80, selling 1.00
        $this->actingAs($this->admin);

        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '0.10'])->assertSessionHas('status');
        $this->assertSame('1.1000', Product::find($product->id)->selling_price);

        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '-0.10']);
        $this->assertSame('1.0000', Product::find($product->id)->selling_price);

        // A step that is not in the allowed list is rejected.
        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '5.55'])->assertSessionHasErrors('delta');
        $this->assertSame('1.0000', Product::find($product->id)->selling_price);

        // -0.50 would give 0.50, below the 0.80 cost: refused for a normal admin.
        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '-0.50'])->assertSessionHas('error');
        $this->assertSame('1.0000', Product::find($product->id)->selling_price);

        // Down to exactly the cost is allowed; one more step below it is not.
        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '-0.10']);
        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '-0.10']);
        $this->assertSame('0.8000', Product::find($product->id)->selling_price);

        $this->post("/admin/products/{$product->id}/price/adjust", ['delta' => '-0.10'])->assertSessionHas('error');
        $this->assertSame('0.8000', Product::find($product->id)->selling_price);
    }

    public function test_toggle_a_product(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin)->patch("/admin/products/{$product->id}/toggle")->assertRedirect();

        $this->assertSame('inactive', Product::find($product->id)->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.toggled', 'subject_id' => $product->id]);
    }

    public function test_percent_markup_product_via_the_form(): void
    {
        $game = $this->makeGame();

        $this->actingAs($this->admin)->post('/admin/products', [
            'game_id' => $game->id, 'name' => 'Pct', 'status' => 'active',
            'provider_cost' => '0.85', 'markup_type' => MarkupType::PercentMarkup->value, 'markup_value' => '25',
        ])->assertRedirect();

        $this->assertSame('1.0625', Product::firstOrFail()->selling_price);
    }
}