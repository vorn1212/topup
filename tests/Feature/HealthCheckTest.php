<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeatJob;
use App\Models\User;
use App\Services\Ops\HealthCheck;
use App\Services\TopUp\ProviderBalanceMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use MakesCatalog, MakesOrders, RefreshDatabase;

    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpShop();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /** @return array{id:string,group:string,name:string,level:string,message:string} */
    private function check(string $id): array
    {
        foreach (app(HealthCheck::class)->run() as $result) {
            if ($result['id'] === $id) {
                return $result;
            }
        }

        $this->fail("No health check called {$id}.");
    }

    private function level(string $id): string
    {
        return $this->check($id)['level'];
    }

    private function production(): void
    {
        config(['app.env' => 'production']);
    }

    private function beat(?string $key = null, ?int $at = null): void
    {
        Cache::put($key ?? HealthCheck::SCHEDULER_KEY, $at ?? now()->getTimestamp(), now()->addHour());
    }

    private function allBeats(): void
    {
        $this->beat();
        $this->beat(QueueHeartbeatJob::key('default'));
        $this->beat(QueueHeartbeatJob::key((string) config('payway_link.queue', 'payway')));
    }

    // ---------------------------------------------------------------- structure

    public function test_every_check_returns_a_known_level_and_a_message(): void
    {
        $results = app(HealthCheck::class)->run();

        $this->assertGreaterThanOrEqual(25, count($results));

        $ids = array_column($results, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'check ids must be unique');

        foreach ($results as $result) {
            $this->assertContains($result['level'], [HealthCheck::PASS, HealthCheck::WARN, HealthCheck::FAIL]);
            $this->assertNotSame('', trim($result['message']));
            $this->assertNotSame('', $result['group']);
        }
    }

    public function test_the_summary_counts_levels_and_picks_the_worst_one(): void
    {
        $health = app(HealthCheck::class);
        $row = fn (string $level) => ['id' => 'x', 'group' => 'g', 'name' => 'n', 'level' => $level, 'message' => 'm'];

        $this->assertSame(
            ['pass' => 2, 'warn' => 0, 'fail' => 0, 'overall' => 'pass'],
            $health->summary([$row('pass'), $row('pass')])
        );
        $this->assertSame('warn', $health->summary([$row('pass'), $row('warn')])['overall']);
        $this->assertSame('fail', $health->summary([$row('warn'), $row('fail'), $row('pass')])['overall']);
    }

    public function test_a_check_that_crashes_is_reported_without_showing_the_error_text(): void
    {
        $this->mock(ProviderBalanceMonitor::class, function ($mock) {
            $mock->shouldReceive('enabled')->andThrow(new \RuntimeException('password=hunter2'));
        });

        $result = $this->check('provider_balance');

        $this->assertSame(HealthCheck::FAIL, $result['level']);
        $this->assertStringNotContainsString('hunter2', $result['message']);
    }

    // ---------------------------------------------------------------- production mistakes

    public function test_the_same_mistake_is_a_warning_on_your_computer_and_a_failure_in_production(): void
    {
        config(['app.debug' => true, 'queue.default' => 'sync', 'fazercards.fake' => true]);

        $this->assertSame(HealthCheck::WARN, $this->level('app_debug'));
        $this->assertSame(HealthCheck::WARN, $this->level('queue_driver'));
        $this->assertSame(HealthCheck::WARN, $this->level('provider_mode'));
        $this->assertSame(HealthCheck::WARN, $this->level('scheduler'));

        $this->production();

        $this->assertSame(HealthCheck::FAIL, $this->level('app_debug'));
        $this->assertSame(HealthCheck::FAIL, $this->level('queue_driver'));
        $this->assertSame(HealthCheck::FAIL, $this->level('provider_mode'));
        $this->assertSame(HealthCheck::FAIL, $this->level('scheduler'));
    }

    public function test_good_settings_pass(): void
    {
        config(['app.debug' => false, 'queue.default' => 'database', 'fazercards.fake' => false, 'fazercards.api_key' => 'k']);
        $this->production();

        $this->assertSame(HealthCheck::PASS, $this->level('app_debug'));
        $this->assertSame(HealthCheck::PASS, $this->level('queue_driver'));
        $this->assertSame(HealthCheck::PASS, $this->level('provider_mode'));
        $this->assertSame(HealthCheck::PASS, $this->level('provider_key'));
        $this->assertSame(HealthCheck::PASS, $this->level('environment'));
    }

    public function test_the_app_url_must_be_a_real_https_address_in_production(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->assertSame(HealthCheck::PASS, $this->level('app_url'));

        $this->production();
        $this->assertSame(HealthCheck::FAIL, $this->level('app_url'));

        config(['app.url' => 'http://shop.example.com']);
        $this->assertSame(HealthCheck::FAIL, $this->level('app_url'));

        config(['app.url' => 'https://shop.example.com']);
        $this->assertSame(HealthCheck::PASS, $this->level('app_url'));
    }

    public function test_session_cookie_settings_are_enforced_in_production(): void
    {
        $this->production();

        config(['session.secure' => false, 'session.http_only' => true, 'session.same_site' => 'lax']);
        $this->assertSame(HealthCheck::FAIL, $this->level('session'));

        config(['session.secure' => true, 'session.http_only' => false]);
        $this->assertSame(HealthCheck::FAIL, $this->level('session'));

        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'none']);
        $this->assertSame(HealthCheck::FAIL, $this->level('session'));

        config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        $this->assertSame(HealthCheck::PASS, $this->level('session'));
    }

    public function test_the_seeder_password_must_not_be_left_in_production(): void
    {
        config(['topup.seed.password' => 'ChangeMe123!']);
        $this->assertSame(HealthCheck::PASS, $this->level('seed_password'));

        $this->production();
        $result = $this->check('seed_password');
        $this->assertSame(HealthCheck::WARN, $result['level']);
        $this->assertStringNotContainsString('ChangeMe123!', $result['message']);
    }

    public function test_demo_accounts_fail_in_production_only(): void
    {
        $this->staff('super_admin');
        User::factory()->create(['email' => 'admin@topup.test']);

        $this->assertSame(HealthCheck::PASS, $this->level('staff'));

        $this->production();
        $this->assertSame(HealthCheck::FAIL, $this->level('staff'));
    }

    public function test_a_shop_without_an_active_super_admin_is_flagged(): void
    {
        $this->production();
        $this->assertSame(HealthCheck::FAIL, $this->level('staff'));

        $this->staff('super_admin');
        $this->assertSame(HealthCheck::PASS, $this->level('staff'));
    }

    public function test_sensitive_files_in_the_public_folder_fail(): void
    {
        $this->assertSame(HealthCheck::PASS, $this->level('public_folder'));

        $file = public_path('.env');
        File::put($file, 'x');

        try {
            $this->assertSame(HealthCheck::FAIL, $this->level('public_folder'));
            $this->assertStringContainsString('.env', $this->check('public_folder')['message']);
        } finally {
            File::delete($file);
        }
    }

    // ---------------------------------------------------------------- scheduler and queue

    public function test_no_heartbeat_means_the_scheduler_and_worker_are_not_running(): void
    {
        $this->assertSame(HealthCheck::WARN, $this->level('scheduler'));
        $this->assertSame(HealthCheck::WARN, $this->level('workers'));

        $this->production();
        $this->assertSame(HealthCheck::FAIL, $this->level('scheduler'));
        $this->assertSame(HealthCheck::FAIL, $this->level('workers'));
    }

    public function test_fresh_heartbeats_pass(): void
    {
        $this->production();
        $this->allBeats();

        $this->assertSame(HealthCheck::PASS, $this->level('scheduler'));
        $this->assertSame(HealthCheck::PASS, $this->level('workers'));
    }

    public function test_old_heartbeats_fail_in_production(): void
    {
        $this->production();
        $old = now()->subMinutes(10)->getTimestamp();

        $this->beat(HealthCheck::SCHEDULER_KEY, $old);
        $this->assertSame(HealthCheck::FAIL, $this->level('scheduler'));

        $this->allBeats();
        $this->beat(QueueHeartbeatJob::key('default'), $old);
        $this->assertSame(HealthCheck::FAIL, $this->level('workers'));
    }

    public function test_a_worker_that_ignores_the_payway_queue_is_detected(): void
    {
        $this->production();
        $this->beat();
        $this->beat(QueueHeartbeatJob::key('default'));

        $result = $this->check('workers');

        $this->assertSame(HealthCheck::FAIL, $result['level']);
        $this->assertStringContainsString((string) config('payway_link.queue', 'payway'), $result['message']);
    }

    public function test_the_heartbeat_job_leaves_a_timestamp_for_its_queue(): void
    {
        $this->assertNull(Cache::get(QueueHeartbeatJob::key('payway')));

        (new QueueHeartbeatJob('payway'))->handle();

        $this->assertEqualsWithDelta(now()->getTimestamp(), (int) Cache::get(QueueHeartbeatJob::key('payway')), 5);
        $this->assertNull(Cache::get(QueueHeartbeatJob::key('default')));
    }

    public function test_the_scheduler_runs_the_heartbeat_every_minute(): void
    {
        Artisan::call('schedule:list');

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'ops-heartbeat');

        $this->assertNotNull($event, 'routes/console.php must schedule the "ops-heartbeat" task');
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_failed_jobs_raise_a_warning(): void
    {
        $this->assertSame(HealthCheck::PASS, $this->level('failed_jobs'));

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->assertSame(HealthCheck::WARN, $this->level('failed_jobs'));
    }

    // ---------------------------------------------------------------- provider

    public function test_provider_balance_states(): void
    {
        $monitor = fn (?array $snapshot, bool $low) => $this->mock(ProviderBalanceMonitor::class, function ($mock) use ($snapshot, $low) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('snapshot')->andReturn($snapshot);
            $mock->shouldReceive('isLow')->andReturn($low);
        });

        $fresh = ['balance' => '50.0000', 'currency' => 'USD', 'checked_at' => now()->getTimestamp()];

        $monitor(null, false);
        $this->assertSame(HealthCheck::WARN, $this->level('provider_balance'));

        $monitor($fresh, false);
        $this->assertSame(HealthCheck::PASS, $this->level('provider_balance'));

        $monitor($fresh, true);
        $this->assertSame(HealthCheck::WARN, $this->level('provider_balance'));

        $monitor(['checked_at' => now()->subHours(3)->getTimestamp()] + $fresh, false);
        $this->assertSame(HealthCheck::WARN, $this->level('provider_balance'));
    }

    public function test_orders_waiting_for_a_decision_raise_a_warning(): void
    {
        $this->assertSame(HealthCheck::PASS, $this->level('orders'));

        $this->reviewOrder();

        $this->assertSame(HealthCheck::WARN, $this->level('orders'));
    }

    // ---------------------------------------------------------------- payments

    public function test_payway_worker_checks(): void
    {
        config(['payway_link.url' => '']);
        $this->assertSame(HealthCheck::WARN, $this->level('payway_url'));

        config(['payway_link.url' => 'https://link.payway.example/pay-secret-777']);
        $result = $this->check('payway_url');
        $this->assertSame(HealthCheck::PASS, $result['level']);
        $this->assertStringNotContainsString('pay-secret-777', $result['message']);

        // Worker script missing.
        config(['payway_link.worker_path' => base_path('storage/does-not-exist/worker.js')]);
        $this->assertSame(HealthCheck::WARN, $this->level('payway_worker'));

        // Script there, but Playwright is not installed.
        $this->tempDir = storage_path('framework/testing-health-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
        File::put($this->tempDir.'/worker.js', '// worker');
        config(['payway_link.worker_path' => $this->tempDir.'/worker.js']);
        $this->assertSame(HealthCheck::WARN, $this->level('payway_worker'));

        // Playwright installed, Node answers.
        File::ensureDirectoryExists($this->tempDir.'/node_modules/playwright');
        Process::fake(['*' => Process::result(output: "v20.11.0\n")]);
        $result = $this->check('payway_worker');
        $this->assertSame(HealthCheck::PASS, $result['level']);
        $this->assertStringContainsString('v20.11.0', $result['message']);

        // Node cannot start.
        Process::fake(['*' => Process::result(errorOutput: 'not found', exitCode: 127)]);
        $this->assertSame(HealthCheck::WARN, $this->level('payway_worker'));

        $this->production();
        $this->assertSame(HealthCheck::FAIL, $this->level('payway_worker'));
    }

    // ---------------------------------------------------------------- money

    public function test_a_clean_ledger_passes(): void
    {
        $this->assertSame(HealthCheck::PASS, $this->level('ledger'));
    }

    public function test_a_wallet_that_does_not_match_its_ledger_fails(): void
    {
        DB::table('wallets')->where('user_id', $this->customer->id)->update(['balance' => '999.0000']);

        $result = $this->check('ledger');

        $this->assertSame(HealthCheck::FAIL, $result['level']);
        $this->assertStringContainsString('1 wallet', $result['message']);
    }

    public function test_a_ledger_row_that_does_not_add_up_fails(): void
    {
        DB::table('wallet_transactions')->where('user_id', $this->customer->id)->update(['balance_after' => '1.0000']);

        $this->assertSame(HealthCheck::FAIL, $this->level('ledger'));
    }

    public function test_the_database_money_guards_are_present(): void
    {
        $this->assertNotSame(HealthCheck::FAIL, $this->level('db_guards'));
        $this->assertSame(HealthCheck::PASS, $this->level('migrations'));
        $this->assertSame(HealthCheck::PASS, $this->level('database'));
    }

    // ---------------------------------------------------------------- command

    public function test_ops_check_succeeds_when_nothing_fails(): void
    {
        $this->artisan('ops:check')->assertExitCode(0);
    }

    public function test_ops_check_strict_also_fails_on_warnings(): void
    {
        // APP_ENV is "testing" here, which is always a warning.
        $this->artisan('ops:check', ['--strict' => true])->assertExitCode(1);
    }

    public function test_ops_check_fails_on_a_production_mistake(): void
    {
        $this->production();
        config(['app.debug' => true]);

        $this->artisan('ops:check')->assertExitCode(1);
    }

    public function test_ops_check_can_list_only_problems(): void
    {
        $this->artisan('ops:check', ['--problems' => true])
            ->doesntExpectOutputToContain('PASS')
            ->assertExitCode(0);
    }

    public function test_ops_check_never_prints_secrets(): void
    {
        config([
            'fazercards.api_key' => 'fz-secret-key-424242',
            'fazercards.webhook_secret' => 'whsec-424242',
            'payway_link.url' => 'https://link.payway.example/pay-secret-424242',
        ]);

        $this->artisan('ops:check')
            ->doesntExpectOutputToContain('fz-secret-key-424242')
            ->doesntExpectOutputToContain('whsec-424242')
            ->doesntExpectOutputToContain('pay-secret-424242')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------------- the admin page

    public function test_only_the_super_admin_can_open_the_health_page(): void
    {
        $this->get('/admin/health')->assertRedirect('/login');

        $this->actingAs($this->customer)->get('/admin/health')->assertForbidden();
        $this->actingAs($this->staff('support'))->get('/admin/health')->assertForbidden();
        $this->actingAs($this->staff('admin'))->get('/admin/health')->assertForbidden();

        $this->actingAs($this->staff('super_admin'))->get('/admin/health')
            ->assertOk()
            ->assertSee('php artisan ops:check')
            ->assertSee('Queue worker')
            ->assertSee('Wallets and ledger');
    }

    public function test_the_health_page_shows_no_secrets(): void
    {
        config([
            'fazercards.api_key' => 'fz-secret-key-424242',
            'fazercards.webhook_secret' => 'whsec-424242',
            'payway_link.url' => 'https://link.payway.example/pay-secret-424242',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ]);

        $this->actingAs($this->staff('super_admin'))->get('/admin/health')
            ->assertOk()
            ->assertDontSee('fz-secret-key-424242')
            ->assertDontSee('whsec-424242')
            ->assertDontSee('pay-secret-424242')
            ->assertDontSee(config('app.key'), false);
    }

    public function test_the_health_page_is_read_only(): void
    {
        $super = $this->staff('super_admin');

        $this->actingAs($super)->post('/admin/health')->assertStatus(405);
        $this->actingAs($super)->delete('/admin/health')->assertStatus(405);
    }
}