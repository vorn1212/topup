<?php

namespace App\Services\Payment;

use App\Enums\DepositStatus;
use App\Enums\WalletTxType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletDeposit;
use App\Services\AuditService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\NumberGenerator;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class DepositService
{
    public const METHOD_PAYWAY_LINK = 'aba_payway_link';

    public const STAGE_QUEUED = 'queued';
    public const STAGE_STARTING = 'starting';
    public const STAGE_QR_READY = 'qr_ready';
    public const STAGE_PAID = 'paid';
    public const STAGE_EXPIRED = 'expired';
    public const STAGE_FAILED = 'failed';
    public const STAGE_REVIEW = 'review';

    /** Columns a gateway session may change through transition(). */
    private const GATEWAY_ATTRIBUTES = ['gateway_stage', 'qr_expires_at', 'gateway_error'];

    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Create a pending deposit record.
     */
    public function create(User $user, string $amount, string $method = 'aba_payway'): WalletDeposit
    {
        if (! $user->canTransact()) {
            throw new AuthorizationException('Your account is not allowed to make deposits.');
        }

        if (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $amount)) {
            throw new InvalidArgumentException('Invalid deposit amount.');
        }

        $amount = Money::normalize($amount);
        $min = (string) config('topup.deposit.min');
        $max = (string) config('topup.deposit.max');

        if (Money::cmp($amount, $min) < 0 || Money::cmp($amount, $max) > 0) {
            throw new InvalidArgumentException(
                sprintf('Deposit must be between %s and %s.', Money::format($min), Money::format($max))
            );
        }

        $wallet = $this->wallets->createWallet($user);

        for ($attempt = 1; ; $attempt++) {
            $number = NumberGenerator::depositNumber();

            try {
                $deposit = new WalletDeposit;
                $deposit->forceFill([
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'deposit_number' => $number,
                    'amount' => $amount,
                    'currency' => $wallet->currency,
                    'payment_method' => $method,
                    'payment_reference' => $number,
                    'status' => DepositStatus::Pending,
                    'gateway_stage' => $method === self::METHOD_PAYWAY_LINK ? self::STAGE_QUEUED : null,
                    'expires_at' => now()->addMinutes((int) config('topup.deposit.expire_minutes', 30)),
                ])->save();

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }

        $this->audit->log('deposit.created', $deposit, [], [
            'deposit_number' => $deposit->deposit_number,
            'amount' => $amount,
            'method' => $method,
        ], $user);

        return $deposit;
    }

    /**
     * Mark a deposit paid and credit the wallet, exactly once.
     *
     * Call ONLY after the payment was verified. Lock order: deposit row, then wallet row.
     * Safe to call repeatedly: an already-paid deposit is returned unchanged.
     */
    public function markPaid(int $depositId, string $abaTransactionId, ?CarbonInterface $paidAt = null): WalletDeposit
    {
        $abaTransactionId = trim($abaTransactionId);
        if ($abaTransactionId === '') {
            throw new InvalidArgumentException('ABA transaction id is required.');
        }

        return DB::transaction(function () use ($depositId, $abaTransactionId, $paidAt) {
            $deposit = WalletDeposit::query()->whereKey($depositId)->lockForUpdate()->firstOrFail();

            if ($deposit->status === DepositStatus::Paid) {
                return $deposit;
            }

            if ($deposit->status === DepositStatus::Refunded) {
                throw new DomainException('A refunded deposit cannot be marked as paid.');
            }

            $previous = $deposit->status->value;

            $deposit->forceFill([
                'status' => DepositStatus::Paid,
                'paid_at' => $paidAt ?? now(),
                'aba_transaction_id' => $abaTransactionId,
                'gateway_stage' => self::STAGE_PAID,
                'gateway_error' => null,
            ])->save();

            $wallet = Wallet::query()->findOrFail($deposit->wallet_id);

            $this->wallets->credit(
                $wallet,
                (string) $deposit->amount,
                "deposit:{$deposit->id}",
                WalletTxType::Deposit,
                $deposit,
                "Wallet deposit {$deposit->deposit_number}",
            );

            $this->audit->log('deposit.paid', $deposit, ['status' => $previous], [
                'status' => 'paid',
                'aba_transaction_id' => $abaTransactionId,
                'amount' => (string) $deposit->amount,
            ]);

            return $deposit;
        });
    }

    /**
     * Atomically claim a queued deposit for a gateway session.
     * Returns false if it was already claimed, is no longer pending, or has expired.
     */
    public function claimSession(int $depositId): bool
    {
        return DB::transaction(function () use ($depositId) {
            $deposit = WalletDeposit::query()->whereKey($depositId)->lockForUpdate()->first();

            if (! $deposit
                || $deposit->status !== DepositStatus::Pending
                || $deposit->gateway_stage !== self::STAGE_QUEUED) {
                return false;
            }

            if ($deposit->expires_at && $deposit->expires_at->isPast()) {
                $deposit->forceFill([
                    'status' => DepositStatus::Expired,
                    'gateway_stage' => self::STAGE_EXPIRED,
                ])->save();

                return false;
            }

            $deposit->forceFill([
                'status' => DepositStatus::Processing,
                'gateway_stage' => self::STAGE_STARTING,
            ])->save();

            return true;
        });
    }

    /**
     * Update gateway-session fields and/or the status of a deposit that is not yet final.
     * Paid and refunded deposits are never changed here. Use markPaid() to pay.
     *
     * @param  array<string,mixed>  $attributes  only gateway_stage, qr_expires_at, gateway_error are applied
     * @param  array<string,mixed>  $metadata    merged into the metadata JSON
     */
    public function transition(int $depositId, ?DepositStatus $to, array $attributes = [], array $metadata = []): WalletDeposit
    {
        if ($to === DepositStatus::Paid) {
            throw new LogicException('Use markPaid() to mark a deposit as paid.');
        }

        return DB::transaction(function () use ($depositId, $to, $attributes, $metadata) {
            $deposit = WalletDeposit::query()->whereKey($depositId)->lockForUpdate()->firstOrFail();

            if (in_array($deposit->status, [DepositStatus::Paid, DepositStatus::Refunded], true)) {
                return $deposit;
            }

            $deposit->forceFill(array_intersect_key($attributes, array_flip(self::GATEWAY_ATTRIBUTES)));

            if ($to !== null) {
                $deposit->forceFill(['status' => $to]);
            }

            if ($metadata !== []) {
                $deposit->metadata = array_merge($deposit->metadata ?? [], $metadata);
            }

            $deposit->save();

            return $deposit;
        });
    }
}