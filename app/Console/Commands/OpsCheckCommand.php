<?php

namespace App\Console\Commands;

use App\Services\Ops\HealthCheck;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * php artisan ops:check            -> exit code 1 when anything FAILS
 * php artisan ops:check --strict   -> exit code 1 on warnings too (use before going live)
 * php artisan ops:check --problems -> show only warnings and failures
 */
class OpsCheckCommand extends Command
{
    protected $signature = 'ops:check {--strict : Treat warnings as failures} {--problems : Only show warnings and failures}';

    protected $description = 'Check that the shop is configured and running correctly (queue, scheduler, keys, security).';

    public function handle(HealthCheck $health): int
    {
        $results = $health->run();
        $summary = $health->summary($results);
        $problemsOnly = (bool) $this->option('problems');

        $this->newLine();
        $this->line('Environment: <options=bold>'.OutputFormatter::escape((string) config('app.env')).'</>');

        $group = null;

        foreach ($results as $result) {
            if ($problemsOnly && $result['level'] === HealthCheck::PASS) {
                continue;
            }

            if ($result['group'] !== $group) {
                $group = $result['group'];
                $this->newLine();
                $this->line('<options=bold>'.OutputFormatter::escape($group).'</>');
            }

            [$tag, $color] = match ($result['level']) {
                HealthCheck::FAIL => ['FAIL', 'red'],
                HealthCheck::WARN => ['WARN', 'yellow'],
                default => ['PASS', 'green'],
            };

            $this->line(sprintf(
                '  <fg=%s>%s</>  %s - %s',
                $color,
                $tag,
                OutputFormatter::escape($result['name']),
                OutputFormatter::escape($result['message'])
            ));
        }

        $this->newLine();
        $this->line(sprintf('%d passed, %d warning(s), %d failure(s).', $summary['pass'], $summary['warn'], $summary['fail']));

        $strict = (bool) $this->option('strict');

        if ($summary['fail'] > 0) {
            $this->error('NOT READY: fix the failures above.');

            return self::FAILURE;
        }

        if ($summary['warn'] > 0 && $strict) {
            $this->error('NOT READY (strict): fix the warnings above.');

            return self::FAILURE;
        }

        $this->info($summary['warn'] > 0 ? 'No failures. Read the warnings before going live.' : 'Everything looks good.');

        return self::SUCCESS;
    }
}