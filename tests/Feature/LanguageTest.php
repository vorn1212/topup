<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Enums\WalletTxType;
use App\Http\Middleware\SetLocale;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    private const HEADLINE_KM = 'បញ្ចូលទឹកប្រាក់ហ្គេម ទទួលបានក្នុងរយៈពេលប៉ុន្មាននាទី';

    private const HEADLINE_EN = 'Game top-up, delivered in minutes';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    protected function tearDown(): void
    {
        Carbon::setLocale('en');

        parent::tearDown();
    }

    /** @return array<string,string> */
    private function dictionary(): array
    {
        return json_decode((string) file_get_contents(lang_path('km.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- the switch

    public function test_choosing_khmer_is_remembered_in_the_session_and_a_cookie(): void
    {
        $response = $this->from('/games')->post('/locale', ['locale' => 'km']);

        $response->assertRedirect('/games');
        $response->assertSessionHas('locale', 'km');
        $response->assertCookie('locale');
    }

    public function test_only_supported_languages_are_accepted(): void
    {
        foreach (['fr', 'KM', '../../etc/passwd', '<script>', '', 'km,en'] as $bad) {
            $this->post('/locale', ['locale' => $bad])->assertSessionHasErrors('locale');
        }

        $this->post('/locale', [])->assertSessionHasErrors('locale');
        $this->post('/locale', ['locale' => ['km']])->assertSessionHasErrors('locale');

        $this->assertNull(session('locale'));
    }

    public function test_the_switch_never_redirects_to_another_website(): void
    {
        $this->from('https://evil.example/phish')->post('/locale', ['locale' => 'km'])
            ->assertRedirect(route('home'));
    }

    public function test_the_choice_is_saved_on_the_account_of_a_signed_in_customer(): void
    {
        $this->actingAs($this->customer)->post('/locale', ['locale' => 'km'])->assertSessionHasNoErrors();

        $this->assertSame('km', $this->customer->fresh()->locale);

        $this->actingAs($this->customer)->post('/locale', ['locale' => 'en']);

        $this->assertSame('en', $this->customer->fresh()->locale);
    }

    public function test_a_visitor_cannot_change_anything_else_through_the_switch(): void
    {
        $this->actingAs($this->customer)->post('/locale', ['locale' => 'km', 'role' => 'super_admin', 'status' => 'banned']);

        $fresh = $this->customer->fresh();

        $this->assertSame('user', $fresh->getRawOriginal('role'));
        $this->assertSame('active', $fresh->getRawOriginal('status'));
    }

    public function test_the_switch_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->post('/locale', ['locale' => 'km'])->assertStatus(302);
        }

        $this->post('/locale', ['locale' => 'km'])->assertStatus(429);
    }

    // ---------------------------------------------------------------- which language is used

    public function test_english_is_the_default(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee(self::HEADLINE_EN)
            ->assertDontSee(self::HEADLINE_KM);
    }

    public function test_the_session_language_is_used(): void
    {
        $this->withSession(['locale' => 'km'])->get('/')->assertOk()
            ->assertSee('lang="km"', false)
            ->assertSee(self::HEADLINE_KM)
            ->assertDontSee(self::HEADLINE_EN);
    }

    public function test_the_cookie_language_is_used_when_there_is_no_session_choice(): void
    {
        $this->withCookie('locale', 'km')->get('/')->assertOk()->assertSee(self::HEADLINE_KM);
    }

    public function test_a_broken_cookie_or_session_value_falls_back_to_english(): void
    {
        $this->withCookie('locale', 'xx')->get('/')->assertOk()->assertSee(self::HEADLINE_EN);
        $this->withSession(['locale' => ['km']])->get('/')->assertOk()->assertSee(self::HEADLINE_EN);
    }

    public function test_the_saved_account_language_wins_for_a_signed_in_customer(): void
    {
        $this->customer->forceFill(['locale' => 'km'])->save();

        $this->actingAs($this->customer)->withSession(['locale' => 'en'])->get('/')
            ->assertOk()->assertSee(self::HEADLINE_KM);
    }

    public function test_the_khmer_font_is_loaded_only_for_khmer(): void
    {
        // English first: a session set with withSession() would otherwise carry over to the next request.
        $this->get('/')->assertOk()->assertDontSee('noto-sans-khmer', false);

        $this->withSession(['locale' => 'km'])->get('/')->assertOk()->assertSee('noto-sans-khmer', false);
    }

    public function test_the_switcher_is_on_public_customer_and_login_pages_but_not_in_the_admin_area(): void
    {
        $this->get('/')->assertSee('name="locale"', false);
        $this->get('/login')->assertSee('name="locale"', false);
        $this->actingAs($this->customer)->get('/dashboard')->assertSee('name="locale"', false);

        $this->actingAs($this->staff('admin'))->get('/admin')->assertOk()->assertDontSee('name="locale"', false);
    }

    public function test_the_admin_area_stays_in_english(): void
    {
        $super = $this->staff('super_admin');

        $this->actingAs($super)->withSession(['locale' => 'km'])->get('/admin/health')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Queue worker');
    }

    public function test_the_webhook_does_not_need_a_session_for_the_language(): void
    {
        config(['fazercards.webhook_secret' => null]);

        $this->postJson('/webhooks/fazercards', ['x' => 1])->assertNotFound();
    }

    // ---------------------------------------------------------------- the pages

    public function test_customer_pages_render_in_khmer_without_errors(): void
    {
        $order = Order::findOrFail($this->placeOrder()->order_id);
        $this->actingAs($this->customer)->withSession(['locale' => 'km']);

        foreach (['/', '/games', '/games/pubg-mobile', '/games/pubg-mobile/top-up', '/dashboard', '/wallet', '/wallet/deposit', '/orders', '/order/'.$order->order_number, '/notifications', '/profile'] as $url) {
            $this->get($url)->assertOk()->assertSee('lang="km"', false);
        }
    }

    public function test_key_sentences_are_translated(): void
    {
        $order = Order::findOrFail($this->placeOrder()->order_id);
        $this->actingAs($this->customer)->withSession(['locale' => 'km']);

        $this->get('/wallet')->assertSee('ប្រវត្តិប្រតិបត្តិការ');
        $this->get('/wallet/deposit')->assertSee('ចំនួនទឹកប្រាក់ដែលត្រូវដាក់');
        $this->get('/games/pubg-mobile/top-up')->assertSee('ព័ត៌មានអ្នកលេង')->assertSee('បង់ប្រាក់ពីកាបូប');
        $this->get('/order/'.$order->order_number)->assertSee('បានបញ្ជាទិញនៅ');
        $this->get('/orders')->assertSee('មើលព័ត៌មានលម្អិត');
    }

    public function test_the_scripts_of_the_top_up_page_get_khmer_sentences(): void
    {
        $this->actingAs($this->customer)->withSession(['locale' => 'km']);

        $html = $this->get('/games/pubg-mobile/top-up')->assertOk()->getContent();

        // The scripts receive the sentences as JSON (unicode may be escaped), so check for the key names.
        $this->assertStringContainsString('payConfirm', $html);
        $this->assertStringContainsString('notFound', $html);
        $this->assertStringNotContainsString("'Pay ' +", $html, 'the pay confirmation must not be hard-coded English any more');
    }

    public function test_money_and_order_numbers_are_never_translated(): void
    {
        $order = Order::findOrFail($this->placeOrder()->order_id);
        $this->actingAs($this->customer)->withSession(['locale' => 'km']);

        $page = $this->get('/order/'.$order->order_number)->assertOk();

        $page->assertSee($order->order_number);
        $page->assertSee(Money::display((string) $order->selling_price, $order->currency));
    }

    public function test_stored_notifications_are_shown_in_khmer(): void
    {
        $this->customer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\DepositPaidNotification',
            'data' => [
                'kind' => 'deposit.paid',
                'title' => 'Deposit received',
                'body' => '$10.00 was added to your wallet (deposit DEP-20261014-ABC123).',
                'url' => '/wallet',
            ],
        ]);

        $this->actingAs($this->customer)->withSession(['locale' => 'km'])->get('/notifications')
            ->assertOk()
            ->assertSee('បានទទួលការដាក់ប្រាក់')
            ->assertSee('$10.00')
            ->assertSee('DEP-20261014-ABC123')
            ->assertDontSee('was added to your wallet');

        $this->actingAs($this->customer)->withSession(['locale' => 'en'])->get('/notifications')
            ->assertSee('was added to your wallet');
    }

    public function test_a_price_change_message_keeps_its_amount_in_khmer(): void
    {
        $this->actingAs($this->customer)->withSession(['locale' => 'km'])
            ->withSession(['locale' => 'km', 'error' => 'The price changed to $1.50. Please check it and confirm again.'])
            ->get('/orders')
            ->assertOk()
            ->assertSee('$1.50')
            ->assertSee('តម្លៃបានប្តូរទៅជា')
            ->assertDontSee('The price changed');
    }

    public function test_dates_follow_the_language(): void
    {
        $order = Order::findOrFail($this->placeOrder()->order_id);
        $this->actingAs($this->customer);

        $khmer = $this->withSession(['locale' => 'km'])->get('/order/'.$order->order_number)->assertOk()->getContent();
        $english = $this->withSession(['locale' => 'en'])->get('/order/'.$order->order_number)->assertOk()->getContent();

        $month = $order->created_at->copy()->locale('en')->translatedFormat('M');

        $this->assertStringContainsString($month, $english);
        $this->assertStringNotContainsString($month.' ', $khmer, 'the English month name must not appear in the Khmer page');
    }

    // ---------------------------------------------------------------- the dictionary

    public function test_the_dictionary_is_complete_and_consistent(): void
    {
        $dictionary = $this->dictionary();

        foreach ($dictionary as $key => $value) {
            $this->assertIsString($value);
            $this->assertNotSame('', trim($value), "empty translation for: {$key}");

            preg_match_all('/:([A-Za-z_]+)/', $key, $inKey);
            preg_match_all('/:([A-Za-z_]+)/', $value, $inValue);

            $this->assertEqualsCanonicalizing(
                array_unique($inKey[1]),
                array_unique($inValue[1]),
                "placeholders differ for: {$key}"
            );
        }
    }

    public function test_every_sentence_in_the_customer_views_has_a_khmer_translation(): void
    {
        $dictionary = $this->dictionary();
        $missing = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            // The admin area stays in English.
            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/views/admin/') || str_ends_with($path, 'admin-nav.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $single);
            preg_match_all('/__\\(\\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $double);

            foreach ([...$single[1], ...$double[1]] as $key) {
                $key = str_replace(["\\'", '\\"'], ["'", '"'], $key);

                if (! array_key_exists($key, $dictionary)) {
                    $missing[$key] = basename($path);
                }
            }
        }

        $this->assertSame([], $missing, 'Add these sentences to lang/km.json');
    }

    public function test_labels_that_come_from_the_code_are_translated(): void
    {
        $dictionary = $this->dictionary();

        foreach (WalletTxType::cases() as $case) {
            $this->assertArrayHasKey($case->label(), $dictionary, 'wallet type '.$case->value);
        }

        foreach (UserStatus::cases() as $case) {
            $this->assertArrayHasKey($case->label(), $dictionary, 'account status '.$case->value);
        }

        foreach (['Completed', 'Paid', 'Active', 'Pending', 'Processing', 'Refunding', 'Refunded', 'Failed', 'Suspended', 'Banned', 'Cancelled'] as $status) {
            $this->assertArrayHasKey($status, $dictionary, 'status badge '.$status);
        }

        foreach ([
            'Order placed. We are sending your top-up to the game now.',
            'This order was already placed.',
            'Your wallet balance is not enough for this package.',
            'You already have a deposit in progress. Finish it or wait for it to expire.',
            'This package is not available right now.',
            'Your account cannot place orders. Please contact support.',
            'This account has been banned.',
        ] as $message) {
            $this->assertArrayHasKey($message, $dictionary, $message);
        }
    }

    public function test_the_supported_languages_are_exactly_english_and_khmer(): void
    {
        $this->assertSame(['en', 'km'], array_keys(SetLocale::LOCALES));
        $this->assertTrue(SetLocale::supported('km'));
        $this->assertFalse(SetLocale::supported('de'));
        $this->assertFalse(SetLocale::supported(null));
        $this->assertFalse(SetLocale::supported(['km']));
    }

    public function test_validation_messages_exist_in_khmer(): void
    {
        $messages = require lang_path('km/validation.php');

        foreach (['required', 'email', 'confirmed', 'unique', 'regex', 'integer', 'string', 'array', 'in', 'digits_between', 'current_password'] as $rule) {
            $this->assertArrayHasKey($rule, $messages);
        }

        $this->assertArrayHasKey('failed', require lang_path('km/auth.php'));
    }

    public function test_validation_errors_are_shown_in_khmer(): void
    {
        $this->withSession(['locale' => 'km'])->from('/register')->post('/register', [])
            ->assertSessionHasErrors('email');

        $errors = session('errors')->get('email');

        $this->assertStringContainsString('អ៊ីមែល', $errors[0]);
    }
}