<?php

namespace App\Contracts;

interface PayWaySessionRunner
{
    /**
     * Run one payment-page session. Calls $onEvent(array) for each event
     * (started, qr_ready, paid, expired, error). Returns the process exit code.
     *
     * @param  callable(array):void  $onEvent
     */
    public function run(string $amount, string $qrAbsolutePath, string $evidenceAbsolutePath, callable $onEvent): int;
}