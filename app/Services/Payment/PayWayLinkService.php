<?php

namespace App\Services\Payment;

use App\Contracts\PayWaySessionRunner;
use App\Enums\DepositStatus;
use App\Models\WalletDeposit;
use App\Services\AuditService;
use App\Support\Money;
use App\Support\QrImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives one ABA PayWay link session for one deposit and turns the worker's events
 * into deposit state changes. The wallet is credited only through DepositService::markPaid().
 */
class PayWayLinkService
{
    public function __construct(
        private readonly PayWaySessionRunner $runner,
        private readonly DepositService $deposits,
        private readonly AuditService $audit,
    ) {
    }

    public static function qrPath(string $depositNumber): string
    {
        return "payway/qr/{$depositNumber}.png";
    }

    public static function evidencePath(string $depositNumber): string
    {
        return "payway/evidence/{$depositNumber}.png";
    }

    public function run(int $depositId): void
    {
        // Only one worker can ever claim a queued deposit.
        if (! $this->deposits->claimSession($depositId)) {
            return;
        }

        $deposit = WalletDeposit::query()->findOrFail($depositId);
        $disk = Storage::disk('local');
        $disk->makeDirectory('payway/qr');
        $disk->makeDirectory('payway/evidence');

        $qrPath = self::qrPath($deposit->deposit_number);
        $evidencePath = self::evidencePath($deposit->deposit_number);
        $qrFile = $disk->path($qrPath);
        $state = ['qr' => false, 'final' => false];
        $exit = -1;

        try {
            $exit = $this->runner->run(
                Money::round((string) $deposit->amount, 2),
                $qrFile,
                $disk->path($evidencePath),
                function (array $event) use ($depositId, &$state, $qrFile) {
                    $this->handleEvent($depositId, $event, $state, $qrFile);
                }
            );
        } catch (Throwable $e) {
            report($e);
            $this->handleUnexpectedEnd($depositId, $state['qr'], 'Session error: '.Str::limit($e->getMessage(), 150));

            return;
        } finally {
            $disk->delete($qrPath);
        }

        if (! $state['final']) {
            $this->handleUnexpectedEnd($depositId, $state['qr'], "Worker ended without a result (exit code {$exit}).");
        }
    }

    /**
     * Called by the queue job's failed() hook.
     */
    public function handleCrash(int $depositId, Throwable $e): void
    {
        $deposit = WalletDeposit::query()->find($depositId);
        if (! $deposit) {
            return;
        }

        $this->handleUnexpectedEnd(
            $depositId,
            $deposit->gateway_stage === DepositService::STAGE_QR_READY,
            'Job failed: '.Str::limit($e->getMessage(), 150)
        );
    }

    /**
     * @param  array{qr:bool,final:bool}  $state
     */
    private function handleEvent(int $depositId, array $event, array &$state, string $qrFile): void
    {
        switch ($event['event'] ?? '') {
            case 'qr_ready':
                $state['qr'] = true;

                // Crop empty margins BEFORE the page is told the QR is ready.
                QrImage::trim($qrFile);

                $this->deposits->transition($depositId, null, [
                    'gateway_stage' => DepositService::STAGE_QR_READY,
                    'qr_expires_at' => now()->addSeconds((int) config('payway_link.qr_seconds', 175)),
                ]);
                break;

            case 'paid':
                $state['final'] = true;
                $this->handlePaid($depositId, $event);
                break;

            case 'expired':
                $state['final'] = true;
                $deposit = $this->deposits->transition($depositId, DepositStatus::Expired, [
                    'gateway_stage' => DepositService::STAGE_EXPIRED,
                ]);
                $this->audit->log('deposit.expired', $deposit);
                break;

            case 'error':
                $state['final'] = true;
                $this->handleUnexpectedEnd(
                    $depositId,
                    $state['qr'],
                    'Worker error: '.Str::limit((string) ($event['message'] ?? 'unknown'), 150)
                );
                break;

            default:
                break; // "started" and unknown events
        }
    }

    private function handlePaid(int $depositId, array $event): void
    {
        $deposit = WalletDeposit::query()->findOrFail($depositId);
        $amount = isset($event['amount']) ? (string) $event['amount'] : '';

        $amountMatches = preg_match('/^\d{1,14}(\.\d{1,4})?$/', $amount) === 1
            && Money::cmp($amount, (string) $deposit->amount) === 0;

        if (! $amountMatches) {
            $this->flagForReview($deposit, 'Paid screen amount missing or different from the deposit amount.', [
                'paid_screen_amount' => Str::limit($amount, 30, ''),
            ]);

            return;
        }

        try {
            if (Storage::disk('local')->exists(self::evidencePath($deposit->deposit_number))) {
                $this->deposits->transition($depositId, null, [], [
                    'evidence' => self::evidencePath($deposit->deposit_number),
                ]);
            }

            $this->deposits->markPaid($depositId, 'PWLINK-'.$deposit->deposit_number);
        } catch (Throwable $e) {
            report($e);
            $this->flagForReview($deposit, 'Payment seen but crediting failed: '.Str::limit($e->getMessage(), 120));
        }
    }

    private function handleUnexpectedEnd(int $depositId, bool $qrWasShown, string $reason): void
    {
        $deposit = WalletDeposit::query()->find($depositId);
        if (! $deposit) {
            return;
        }

        if ($qrWasShown) {
            // The customer may have paid. Never auto-fail; staff must verify in the ABA app.
            $this->flagForReview($deposit, $reason);

            return;
        }

        $updated = $this->deposits->transition($depositId, DepositStatus::Failed, [
            'gateway_stage' => DepositService::STAGE_FAILED,
            'gateway_error' => Str::limit($reason, 255, ''),
        ]);

        Log::warning('PayWay link session failed before QR', ['deposit' => $deposit->deposit_number, 'reason' => $reason]);
        $this->audit->log('deposit.session_failed', $updated, [], ['reason' => $reason]);
    }

    private function flagForReview(WalletDeposit $deposit, string $reason, array $metadata = []): void
    {
        $updated = $this->deposits->transition($deposit->id, null, [
            'gateway_stage' => DepositService::STAGE_REVIEW,
            'gateway_error' => Str::limit($reason, 255, ''),
        ], $metadata);

        Log::critical('PayWay deposit needs manual review', [
            'deposit' => $deposit->deposit_number,
            'amount' => (string) $deposit->amount,
            'reason' => $reason,
        ]);

        $this->audit->log('deposit.needs_review', $updated, [], ['reason' => $reason] + $metadata);
    }
}