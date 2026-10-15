<?php

namespace App\Services\Payment;

use App\Contracts\PayWaySessionRunner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class NodeSessionRunner implements PayWaySessionRunner
{
    public function run(string $amount, string $qrAbsolutePath, string $evidenceAbsolutePath, callable $onEvent): int
    {
        $url = (string) config('payway_link.url');
        $worker = (string) config('payway_link.worker_path');

        if ($url === '' || ! is_file($worker)) {
            throw new RuntimeException('PayWay link worker is not configured.');
        }

        $process = new Process(
            [(string) config('payway_link.node_binary', 'node'), $worker],
            dirname($worker),
            [
                'PAYWAY_LINK_URL' => $url,
                'DEPOSIT_AMOUNT' => $amount,
                'QR_OUT' => $qrAbsolutePath,
                'EVIDENCE_OUT' => $evidenceAbsolutePath,
                'SESSION_SECONDS' => (string) config('payway_link.session_seconds'),
                'HEADLESS' => config('payway_link.headless') ? 'true' : 'false',
            ]
        );
        $process->setTimeout((int) config('payway_link.session_seconds') + 90);

        $buffer = '';

        $process->run(function (string $type, string $chunk) use (&$buffer, $onEvent) {
            if ($type !== Process::OUT) {
                Log::warning('payway worker stderr', ['message' => Str::limit(trim($chunk), 300)]);

                return;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $event = json_decode($line, true);
                if (is_array($event)) {
                    $onEvent($event);
                }
            }
        });

        return (int) $process->getExitCode();
    }
}