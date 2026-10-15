<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\WalletFrozenException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Admin\UserAdminService;
use App\Services\TopUp\OrderService;
use App\Services\Wallet\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop(); // $this->customer has 20.00
    }

    // ---------------------------------------------------------------- access

    public function test_guests_and_customers_are_kept_out(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
        $this->actingAs($this->customer)->get('/admin/users')->assertForbidden();
        $this->actingAs($this->customer)->get('/admin/users/'.$this->customer->id)->assertForbidden();
        $this->actingAs($this->customer)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'banned', 'reason' => 'trying it'])->assertForbidden();
    }

    public function test_support_can_look_but_not_change_anything(): void
    {
        $support = $this->staff('support');

        $this->actingAs($support)->get('/admin/users')->assertOk()->assertSee($this->customer->email);
        $this->actingAs($support)->get('/admin/users/'.$this->customer->id)->assertOk()->assertDontSee('Change status');

        $this->actingAs($support)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'suspended', 'reason' => 'Support must not'])->assertForbidden();
        $this->actingAs($support)->post('/admin/users/'.$this->customer->id.'/freeze', ['frozen' => '1', 'reason' => 'Support must not'])->assertForbidden();
        $this->assertSame(UserStatus::Active, $this->customer->fresh()->status);
    }

    // ---------------------------------------------------------------- list

    public function test_the_list_searches_and_filters(): void
    {
        $other = User::factory()->create(['name' => 'Zed Zebra', 'email' => 'zed@example.test']);
        $banned = User::factory()->banned()->create(['name' => 'Bad Bob', 'email' => 'bob@example.test']);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get('/admin/users?q=zebra')->assertOk()->assertSee('zed@example.test')->assertDontSee('bob@example.test');
        $this->actingAs($admin)->get('/admin/users?status=banned')->assertOk()->assertSee('bob@example.test')->assertDontSee('zed@example.test');
        $this->actingAs($admin)->get('/admin/users?role=admin')->assertOk()->assertSee($admin->email)->assertDontSee('zed@example.test');
    }

    public function test_search_wildcards_are_literal(): void
    {
        User::factory()->create(['email' => 'plain@example.test']);

        $this->actingAs($this->staff('admin'))->get('/admin/users?q=%25')->assertOk()->assertDontSee('plain@example.test');
    }

    public function test_the_detail_page_shows_money_and_history(): void
    {
        $this->placeOrder();

        $this->actingAs($this->staff('admin'))->get('/admin/users/'.$this->customer->id)
            ->assertOk()
            ->assertSee($this->customer->email)
            ->assertSee('$19.00')
            ->assertSee('Wallet ledger')
            ->assertSee('Purchase');
    }

    // ---------------------------------------------------------------- status

    public function test_an_admin_can_suspend_and_reactivate_a_customer(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'suspended', 'reason' => 'Chargeback dispute'])
            ->assertRedirect('/admin/users/'.$this->customer->id)
            ->assertSessionHas('status');

        $this->assertSame(UserStatus::Suspended, $this->customer->fresh()->status);

        $log = AuditLog::where('action', 'user.status_changed')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('suspended', $log->new_values['status']);
        $this->assertSame('Chargeback dispute', $log->new_values['reason']);

        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'active', 'reason' => 'Dispute settled'])->assertSessionHas('status');
        $this->assertSame(UserStatus::Active, $this->customer->fresh()->status);
    }

    public function test_a_status_change_needs_a_reason_and_a_real_change(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'banned', 'reason' => 'no'])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'nonsense', 'reason' => 'Valid reason'])->assertSessionHasErrors('status');
        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'active', 'reason' => 'Already active'])->assertSessionHas('error');

        $this->assertSame(UserStatus::Active, $this->customer->fresh()->status);
    }

    public function test_a_suspended_customer_cannot_order(): void
    {
        $this->actingAs($this->staff('admin'))->post('/admin/users/'.$this->customer->id.'/status', ['status' => 'suspended', 'reason' => 'Under review']);

        $this->expectException(\App\Exceptions\OrderRejectedException::class);
        app(OrderService::class)->create($this->customer->fresh(), 'pubg-mobile', $this->product->id, ['player_id' => '123456789']);
    }

    // ---------------------------------------------------------------- rank rules

    public function test_nobody_can_manage_themselves_or_an_equal_or_higher_rank(): void
    {
        $service = app(UserAdminService::class);
        $support = $this->staff('support');
        $admin = $this->staff('admin');
        $otherAdmin = $this->staff('admin');
        $super = $this->staff('super_admin');
        $otherSuper = $this->staff('super_admin');

        $this->assertTrue($service->canManage($admin, $this->customer));
        $this->assertTrue($service->canManage($admin, $support));
        $this->assertTrue($service->canManage($super, $admin));

        $this->assertFalse($service->canManage($admin, $admin));
        $this->assertFalse($service->canManage($admin, $otherAdmin));
        $this->assertFalse($service->canManage($admin, $super));
        $this->assertFalse($service->canManage($super, $otherSuper));
        
    }

    public function test_an_admin_cannot_suspend_another_admin_or_a_super_admin(): void
    {
        $admin = $this->staff('admin');
        $otherAdmin = $this->staff('admin');
        $super = $this->staff('super_admin');

        foreach ([$otherAdmin, $super, $admin] as $target) {
            $this->actingAs($admin)->post('/admin/users/'.$target->id.'/status', ['status' => 'banned', 'reason' => 'Not allowed to'])
                ->assertRedirect()
                ->assertSessionHas('error');
            $this->assertSame(UserStatus::Active, $target->fresh()->status);
        }
    }

    public function test_an_inactive_staff_account_has_no_power(): void
    {
        $admin = $this->staff('admin');
        $admin->forceFill(['status' => 'suspended'])->save();

        $this->assertFalse(app(UserAdminService::class)->canManage($admin->fresh(), $this->customer));
        $this->actingAs($admin->fresh())->get('/admin/users')->assertForbidden();
    }

    // ---------------------------------------------------------------- role

    public function test_only_a_super_admin_can_change_roles(): void
    {
        $admin = $this->staff('admin');
        $super = $this->staff('super_admin');

        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/role', ['role' => 'support', 'reason' => 'Promote them'])->assertForbidden();
        $this->assertSame(UserRole::User, $this->customer->fresh()->role);

        $this->actingAs($super)->post('/admin/users/'.$this->customer->id.'/role', ['role' => 'support', 'reason' => 'Joins the support team'])
            ->assertSessionHas('status');

        $this->assertSame(UserRole::Support, $this->customer->fresh()->role);
        $this->assertSame('support', AuditLog::where('action', 'user.role_changed')->firstOrFail()->new_values['role']);
    }

    public function test_nobody_can_create_a_super_admin_through_the_website(): void
    {
        $super = $this->staff('super_admin');

        $this->actingAs($super)->post('/admin/users/'.$this->customer->id.'/role', ['role' => 'super_admin', 'reason' => 'Make them boss'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(UserRole::User, $this->customer->fresh()->role);
    }

    public function test_another_super_admin_cannot_be_demoted_here(): void
    {
        $super = $this->staff('super_admin');
        $otherSuper = $this->staff('super_admin');

        $this->actingAs($super)->post('/admin/users/'.$otherSuper->id.'/role', ['role' => 'user', 'reason' => 'Demote the boss'])->assertSessionHas('error');

        $this->assertSame(UserRole::SuperAdmin, $otherSuper->fresh()->role);
    }

    public function test_the_service_itself_refuses_without_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        app(UserAdminService::class)->setStatus($this->customer, UserStatus::Banned, $this->staff('support'), 'Support has no power');
    }

    // ---------------------------------------------------------------- wallet freeze

    public function test_a_frozen_wallet_cannot_pay_but_can_receive_and_be_adjusted(): void
    {
        $admin = $this->staff('admin');
        $super = $this->staff('super_admin');

        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/freeze', ['frozen' => '1', 'reason' => 'Suspicious activity'])
            ->assertSessionHas('status');

        try {
            $this->placeOrder();
            $this->fail('A frozen wallet must not pay for orders.');
        } catch (WalletFrozenException) {
            $this->assertSame('20.0000', $this->customerBalance());
        }

        $this->actingAs($super)->post('/admin/users/'.$this->customer->id.'/adjust', [
            'direction' => 'credit', 'amount' => '1.00', 'reason' => 'Goodwill credit', 'token' => 'frozen-wallet-token-1',
        ])->assertSessionHas('status');
        $this->assertSame('21.0000', $this->customerBalance());

        $this->actingAs($admin)->post('/admin/users/'.$this->customer->id.'/freeze', ['frozen' => '0', 'reason' => 'Checked, all fine'])->assertSessionHas('status');
        $this->placeOrder();
        $this->assertSame('20.0000', $this->customerBalance());
    }

    // ---------------------------------------------------------------- adjustments

    private function adjust(User $by, array $data, ?User $for = null)
    {
        return $this->actingAs($by)->post('/admin/users/'.($for ?? $this->customer)->id.'/adjust', $data + [
            'direction' => 'credit', 'amount' => '5.00', 'reason' => 'Manual correction', 'token' => 'adjust-token-0000001',
        ]);
    }

    public function test_a_super_admin_can_credit_and_debit_with_a_ledger_entry(): void
    {
        $super = $this->staff('super_admin');

        $this->adjust($super, ['amount' => '5.50'])->assertRedirect('/admin/users/'.$this->customer->id)->assertSessionHas('status');
        $this->assertSame('25.5000', $this->customerBalance());

        $this->adjust($super, ['direction' => 'debit', 'amount' => '2.25', 'token' => 'adjust-token-0000002', 'reason' => 'Duplicate bonus removed'])->assertSessionHas('status');
        $this->assertSame('23.2500', $this->customerBalance());

        $tx = WalletTransaction::where('type', 'adjustment')->latest('id')->firstOrFail();
        $this->assertSame('debit', $tx->direction);
        $this->assertSame($super->id, $tx->meta['admin_id']);
        $this->assertSame('Duplicate bonus removed', $tx->meta['reason']);
    }

    public function test_the_same_form_submitted_twice_adjusts_once(): void
    {
        $super = $this->staff('super_admin');

        $this->adjust($super, ['amount' => '3.00'])->assertSessionHas('status');
        $this->adjust($super, ['amount' => '3.00'])->assertSessionHas('status');

        $this->assertSame('23.0000', $this->customerBalance());
        $this->assertSame(1, WalletTransaction::where('type', 'adjustment')->count());
    }

    public function test_an_admin_without_the_wallet_permission_is_refused(): void
    {
        $this->adjust($this->staff('admin'), [])->assertForbidden();
        $this->adjust($this->staff('support'), [])->assertForbidden();

        $this->assertSame('20.0000', $this->customerBalance());
    }

    public function test_a_debit_cannot_overdraw_the_wallet(): void
    {
        $this->adjust($this->staff('super_admin'), ['direction' => 'debit', 'amount' => '500.00'])->assertSessionHas('error');

        $this->assertSame('20.0000', $this->customerBalance());
    }

    public function test_bad_adjustments_are_refused(): void
    {
        $super = $this->staff('super_admin');

        $this->adjust($super, ['amount' => '-5'])->assertSessionHasErrors('amount');
        $this->adjust($super, ['amount' => '1.23456'])->assertSessionHasErrors('amount');
        $this->adjust($super, ['amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->adjust($super, ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->adjust($super, ['direction' => 'sideways'])->assertSessionHasErrors('direction');
        $this->adjust($super, ['token' => 'short'])->assertSessionHasErrors('token');
        $this->adjust($super, ['amount' => '0.00'])->assertSessionHas('error');
        $this->adjust($super, ['amount' => '10000.01'])->assertSessionHas('error');

        $this->assertSame('20.0000', $this->customerBalance());
    }

    public function test_nobody_can_adjust_their_own_wallet(): void
    {
        $super = $this->staff('super_admin');

        $this->adjust($super, ['amount' => '100.00'], $super)->assertSessionHas('error');

        $this->assertSame('0.0000', app(WalletService::class)->getBalance($super));
    }

    public function test_the_adjust_form_is_shown_only_to_those_who_may_use_it(): void
    {
        $this->actingAs($this->staff('super_admin'))->get('/admin/users/'.$this->customer->id)->assertOk()->assertSee('Adjust balance')->assertSee('Change role');
        $this->actingAs($this->staff('admin'))->get('/admin/users/'.$this->customer->id)->assertOk()->assertDontSee('Adjust balance')->assertDontSee('Change role')->assertSee('Change status');
    }
}