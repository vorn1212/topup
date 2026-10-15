<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
    }

    private function log(string $action, ?User $actor = null, array $old = [], array $new = [], ?Model $subject = null): AuditLog
    {
        return app(AuditService::class)->log($action, $subject, $old, $new, $actor);
    }

    /** @return list<string> the actions shown on the list page, newest first */
    private function listed(string $query): array
    {
        $response = $this->actingAs($this->staff('admin'))->get('/admin/audit?'.$query)->assertOk();

        return $response->viewData('logs')->getCollection()->pluck('action')->all();
    }

    // ---------------------------------------------------------------- access

    public function test_only_staff_with_the_audit_permission_can_open_the_log(): void
    {
        $entry = $this->log('test.alpha');
        $urls = ['/admin/audit', '/admin/audit/'.$entry->id];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        foreach ($urls as $url) {
            $this->actingAs($this->customer)->get($url)->assertForbidden();
            $this->actingAs($this->staff('support'))->get($url)->assertForbidden();
        }

        foreach ($urls as $url) {
            $this->actingAs($this->staff('admin'))->get($url)->assertOk();
            $this->actingAs($this->staff('super_admin'))->get($url)->assertOk();
        }
    }

    public function test_the_log_is_read_only(): void
    {
        $entry = $this->log('test.alpha');
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post('/admin/audit')->assertStatus(405);
        $this->actingAs($admin)->delete('/admin/audit/'.$entry->id)->assertStatus(405);
        $this->actingAs($admin)->put('/admin/audit/'.$entry->id)->assertStatus(405);
    }

    // ---------------------------------------------------------------- list and filters

    public function test_the_list_shows_the_newest_entries_first(): void
    {
        $admin = $this->staff('admin');
        $this->log('test.first', $admin);
        $this->log('test.second', $admin);

        $this->assertSame(['test.second', 'test.first'], $this->listed('q=test.'));

        $this->actingAs($admin)->get('/admin/audit?q=test.')
            ->assertOk()
            ->assertSee('test.first')
            ->assertSee('test.second')
            ->assertSee($admin->email);
    }

    public function test_entries_can_be_filtered_by_action_actor_and_text(): void
    {
        $alice = $this->staff('admin');
        $bob = $this->staff('admin');
        $this->log('test.alpha', $alice);
        $this->log('test.beta', $bob);
        $this->log('test.system');

        $this->assertEqualsCanonicalizing(['test.alpha', 'test.beta', 'test.system'], $this->listed('q=test.'));
        $this->assertSame(['test.alpha'], $this->listed('action=test.alpha'));
        $this->assertSame(['test.system'], $this->listed('q=test.&actor_type=system'));
        $this->assertEqualsCanonicalizing(['test.alpha', 'test.beta'], $this->listed('q=test.&actor_type=staff'));
        $this->assertSame(['test.alpha'], $this->listed('q='.urlencode($alice->email)));
        $this->assertSame(['test.beta'], $this->listed('q='.urlencode($bob->email)));
    }

    public function test_the_date_filter_works(): void
    {
        $this->log('test.today');
        AuditLog::create([
            'actor_type' => 'system',
            'action' => 'test.old',
            'created_at' => now()->subDays(10),
        ]);

        $today = now()->format('Y-m-d');
        $fiveDaysAgo = now()->subDays(5)->format('Y-m-d');

        $this->assertSame(['test.today'], $this->listed("q=test.&from={$today}"));
        $this->assertSame(['test.old'], $this->listed("q=test.&to={$fiveDaysAgo}"));
        $this->assertEqualsCanonicalizing(['test.today', 'test.old'], $this->listed('q=test.'));
    }

    public function test_wildcards_in_the_search_are_taken_literally(): void
    {
        $this->log('test.alpha');
        $this->log('test.beta');

        $this->assertSame([], $this->listed('q=%25'));
        $this->assertSame([], $this->listed('q=_est.alpha'));
    }

    public function test_bad_filter_input_never_breaks_the_page(): void
    {
        $admin = $this->staff('admin');
        $this->log('test.alpha');

        $this->actingAs($admin)->get('/admin/audit?from=zzz&to=2026-13-45&actor_type=bogus&action[]=x&q[]=y')->assertOk();
        $this->actingAs($admin)->get('/admin/audit?q='.str_repeat('a', 5000))->assertOk();
        $this->actingAs($admin)->get('/admin/audit?page=999999')->assertOk();
    }

    public function test_real_actions_of_the_shop_appear_in_the_log(): void
    {
        $po = $this->placeOrder();
        $this->moveOrder($po, OrderStatus::Processing);

        $actions = $this->listed('q=order.');

        $this->assertContains('order.status_changed', $actions);
    }

    // ---------------------------------------------------------------- details

    public function test_the_detail_page_shows_before_and_after(): void
    {
        $admin = $this->staff('admin');
        $entry = $this->log('test.change', $admin, ['name' => 'Old name'], ['name' => 'New name']);

        $this->actingAs($admin)->get('/admin/audit/'.$entry->id)
            ->assertOk()
            ->assertSee('test.change')
            ->assertSee($admin->email)
            ->assertSee('Old name')
            ->assertSee('New name');
    }

    public function test_secrets_never_show_up(): void
    {
        $admin = $this->staff('admin');
        $entry = $this->log('test.secret', $admin, ['password' => 'hunter2'], ['api_key' => 'sk-live-123', 'note' => 'ok']);

        $this->actingAs($admin)->get('/admin/audit/'.$entry->id)
            ->assertOk()
            ->assertSee('[REDACTED]')
            ->assertDontSee('hunter2')
            ->assertDontSee('sk-live-123');
    }

    public function test_stored_text_is_escaped_on_the_page(): void
    {
        $admin = $this->staff('admin');
        $entry = $this->log('test.xss', $admin, [], ['note' => '<script>alert(1)</script>']);

        $this->actingAs($admin)->get('/admin/audit/'.$entry->id)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_the_detail_page_links_to_the_order_or_user_it_is_about(): void
    {
        $admin = $this->staff('admin');
        $order = Order::findOrFail($this->placeOrder()->order_id);

        $about = $this->log('test.order', $admin, [], [], $order);
        $this->actingAs($admin)->get('/admin/audit/'.$about->id)->assertOk()->assertSee(route('admin.orders.show', $order->id));

        $aboutUser = $this->log('test.user', $admin, [], [], $this->customer);
        $this->actingAs($admin)->get('/admin/audit/'.$aboutUser->id)->assertOk()->assertSee(route('admin.users.show', $this->customer->id));

        $none = $this->log('test.nothing', $admin);
        $this->actingAs($admin)->get('/admin/audit/'.$none->id)->assertOk();
    }

    public function test_unknown_entries_are_not_found(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get('/admin/audit/999999')->assertNotFound();
        $this->actingAs($admin)->get('/admin/audit/abc')->assertNotFound();
    }
}