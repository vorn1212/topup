<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the files in /deploy against mistakes that only show up on the live server:
 * wrong timing between the queue and the workers, secrets pasted into the example, Windows line endings.
 */
class DeploymentFilesTest extends TestCase
{
    private const FILES = [
        'deploy/DEPLOY-GUIDE.md',
        'deploy/env/.env.production.example',
        'deploy/nginx/topup-bootstrap.conf',
        'deploy/nginx/topup.conf',
        'deploy/supervisor/topup.conf',
        'deploy/cron/topup.cron',
        'deploy/php/99-topup.ini',
        'deploy/php/topup-pool.conf',
        'deploy/scripts/deploy.sh',
        'deploy/scripts/rollback.sh',
        'deploy/scripts/backup.sh',
        'deploy/scripts/smoke-test.sh',
    ];

    private function read(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, $relative.' is missing. Copy it from the Phase 12 zip.');

        return (string) file_get_contents($path);
    }

    /** @return array<string, string> */
    private function envExample(): array
    {
        $values = [];

        foreach (preg_split('/\R/', $this->read('deploy/env/.env.production.example')) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m) === 1) {
                $values[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        return $values;
    }

    private function section(string $name): string
    {
        $conf = $this->read('deploy/supervisor/topup.conf');

        $this->assertSame(1, preg_match('/^\['.preg_quote($name, '/').'\]\R(.*?)(?=^\[|\z)/ms', $conf, $m), 'Section '.$name.' not found.');

        return $m[1];
    }

    private function setting(string $section, string $key): string
    {
        $this->assertSame(1, preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $section, $m), $key.' not found.');

        return trim($m[1]);
    }

    public function test_every_deployment_file_exists_and_uses_unix_line_endings(): void
    {
        foreach (self::FILES as $file) {
            $this->assertStringNotContainsString("\r", $this->read($file), $file.' has Windows line endings (CRLF). Linux scripts and cron files break with them.');
        }
    }

    public function test_the_env_example_is_safe_and_set_for_production(): void
    {
        $env = $this->envExample();

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('database', $env['QUEUE_CONNECTION']);
        $this->assertSame('false', $env['FAZERCARDS_FAKE'], 'FAZERCARDS_FAKE must be false on the live site.');
        $this->assertSame('true', $env['PAYWAY_HEADLESS'], 'The server has no screen: the payment browser must be headless.');
        $this->assertStringStartsWith('https://', $env['APP_URL']);
        $this->assertSame('', $env['PAYWAY_LINK_URL'], 'The payment link is private. Do not write it in the example.');

        foreach ($env as $key => $value) {
            if (preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/', $key) === 1) {
                $this->assertSame('', $value, $key.' looks like a secret and must be empty in the example.');
            }
        }
    }

    public function test_every_env_name_in_the_payment_config_is_in_the_example(): void
    {
        $env = $this->envExample();

        foreach (['PAYWAY_LINK_URL', 'PAYWAY_NODE_BINARY', 'PAYWAY_SESSION_SECONDS', 'PAYWAY_QR_SECONDS', 'PAYWAY_HEADLESS', 'FAZERCARDS_API_KEY', 'FAZERCARDS_FAKE', 'DB_QUEUE_RETRY_AFTER'] as $name) {
            $this->assertArrayHasKey($name, $env, $name.' is missing from the example.');
        }
    }

    public function test_the_workers_timing_is_consistent(): void
    {
        $env = $this->envExample();
        $payway = $this->section('program:topup-payway');
        $default = $this->section('program:topup-default');

        $this->assertSame(1, preg_match('/--timeout=(\d+)/', $this->setting($payway, 'command'), $p));
        $this->assertSame(1, preg_match('/--timeout=(\d+)/', $this->setting($default, 'command'), $d));

        $session = (int) $env['PAYWAY_SESSION_SECONDS'];
        $retryAfter = (int) $env['DB_QUEUE_RETRY_AFTER'];

        $this->assertGreaterThan($session, (int) $p[1], 'The worker timeout must be longer than a payment session.');
        $this->assertGreaterThan((int) $p[1], $retryAfter, 'DB_QUEUE_RETRY_AFTER must be longer than the worker timeout, or one payment is started twice.');
        $this->assertGreaterThan((int) $d[1], $retryAfter);
        $this->assertGreaterThan((int) $p[1], (int) $this->setting($payway, 'stopwaitsecs'), 'Supervisor must wait longer than a job can run when it stops a worker.');
        $this->assertGreaterThan((int) $d[1], (int) $this->setting($default, 'stopwaitsecs'));
    }

    public function test_the_payment_workers_listen_to_the_right_queue_and_stop_the_browser_with_them(): void
    {
        $payway = $this->section('program:topup-payway');

        $this->assertStringContainsString('--queue='.config('payway_link.queue'), $this->setting($payway, 'command'));
        $this->assertStringContainsString('--queue=default', $this->setting($this->section('program:topup-default'), 'command'));
        $this->assertGreaterThanOrEqual(1, (int) $this->setting($payway, 'numprocs'));
        $this->assertSame('true', $this->setting($payway, 'stopasgroup'));
        $this->assertSame('true', $this->setting($payway, 'killasgroup'));
        $this->assertSame('true', $this->setting($payway, 'autorestart'));
        $this->assertStringContainsString('HOME=', $this->setting($payway, 'environment'), 'Chromium is found through HOME.');
    }

    public function test_the_scripts_stop_on_the_first_error_and_update_in_a_safe_order(): void
    {
        foreach (['deploy', 'rollback', 'backup'] as $name) {
            $script = $this->read('deploy/scripts/'.$name.'.sh');

            $this->assertStringStartsWith('#!/usr/bin/env bash', $script);
            $this->assertStringContainsString('set -Eeuo pipefail', $script, $name.'.sh must stop on the first error.');
        }

        $deploy = $this->read('deploy/scripts/deploy.sh');
        $order = ['artisan down', 'composer install', 'npm run build', 'migrate --force', 'artisan optimize', 'queue:restart', 'artisan up'];
        $last = -1;

        foreach ($order as $step) {
            $position = strpos($deploy, $step);

            $this->assertNotFalse($position, 'deploy.sh is missing: '.$step);
            $this->assertGreaterThan($last, $position, 'deploy.sh runs "'.$step.'" in the wrong order.');
            $last = $position;
        }

        $this->assertStringContainsString('--no-dev', $deploy);

        $backup = $this->read('deploy/scripts/backup.sh');
        $this->assertStringContainsString('--single-transaction', $backup);
        $this->assertStringContainsString('gzip -t', $backup, 'A backup must be test-read after it is written.');
        $this->assertStringNotContainsString('DB_PASSWORD', $backup, 'The backup must not read the password into a command line.');
    }

    public function test_nginx_hides_secrets_and_runs_only_index_php(): void
    {
        $nginx = $this->read('deploy/nginx/topup.conf');

        $this->assertStringContainsString('server_tokens off', $nginx);
        $this->assertStringContainsString('location ~ /\.(?!well-known)', $nginx);
        $this->assertStringContainsString('location = /index.php', $nginx);
        $this->assertMatchesRegularExpression('/location ~ \\\\\.php\$ \{\s*return 404;/', $nginx);
        $this->assertStringContainsString('Strict-Transport-Security', $nginx);
        $this->assertSame(1, substr_count($nginx, 'add_header'), 'add_header inside a location would silently remove the HSTS header there.');
        $this->assertStringContainsString('YOUR_DOMAIN', $nginx);
        $this->assertStringContainsString('return 301 https://', $nginx);

        $bootstrap = $this->read('deploy/nginx/topup-bootstrap.conf');
        $this->assertStringContainsString('acme-challenge', $bootstrap);
        $this->assertStringContainsString('YOUR_DOMAIN', $bootstrap);
    }

    public function test_the_php_pool_and_nginx_use_the_same_socket_and_one_user(): void
    {
        $pool = $this->read('deploy/php/topup-pool.conf');

        $this->assertSame(1, preg_match('/^listen = (\S+)$/m', $pool, $m));
        $this->assertStringContainsString($m[1], $this->read('deploy/nginx/topup.conf'), 'nginx must talk to the socket the PHP pool listens on.');
        $this->assertStringContainsString($m[1], $this->read('deploy/nginx/topup-bootstrap.conf'));
        $this->assertMatchesRegularExpression('/^user = deploy$/m', $pool);
        $this->assertStringContainsString('user=deploy', $this->section('program:topup-payway'));
        $this->assertStringContainsString('user=deploy', $this->section('program:topup-default'));
        $this->assertMatchesRegularExpression('/^\* \* \* \* \* deploy /m', $this->read('deploy/cron/topup.cron'));
    }

    public function test_cron_runs_the_scheduler_every_minute_and_the_backup(): void
    {
        $cron = $this->read('deploy/cron/topup.cron');

        $this->assertMatchesRegularExpression('/^\* \* \* \* \* deploy cd \/var\/www\/topup && \/usr\/bin\/php artisan schedule:run/m', $cron);
        $this->assertStringContainsString('deploy/scripts/backup.sh', $cron);
    }

    public function test_git_will_not_upload_secrets_or_the_worker_node_modules(): void
    {
        $raw = file_exists(base_path('.gitignore')) ? (string) file_get_contents(base_path('.gitignore')) : '';

        // Windows editors may save CRLF, and Laravel writes entries like "/node_modules": accept both.
        $lines = array_map(fn ($line) => trim($line), preg_split('/\R/', $raw) ?: []);
        $lines = array_map(fn ($line) => rtrim(ltrim($line, '/'), '/'), $lines);

        $this->assertContains('.env', $lines, '.gitignore must contain the line: .env');
        $this->assertTrue(
            in_array('node_modules', $lines, true) || in_array('tools/payway-worker/node_modules', $lines, true),
            'Add the line "node_modules" to .gitignore, or the payment worker packages are uploaded to git.'
        );
    }
}