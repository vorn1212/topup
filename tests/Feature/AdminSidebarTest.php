<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSidebarTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role->value])->save();

        return $user;
    }

    public function test_the_admin_menu_is_a_sidebar_with_every_link_for_the_super_admin(): void
    {
        $response = $this->actingAs($this->userWithRole(UserRole::SuperAdmin))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('admin-sidebar', false);

        foreach ([
            'admin.dashboard', 'admin.attention.index', 'admin.games.index', 'admin.products.index', 'admin.provider.index',
            'admin.orders.index', 'admin.deposits.index', 'admin.transactions.index', 'admin.users.index',
            'admin.reports.index', 'admin.audit.index', 'admin.health.index', 'admin.settings.edit',
        ] as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }
    }

    public function test_the_current_page_is_marked_in_the_sidebar(): void
    {
        $this->actingAs($this->userWithRole(UserRole::SuperAdmin))
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    public function test_links_a_role_may_not_use_are_not_shown(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('admin-sidebar', false)
            ->assertDontSee('href="'.route('admin.settings.edit').'"', false)
            ->assertDontSee('href="'.route('admin.health.index').'"', false);
    }

    public function test_customers_cannot_see_the_admin_area_at_all(): void
    {
        $this->actingAs($this->userWithRole(UserRole::User))
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}