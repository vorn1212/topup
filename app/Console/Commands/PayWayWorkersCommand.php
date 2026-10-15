<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Runs several PayWay queue workers in parallel and restarts any that exit.
 * One worker = one deposit at a time (each holds one browser until paid/expired).
 */
class PayWayWorkersCommand extends Command
{
    protected $signature = 'payway:workers {count? : Number of parallel workers (1-10)}';

    protected $description = 'Run several PayWay queue workers so customers can deposit at the same time.';

    public function handle(): int
    {
        $count = (int) ($this->argument('count') ?: config('payway_link.workers', 3));
        $count = max(1, min(10, $count));

        $this->info("Starting {$count} PayWay workers (queue: ".config('payway_link.queue').'). Press Ctrl+C to stop.');
        $this->warn('Do not stop the workers while a customer is paying: their deposit would be left for manual review.');

        $stopping = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$stopping) {
                $stopping = true;
            });
            pcntl_signal(SIGTERM, function () use (&$stopping) {
                $stopping = true;
            });
        }

        /** @var array<int,Process> $processes */
        $processes = [];

        while (! $stopping) {
            for ($i = 1; $i <= $count; $i++) {
                if (isset($processes[$i]) && $processes[$i]->isRunning()) {
                    continue;
                }

                if (isset($processes[$i])) {
                    $this->line("[worker {$i}] exited, restarting");
                }

                $processes[$i] = $this->startWorker($i);
            }

            sleep(1);
        }

        foreach ($processes as $process) {
            $process->stop(10);
        }

        return self::SUCCESS;
    }

    private function startWorker(int $number): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            '--queue='.config('payway_link.queue', 'payway'),
            '--tries=1',
            '--timeout=400',
            '--sleep=1',
            '--max-time=3600', // exit hourly; this command restarts it with fresh code
        ], base_path());

        $process->setTimeout(null);
        $process->start(function (string $type, string $buffer) use ($number) {
            foreach (preg_split('/\R/', trim($buffer)) as $line) {
                if ($line !== '') {
                    $this->line("[worker {$number}] ".$line);
                }
            }
        });

        return $process;
    }
}