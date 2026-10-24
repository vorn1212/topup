<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WalletDeposit;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

/**
 * Who may open what, and what is rate limited. The first group of tests reads the route table itself,
 * so a route added in the future without protection fails here instead of going live unprotected.
 */
class SecurityAccessTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    /** Mutating routes that a signed-out visitor is meant to reach. */
    private const PUBLIC_POSTS = ['login', 'register', 'forgot-password', 'reset-password', 'webhooks/fazercards', 'checkout', 'locale'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    /** @return list<Route> */
    private function routes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => ! str_starts_with($route->uri(), '_') && ! str_starts_with($route->uri(), 'up')
        ));
    }

    /** @return list<string> */
    private function middleware(Route $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
    }

    private function has(array $middleware, string $prefix): bool
    {
        foreach ($middleware as $m) {
            if ($m === $prefix || str_starts_with($m, $prefix.':')) {
                return true;
            }
        }

        return false;
    }

    private function mutating(Route $route): bool
    {
        return array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
    }

    // ---------------------------------------------------------------- the route table

    public function test_every_admin_route_requires_login_a_staff_role_and_a_permission(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            if (! str_starts_with($route->uri(), 'admin')) {
                continue;
            }

            $m = $this->middleware($route);
            $name = $route->getName() ?? $route->uri();

            $this->assertTrue($this->has($m, 'auth'), "{$name} has no login requirement");
            $this->assertTrue(
                $this->has($m, 'role') || collect($m)->contains(fn ($x) => str_contains($x, 'EnsureRole')),
                "{$name} has no staff role requirement"
            );
            $this->assertTrue(collect($m)->contains(fn ($x) => str_starts_with($x, 'can:')), "{$name} has no permission (can:) requirement");

            $checked++;
        }

        $this->assertGreaterThan(40, $checked, 'the admin routes were not found');
    }

    public function test_every_mutating_route_needs_login_unless_it_is_on_the_public_list(): void
    {
        $checked = 0;

        foreach ($this->routes() as $route) {
            if (! $this->mutating($route) || str_starts_with($route->uri(), 'admin')) {
                continue;
            }

            if (in_array($route->uri(), self::PUBLIC_POSTS, true)) {
                continue;
            }

            // Laravel's own local-disk file route (signed URLs only), not part of the shop.
            if (str_starts_with((string) $route->getName(), 'storage.')) {
                continue;
            }

            $this->assertTrue(
                $this->has($this->middleware($route), 'auth'),
                ($route->getName() ?? $route->uri()).' changes data but does not require login'
            );
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    public function test_csrf_protection_is_only_switched_off_for_the_webhook(): void
    {
        $off = [];

        foreach ($this->routes() as $route) {
            if ($this->mutating($route) && in_array(ValidateCsrfToken::class, $route->excludedMiddleware(), true)) {
                $off[] = $route->uri();
            }
        }

        $this->assertSame(['webhooks/fazercards'], $off);
    }

    public function test_money_and_provider_actions_are_rate_limited(): void
    {
        $names = [
            'orders.store', 'orders.status',
            'wallet.deposit.store', 'wallet.deposit.status', 'wallet.deposit.qr',
            'games.validate-player', 'notifications.read-all',
            'webhooks.fazercards', 'locale.update',
            'admin.reports.export',
            'admin.users.status', 'admin.users.freeze', 'admin.users.role', 'admin.users.adjust',
            'admin.orders.notes.store',
            'admin.attention.balance', 'admin.attention.orders.recheck', 'admin.attention.orders.resend',
            'admin.attention.orders.complete', 'admin.attention.orders.refund',
            'admin.provider.sync',
        ];

        foreach ($names as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);

            $this->assertNotNull($route, "route {$name} does not exist");
            $this->assertTrue($this->has($this->middleware($route), 'throttle'), "{$name} is not rate limited");
        }
    }

    public function test_orders_and_deposits_need_an_active_account(): void
    {
        foreach (['orders.store', 'wallet.deposit.store', 'games.validate-player'] as $name) {
            $this->assertTrue(
                $this->has($this->middleware(RouteFacade::getRoutes()->getByName($name)), 'active'),
                "{$name} must use the active-account check"
            );
        }
    }

    // ---------------------------------------------------------------- guests

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach (['/dashboard', '/wallet', '/wallet/deposit', '/orders', '/notifications', '/profile', '/admin', '/admin/users', '/admin/health'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $this->post('/orders')->assertRedirect('/login');
        $this->post('/wallet/deposit')->assertRedirect('/login');
        $this->post('/admin/users/1/adjust')->assertRedirect('/login');
    }

    public function test_the_webhook_is_closed_when_no_secret_is_configured(): void
    {
        config(['fazercards.webhook_secret' => null]);

        $this->postJson('/webhooks/fazercards', ['order_id' => 'x'])->assertNotFound();
    }

    // ---------------------------------------------------------------- customers cannot reach each other's data

    private function otherCustomer(): User
    {
        return User::factory()->create();
    }

    public function test_another_customer_cannot_open_my_order(): void
    {
        $order = Order::findOrFail($this->placeOrder()->order_id);
        $other = $this->otherCustomer();

        $this->actingAs($other)->get('/order/'.$order->order_number)->assertNotFound();
        $this->actingAs($other)->get('/order/'.$order->order_number.'/status')->assertNotFound();

        $this->actingAs($this->customer)->get('/order/'.$order->order_number)->assertOk();
    }

    public function test_another_customer_cannot_open_my_deposit(): void
    {
        $location = $this->actingAs($this->customer)
            ->post('/wallet/deposit', ['amount' => '10'])
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringContainsString('/wallet/deposit/', (string) $location);

        $deposit = WalletDeposit::query()->where('user_id', $this->customer->id)->firstOrFail();
        $other = $this->otherCustomer();

        foreach (['', '/status', '/qr'] as $suffix) {
            $this->actingAs($other)->get('/wallet/deposit/'.$deposit->deposit_number.$suffix)->assertNotFound();
        }
    }

    public function test_customers_cannot_reach_the_admin_area_or_the_health_page(): void
    {
        $this->actingAs($this->customer);

        foreach (['/admin', '/admin/users', '/admin/orders', '/admin/deposits', '/admin/transactions', '/admin/reports', '/admin/audit', '/admin/attention', '/admin/health'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_support_staff_cannot_change_money_prices_or_roles(): void
    {
        $support = $this->staff('support');
        $this->actingAs($support);

        $this->post('/admin/users/'.$this->customer->id.'/adjust', ['amount' => '5', 'direction' => 'credit', 'reason' => 'free money'])->assertForbidden();
        $this->post('/admin/users/'.$this->customer->id.'/role', ['role' => 'super_admin'])->assertForbidden();
        $this->patch('/admin/products/'.$this->product->id.'/price', ['markup_value' => '0'])->assertForbidden();

        $this->assertSame('user', $this->customer->fresh()->getRawOriginal('role'));
    }

    // ---------------------------------------------------------------- mass assignment

    public function test_registering_cannot_choose_a_role_or_status(): void
    {
        $this->post('/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'a-Strong-pass-123',
            'password_confirmation' => 'a-Strong-pass-123',
            'role' => 'super_admin',
            'status' => 'active',
            'is_admin' => 1,
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        $user = User::where('email', 'sneaky@example.com')->first();

        $this->assertNotNull($user, 'registration should have worked');
        $this->assertSame('user', $user->getRawOriginal('role'));
    }

    public function test_the_profile_form_cannot_change_role_status_or_balance(): void
    {
        $this->actingAs($this->customer)->patch('/profile', [
            'name' => 'New Name',
            'email' => $this->customer->email,
            'role' => 'super_admin',
            'status' => 'banned',
            'balance' => '9999',
        ]);

        $fresh = $this->customer->fresh();

        $this->assertSame('user', $fresh->getRawOriginal('role'));
        $this->assertSame('active', $fresh->getRawOriginal('status'));
        $this->assertSame(0, bccomp($this->customerBalance(), '20', 4), 'the balance must not change');
    }

    // ---------------------------------------------------------------- brute force

    public function test_login_locks_out_after_five_wrong_passwords(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_placing_orders_is_limited_to_ten_a_minute(): void
    {
        $this->actingAs($this->customer);

        for ($i = 0; $i < 10; $i++) {
            $this->post('/orders', [])->assertStatus(302);
        }

        $this->post('/orders', [])->assertStatus(429);
    }

    public function test_starting_deposits_is_limited(): void
    {
        $this->actingAs($this->customer);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/wallet/deposit', ['amount' => 'abc'])->assertStatus(302);
        }

        $this->post('/wallet/deposit', ['amount' => 'abc'])->assertStatus(429);
    }

    public function test_registration_is_limited_per_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/register', [])->assertStatus(302);
        }

        $this->post('/register', [])->assertStatus(429);
    }
}