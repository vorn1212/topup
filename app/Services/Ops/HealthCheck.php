<?php

namespace App\Services\Ops;

use App\Enums\ProviderOrderStatus;
use App\Jobs\QueueHeartbeatJob;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\TopUp\ProviderBalanceMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Is the shop set up and running correctly?" Used by `php artisan ops:check` and by /admin/health.
 *
 *  - Every check is read-only and returns a level (pass / warn / fail) and a short message.
 *  - Messages never contain secrets (keys, passwords, the payment link) and never raw exception text.
 *  - Outside production a mistake is a WARNING (your computer is allowed to be messy);
 *    in production the same mistake is a FAILURE.
 */
final class HealthCheck
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** Seconds without a heartbeat before the scheduler or a queue counts as stopped. */
    public const HEARTBEAT_MAX_AGE = 300;

    public const SCHEDULER_KEY = 'ops:scheduler-heartbeat';

    /** Accounts created by the development seeder. They must not exist on a live server. */
    public const DEMO_EMAILS = ['superadmin@topup.test', 'admin@topup.test', 'support@topup.test', 'user@topup.test'];

    /** Database-level money guards created by the migrations. */
    public const DB_GUARDS = [
        'wallets_balance_non_negative',
        'wallet_transactions_amount_positive',
        'wallet_transactions_direction_valid',
        'wallet_deposits_amount_positive',
        'orders_amounts_valid',
    ];

    /**
     * @return list<array{id:string,group:string,name:string,level:string,message:string}>
     */
    public function run(): array
    {
        $checks = [
            ['app_key', 'Environment', 'APP_KEY', 'checkAppKey'],
            ['environment', 'Environment', 'APP_ENV', 'checkEnvironment'],
            ['app_debug', 'Environment', 'APP_DEBUG', 'checkDebug'],
            ['app_url', 'Environment', 'APP_URL', 'checkUrl'],
            ['caches', 'Environment', 'Config and route cache', 'checkCaches'],
            ['storage', 'Environment', 'Writable folders', 'checkStorage'],

            ['database', 'Database', 'Connection', 'checkDatabase'],
            ['migrations', 'Database', 'Migrations', 'checkMigrations'],
            ['db_guards', 'Database', 'Money guards in the database', 'checkDbGuards'],

            ['queue_driver', 'Queue and scheduler', 'Queue driver', 'checkQueueDriver'],
            ['scheduler', 'Queue and scheduler', 'Scheduler', 'checkScheduler'],
            ['workers', 'Queue and scheduler', 'Queue worker', 'checkWorkers'],
            ['failed_jobs', 'Queue and scheduler', 'Failed jobs', 'checkFailedJobs'],
            ['stuck_jobs', 'Queue and scheduler', 'Waiting jobs', 'checkStuckJobs'],

            ['provider_mode', 'Provider (FazerCards)', 'Fake mode', 'checkProviderMode'],
            ['provider_key', 'Provider (FazerCards)', 'API key', 'checkProviderKey'],
            ['provider_balance', 'Provider (FazerCards)', 'Provider balance', 'checkProviderBalance'],
            ['webhook', 'Provider (FazerCards)', 'Webhook', 'checkWebhook'],
            ['orders', 'Provider (FazerCards)', 'Orders needing attention', 'checkOrders'],

            ['payway_url', 'Deposits (ABA PayWay)', 'Payment link', 'checkPayWayUrl'],
            ['payway_worker', 'Deposits (ABA PayWay)', 'Payment worker', 'checkPayWayWorker'],

            ['session', 'Security', 'Session cookie', 'checkSessionCookie'],
            ['public_folder', 'Security', 'Public folder', 'checkPublicFolder'],
            ['seed_password', 'Security', 'Seeder password', 'checkSeedPassword'],
            ['staff', 'Security', 'Staff accounts', 'checkStaffAccounts'],
            ['mail', 'Security', 'E-mail notifications', 'checkMail'],

            ['ledger', 'Money', 'Wallets and ledger', 'checkLedger'],
        ];

        $results = [];

        foreach ($checks as [$id, $group, $name, $method]) {
            try {
                [$level, $message] = $this->{$method}();
            } catch (Throwable $e) {
                // The real error goes to the log; the screen never shows raw exception text (it can hold passwords).
                Log::warning('Health check could not run.', ['check' => $id, 'error' => $e->getMessage()]);

                [$level, $message] = [self::FAIL, 'This check could not run (see storage/logs/laravel.log).'];
            }

            $results[] = ['id' => $id, 'group' => $group, 'name' => $name, 'level' => $level, 'message' => $message];
        }

        return $results;
    }

    /**
     * @param  list<array{level:string}>  $results
     * @return array{pass:int,warn:int,fail:int,overall:string}
     */
    public function summary(array $results): array
    {
        $count = fn (string $level) => count(array_filter($results, fn (array $r) => $r['level'] === $level));

        $fail = $count(self::FAIL);
        $warn = $count(self::WARN);

        return [
            'pass' => $count(self::PASS),
            'warn' => $warn,
            'fail' => $fail,
            'overall' => $fail > 0 ? self::FAIL : ($warn > 0 ? self::WARN : self::PASS),
        ];
    }

    // ------------------------------------------------------------ environment

    /** @return array{0:string,1:string} */
    private function checkAppKey(): array
    {
        return filled(config('app.key'))
            ? [self::PASS, 'Set.']
            : [self::FAIL, 'APP_KEY is empty. Run: php artisan key:generate'];
    }

    /** @return array{0:string,1:string} */
    private function checkEnvironment(): array
    {
        return $this->production()
            ? [self::PASS, 'APP_ENV=production.']
            : [self::WARN, 'APP_ENV is "'.config('app.env').'". Fine on your own computer; the live server must use production.'];
    }

    /** @return array{0:string,1:string} */
    private function checkDebug(): array
    {
        return config('app.debug')
            ? [$this->bad(), 'APP_DEBUG is true: error pages show code, file paths and settings to visitors. Set APP_DEBUG=false.']
            : [self::PASS, 'Off.'];
    }

    /** @return array{0:string,1:string} */
    private function checkUrl(): array
    {
        $url = (string) config('app.url');

        if ($this->production()) {
            $local = str_contains($url, 'localhost') || str_contains($url, '127.0.0.1');

            if (! str_starts_with(strtolower($url), 'https://') || $local) {
                return [self::FAIL, 'APP_URL is "'.$url.'". On the live server it must be your real https:// address.'];
            }
        }

        return [self::PASS, $url !== '' ? $url : 'Not set.'];
    }

    /** @return array{0:string,1:string} */
    private function checkCaches(): array
    {
        if (! $this->production()) {
            return [self::PASS, 'Not needed outside production.'];
        }

        $missing = [];

        if (! app()->configurationIsCached()) {
            $missing[] = 'config (php artisan config:cache)';
        }

        if (! app()->routesAreCached()) {
            $missing[] = 'routes (php artisan route:cache)';
        }

        return $missing === []
            ? [self::PASS, 'Configuration and routes are cached.']
            : [self::WARN, 'Not cached yet: '.implode(', ', $missing).'. Caching makes every page faster.'];
    }

    /** @return array{0:string,1:string} */
    private function checkStorage(): array
    {
        $folders = [
            'storage/app' => storage_path('app'),
            'storage/framework' => storage_path('framework'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $bad = [];

        foreach ($folders as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        if ($bad !== []) {
            return [self::FAIL, 'Not writable: '.implode(', ', $bad).'. The web server user must be able to write there.'];
        }

        if (! is_link(public_path('storage')) && ! is_dir(public_path('storage')) && ! file_exists(public_path('storage'))) {
            return [self::WARN, 'public/storage is missing, so game logos will not show. Run: php artisan storage:link'];
        }

        return [self::PASS, 'All writable, and public/storage is linked.'];
    }

    // ------------------------------------------------------------ database

    /** @return array{0:string,1:string} */
    private function checkDatabase(): array
    {
        DB::select('select 1');

        return [self::PASS, 'Connected ('.DB::connection()->getDriverName().').'];
    }

    /** @return array{0:string,1:string} */
    private function checkMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return [self::FAIL, 'The database has no migrations table. Run: php artisan migrate'];
        }

        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $pending = array_diff(array_keys($files), $migrator->getRepository()->getRan());

        return $pending === []
            ? [self::PASS, count($files).' migrations, none pending.']
            : [self::FAIL, count($pending).' migration(s) have not run. Run: php artisan migrate'];
    }

    /** @return array{0:string,1:string} */
    private function checkDbGuards(): array
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [self::PASS, 'Skipped (database is '.$driver.').'];
        }

        $found = DB::table('information_schema.check_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->whereIn('constraint_name', self::DB_GUARDS)
            ->select('constraint_name as name')
            ->pluck('name')
            ->map(fn ($name) => strtolower((string) $name))
            ->all();

        $missing = array_values(array_diff(self::DB_GUARDS, $found));

        return $missing === []
            ? [self::PASS, 'All '.count(self::DB_GUARDS).' guards (no negative balance, no zero amounts) are active.']
            : [self::FAIL, 'Missing database guard(s): '.implode(', ', $missing).'. Re-run the migrations on a database that supports CHECK constraints (MariaDB 10.2+, MySQL 8.0.16+).'];
    }

    // ------------------------------------------------------------ queue and scheduler

    /** @return array{0:string,1:string} */
    private function checkQueueDriver(): array
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            return [$this->bad(), 'QUEUE_CONNECTION=sync runs jobs inside the web request, so a payment page would hang. Use QUEUE_CONNECTION=database.'];
        }

        return [self::PASS, 'Queue driver: '.$driver.'.'];
    }

    /** @return array{0:string,1:string} */
    private function checkScheduler(): array
    {
        $beat = $this->heartbeat(self::SCHEDULER_KEY);

        if ($beat === null) {
            return [$this->bad(), 'No sign that the scheduler runs. Make the server run "php artisan schedule:run" every minute (cron, or Windows Task Scheduler).'];
        }

        $age = $this->age($beat);

        return $age > self::HEARTBEAT_MAX_AGE
            ? [$this->bad(), 'The scheduler last ran '.$this->human($age).' ago. It must run every minute.']
            : [self::PASS, 'Ran '.$this->human($age).' ago.'];
    }

    /** @return array{0:string,1:string} */
    private function checkWorkers(): array
    {
        $scheduler = $this->heartbeat(self::SCHEDULER_KEY);

        if ($scheduler === null || $this->age($scheduler) > self::HEARTBEAT_MAX_AGE) {
            return [$this->bad(), 'Cannot tell: the scheduler is not running, so no test jobs are sent to the queue.'];
        }

        $queues = array_values(array_unique(['default', (string) config('payway_link.queue', 'payway')]));
        $stopped = [];

        foreach ($queues as $queue) {
            $beat = $this->heartbeat(QueueHeartbeatJob::key($queue));

            if ($beat === null || $this->age($beat) > self::HEARTBEAT_MAX_AGE) {
                $stopped[] = $queue;
            }
        }

        return $stopped === []
            ? [self::PASS, 'The worker handled test jobs on: '.implode(', ', $queues).'.']
            : [$this->bad(), 'The queue worker is not handling: '.implode(', ', $stopped).'. Start it with: php artisan queue:work --queue='.implode(',', $queues).' --tries=3'];
    }

    /** @return array{0:string,1:string} */
    private function checkFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [self::PASS, 'No failed_jobs table.'];
        }

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return [self::PASS, 'None.'];
        }

        return [self::WARN, $count.' failed job(s), latest at '.DB::table('failed_jobs')->max('failed_at').'. Look: php artisan queue:failed   Retry: php artisan queue:retry all'];
    }

    /** @return array{0:string,1:string} */
    private function checkStuckJobs(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return [self::PASS, 'Not applicable.'];
        }

        $stuck = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subMinutes(10)->getTimestamp())
            ->count();

        return $stuck === 0
            ? [self::PASS, 'No job has been waiting for more than 10 minutes.']
            : [self::WARN, $stuck.' job(s) have been waiting for more than 10 minutes. Is the queue worker running?'];
    }

    // ------------------------------------------------------------ provider

    /** @return array{0:string,1:string} */
    private function checkProviderMode(): array
    {
        if (config('fazercards.fake')) {
            return $this->production()
                ? [self::FAIL, 'FAZERCARDS_FAKE=true: orders are NOT sent to FazerCards, so customers would pay and receive nothing. Set it to false.']
                : [self::WARN, 'Fake provider is ON (test mode): orders are not sent to FazerCards. Set FAZERCARDS_FAKE=false before real orders.'];
        }

        return [self::PASS, 'Off: orders go to the real provider.'];
    }

    /** @return array{0:string,1:string} */
    private function checkProviderKey(): array
    {
        return blank(config('fazercards.api_key'))
            ? [$this->bad(), 'FAZERCARDS_API_KEY is empty, so orders cannot be sent.']
            : [self::PASS, 'Set.'];
    }

    /** @return array{0:string,1:string} */
    private function checkProviderBalance(): array
    {
        $monitor = app(ProviderBalanceMonitor::class);

        if (! $monitor->enabled()) {
            return [self::PASS, 'Not checked (no API key, or fake mode).'];
        }

        $snapshot = $monitor->snapshot();

        if ($snapshot === null) {
            return [self::WARN, 'No balance reading yet. Run: php artisan fazercards:check-balance'];
        }

        $age = $this->age((int) $snapshot['checked_at']);
        $money = $snapshot['balance'].' '.($snapshot['currency'] ?? '');
        $maxAge = max(1, (int) config('fazercards.balance_max_age_minutes', 30)) * 60;

        if ($age > $maxAge) {
            return [self::WARN, 'The last balance reading ('.$money.') is '.$this->human($age).' old. Is the scheduler running?'];
        }

        if ($monitor->isLow($snapshot)) {
            return [self::WARN, 'Provider balance is '.$money.', below your alert limit. Top it up in the FazerCards panel or orders will fail.'];
        }

        return [self::PASS, $money.' (checked '.$this->human($age).' ago).'];
    }

    /** @return array{0:string,1:string} */
    private function checkWebhook(): array
    {
        return filled(config('fazercards.webhook_secret'))
            ? [self::PASS, 'On. Only correctly signed deliveries are accepted.']
            : [self::PASS, 'Off (no secret set). Orders are still tracked by polling every minute.'];
    }

    /** @return array{0:string,1:string} */
    private function checkOrders(): array
    {
        $review = ProviderOrder::query()->where('status', ProviderOrderStatus::Review->value)->count();

        $stuck = ProviderOrder::query()
            ->whereIn('status', [
                ProviderOrderStatus::Queued->value,
                ProviderOrderStatus::Submitting->value,
                ProviderOrderStatus::Unknown->value,
            ])
            ->where('updated_at', '<=', now()->subMinutes(30))
            ->count();

        if ($review === 0 && $stuck === 0) {
            return [self::PASS, 'Nothing is waiting for a decision.'];
        }

        return [self::WARN, "{$review} order(s) wait for a decision, {$stuck} not sent for 30+ minutes. Open Admin > Needs attention."];
    }

    // ------------------------------------------------------------ payments

    /** @return array{0:string,1:string} */
    private function checkPayWayUrl(): array
    {
        return blank(config('payway_link.url'))
            ? [$this->bad(), 'PAYWAY_LINK_URL is empty, so customers cannot deposit.']
            : [self::PASS, 'Set (it is never shown to customers).'];
    }

    /** @return array{0:string,1:string} */
    private function checkPayWayWorker(): array
    {
        $worker = (string) config('payway_link.worker_path');

        if ($worker === '' || ! is_file($worker)) {
            return [$this->bad(), 'The payment worker script was not found (tools/payway-worker/worker.js).'];
        }

        if (! is_dir(dirname($worker).'/node_modules/playwright')) {
            return [$this->bad(), 'Playwright is not installed. Run "npm install" inside tools/payway-worker, then install its browser.'];
        }

        $node = (string) (config('payway_link.node_binary') ?: 'node');
        $result = Process::timeout(5)->run([$node, '--version']);

        if (! $result->successful()) {
            return [$this->bad(), 'Node.js could not be started (PAYWAY_NODE_BINARY="'.$node.'"). Install Node.js 18 or newer.'];
        }

        return [self::PASS, 'Node '.trim($result->output()).' and the worker are ready.'];
    }

    // ------------------------------------------------------------ security

    /** @return array{0:string,1:string} */
    private function checkSessionCookie(): array
    {
        if (! $this->production()) {
            return [self::PASS, 'Only enforced in production.'];
        }

        $problems = [];

        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true (cookie only over https)';
        }

        if (config('session.http_only') !== true) {
            $problems[] = 'SESSION_HTTP_ONLY must be true';
        }

        if (! in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true)) {
            $problems[] = 'SESSION_SAME_SITE must be lax or strict';
        }

        return $problems === []
            ? [self::PASS, 'Secure, HttpOnly and SameSite are set.']
            : [self::FAIL, implode('; ', $problems).'.'];
    }

    /** @return array{0:string,1:string} */
    private function checkPublicFolder(): array
    {
        $dangerous = ['.env', '.env.example', '.git', 'composer.json', 'composer.lock', 'artisan', 'vendor', 'phpunit.xml'];
        $found = array_values(array_filter($dangerous, fn (string $name) => file_exists(public_path($name))));

        return $found === []
            ? [self::PASS, 'No sensitive files inside public/.']
            : [self::FAIL, 'Sensitive file(s) inside public/: '.implode(', ', $found).'. Anyone could download them. The web root must be the public/ folder only.'];
    }

    /** @return array{0:string,1:string} */
    private function checkSeedPassword(): array
    {
        if (filled(config('topup.seed.password')) && $this->production()) {
            return [self::WARN, 'SEED_STAFF_PASSWORD is still in .env. Remove it now that the staff accounts exist.'];
        }

        return [self::PASS, 'Not left behind.'];
    }

    /** @return array{0:string,1:string} */
    private function checkStaffAccounts(): array
    {
        $supers = User::query()->where('role', 'super_admin')->where('status', 'active')->count();

        if ($supers === 0) {
            return [$this->bad(), 'There is no active super admin account.'];
        }

        $demo = User::query()->whereIn('email', self::DEMO_EMAILS)->pluck('email')->all();

        if ($demo !== [] && $this->production()) {
            return [self::FAIL, 'Development demo account(s) exist on the live server: '.implode(', ', $demo).'. Delete them or change their e-mail and password.'];
        }

        return [self::PASS, $supers.' active super admin account(s).'];
    }

    /** @return array{0:string,1:string} */
    private function checkMail(): array
    {
        if (! config('topup.notifications.mail')) {
            return [self::PASS, 'Off: notifications only appear on the website.'];
        }

        return in_array(config('mail.default'), ['log', 'array'], true)
            ? [self::WARN, 'NOTIFY_BY_MAIL=true, but MAIL_MAILER is "'.config('mail.default').'", so no e-mail is really sent.']
            : [self::PASS, 'On, sent through "'.config('mail.default').'".'];
    }

    // ------------------------------------------------------------ money

    /** @return array{0:string,1:string} */
    private function checkLedger(): array
    {
        $wallets = DB::table('wallets')->count();
        $entries = DB::table('wallet_transactions')->count();

        $unbalanced = (int) DB::selectOne(
            "SELECT COUNT(*) AS n FROM (
                SELECT w.id FROM wallets w
                LEFT JOIN wallet_transactions t ON t.wallet_id = w.id AND t.status = 'completed'
                GROUP BY w.id, w.balance
                HAVING w.balance <> COALESCE(SUM(CASE WHEN t.direction = 'credit' THEN t.amount WHEN t.direction = 'debit' THEN -t.amount ELSE 0 END), 0)
            ) x"
        )->n;

        $broken = DB::table('wallet_transactions')
            ->where(fn ($q) => $q->where('direction', 'credit')->whereRaw('balance_after <> balance_before + amount'))
            ->orWhere(fn ($q) => $q->where('direction', 'debit')->whereRaw('balance_after <> balance_before - amount'))
            ->count();

        if ($unbalanced > 0 || $broken > 0) {
            return [self::FAIL, "{$unbalanced} wallet(s) do not match their ledger and {$broken} ledger row(s) do not add up. Run: php artisan wallet:reconcile and investigate before anything else."];
        }

        return [self::PASS, "All {$wallets} wallet(s) match their {$entries} ledger entries."];
    }

    // ------------------------------------------------------------ helpers

    private function production(): bool
    {
        return config('app.env') === 'production';
    }

    /** What an operational mistake counts as: a failure on the live server, a warning elsewhere. */
    private function bad(): string
    {
        return $this->production() ? self::FAIL : self::WARN;
    }

    private function heartbeat(string $key): ?int
    {
        $value = Cache::get($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function age(int $timestamp): int
    {
        return max(0, now()->getTimestamp() - $timestamp);
    }

    private function human(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds.' seconds';
        }

        if ($seconds < 5400) {
            return (int) round($seconds / 60).' minutes';
        }

        return (int) round($seconds / 3600).' hours';
    }
}