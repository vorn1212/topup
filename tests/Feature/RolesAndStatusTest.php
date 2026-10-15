<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class RolesAndStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_regular_user_gets_403_on_admin(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_support_can_view_but_not_change_money_or_config(): void
    {
        $user = User::factory()->support()->create();

        $this->actingAs($user)->get('/admin')->assertOk();

        $gate = Gate::forUser($user);
        $this->assertTrue($gate->allows('orders.view'));
        $this->assertTrue($gate->allows('transactions.view'));
        $this->assertFalse($gate->allows('wallet.adjust'));
        $this->assertFalse($gate->allows('settings.manage'));
        $this->assertFalse($gate->allows('roles.manage'));
        $this->assertFalse($gate->allows('users.manage'));
    }

    public function test_admin_permissions(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/admin')->assertOk();

        $gate = Gate::forUser($user);
        $this->assertTrue($gate->allows('pricing.manage'));
        $this->assertTrue($gate->allows('users.manage'));
        $this->assertFalse($gate->allows('wallet.adjust'));
        $this->assertFalse($gate->allows('settings.manage'));
        $this->assertFalse($gate->allows('pricing.allow_loss'));
    }

    public function test_super_admin_has_every_permission(): void
    {
        $user = User::factory()->superAdmin()->create();

        $gate = Gate::forUser($user);
        $this->assertTrue($gate->allows('wallet.adjust'));
        $this->assertTrue($gate->allows('settings.manage'));
        $this->assertTrue($gate->allows('roles.manage'));
    }

    public function test_suspended_staff_lose_admin_access_and_permissions(): void
    {
        $user = User::factory()->admin()->suspended()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->assertFalse(Gate::forUser($user)->allows('orders.view'));

        $super = User::factory()->superAdmin()->suspended()->create();
        $this->assertFalse(Gate::forUser($super)->allows('wallet.adjust'));
    }

    public function test_banned_user_cannot_log_in(): void
    {
        $user = User::factory()->banned()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_suspended_user_can_log_in_and_see_dashboard(): void
    {
        $user = User::factory()->suspended()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);

        $this->get('/dashboard')->assertOk()->assertSee('Suspended');
    }

    public function test_active_middleware_blocks_suspended_users(): void
    {
        Route::middleware(['web', 'auth', 'active'])->get('/_money-test', fn () => 'ok');

        $suspended = User::factory()->suspended()->create();
        $this->actingAs($suspended)->get('/_money-test')->assertRedirect(route('dashboard'));

        $this->actingAs($suspended)->getJson('/_money-test')
            ->assertForbidden()
            ->assertJson(['success' => false]);

        $active = User::factory()->create();
        $this->actingAs($active)->get('/_money-test')->assertOk();
    }

    public function test_registration_cannot_mass_assign_role_or_status(): void
    {
        $this->post('/register', [
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'phone' => '+85512345678',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'super_admin',
            'status' => 'banned',
        ]);

        $user = User::where('email', 'tester@example.com')->firstOrFail();

        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame('+85512345678', $user->phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.register', 'actor_id' => $user->id]);
    }

    public function test_registration_rejects_invalid_phone(): void
    {
        $this->post('/register', [
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'phone' => 'abc',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('phone');
    }

    public function test_dashboard_renders_before_wallet_tables_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Wallet balance');
    }

    public function test_login_and_logout_are_audited(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_audit_service_redacts_secrets(): void
    {
        $log = app(AuditService::class)->log('test.action', null, [], [
            'password' => 'hunter2',
            'nested' => ['api_key' => 'abc', 'safe' => 'value'],
        ]);

        $this->assertSame('[REDACTED]', $log->new_values['password']);
        $this->assertSame('[REDACTED]', $log->new_values['nested']['api_key']);
        $this->assertSame('value', $log->new_values['nested']['safe']);
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = app(AuditService::class)->log('test.immutable');

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_log_delete_is_blocked(): void
    {
        $log = app(AuditService::class)->log('test.immutable.delete');

        try {
            $log->delete();
            $this->fail('Deleting an audit log should throw.');
        } catch (LogicException) {
            $this->assertTrue(AuditLog::whereKey($log->id)->exists());
        }
    }
}