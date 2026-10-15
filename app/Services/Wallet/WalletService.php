<?php

namespace App\Services\Wallet;

use App\Enums\WalletStatus;
use App\Enums\WalletTxType;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WalletFrozenException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditService;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The ONLY class allowed to change a wallet balance.
 *
 * Every change:
 *  - runs inside DB::transaction
 *  - locks the wallet row (SELECT ... FOR UPDATE)
 *  - checks idempotency AFTER the lock (concurrent duplicates are serialized)
 *  - checks funds AFTER the lock
 *  - writes an immutable ledger row and updates the cached balance
 */
class WalletService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function createWallet(User $user, ?string $currency = null): Wallet
    {
        $existing = Wallet::query()->where('user_id', $user->getKey())->first();
        if ($existing) {
            return $existing;
        }

        try {
            $wallet = new Wallet;
            $wallet->forceFill([
                'user_id' => $user->getKey(),
                'currency' => $currency ?? config('topup.currency', 'USD'),
                'balance' => '0.0000',
                'status' => WalletStatus::Active,
            ])->save();

            return $wallet;
        } catch (UniqueConstraintViolationException) {
            // Another request created it first.
            return Wallet::query()->where('user_id', $user->getKey())->firstOrFail();
        }
    }

    /**
     * Current balance read straight from the database (decimal string).
     */
    public function getBalance(User|Wallet $owner): string
    {
        $wallet = $owner instanceof Wallet ? $owner : $this->createWallet($owner);

        return Money::normalize((string) Wallet::query()->whereKey($wallet->getKey())->value('balance'));
    }

    /**
     * Informational only. The authoritative check is repeated under lock in debit().
     */
    public function hasSufficientBalance(Wallet $wallet, string $amount): bool
    {
        return Money::cmp($this->getBalance($wallet), $this->assertAmount($amount)) >= 0;
    }

    public function credit(
        Wallet $wallet,
        string $amount,
        string $idempotencyKey,
        WalletTxType $type = WalletTxType::Deposit,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
    ): WalletTransaction {
        if (! in_array($type, [WalletTxType::Deposit, WalletTxType::Bonus], true)) {
            throw new InvalidArgumentException('credit() only supports deposit or bonus. Use refund() or adjust().');
        }

        return $this->apply($wallet, WalletTransaction::CREDIT, $type, $amount, $idempotencyKey, $reference, $description, $meta);
    }

    public function debit(
        Wallet $wallet,
        string $amount,
        string $idempotencyKey,
        WalletTxType $type = WalletTxType::Purchase,
        ?Model $reference = null,
        ?string $description = null,
        array $meta = [],
    ): WalletTransaction {
        if (! in_array($type, [WalletTxType::Purchase, WalletTxType::Withdrawal], true)) {
            throw new InvalidArgumentException('debit() only supports purchase or withdrawal. Use adjust().');
        }

        return $this->apply($wallet, WalletTransaction::DEBIT, $type, $amount, $idempotencyKey, $reference, $description, $meta);
    }

    public function refund(
        Wallet $wallet,
        string $amount,
        string $idempotencyKey,
        ?Model $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return $this->apply(
            $wallet,
            WalletTransaction::CREDIT,
            WalletTxType::Refund,
            $amount,
            $idempotencyKey,
            $reference,
            $description ?? 'Order refund',
        );
    }

    /**
     * Manual admin credit/debit. Creates a NEW adjustment transaction; nothing is edited.
     */
    public function adjust(
        Wallet $wallet,
        string $direction,
        string $amount,
        User $admin,
        string $reason,
        ?string $idempotencyKey = null,
    ): WalletTransaction {
        Gate::forUser($admin)->authorize('wallet.adjust');

        if (! in_array($direction, [WalletTransaction::CREDIT, WalletTransaction::DEBIT], true)) {
            throw new InvalidArgumentException('Direction must be credit or debit.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('A reason is required for a manual adjustment.');
        }

        $meta = [
            'admin_id' => $admin->getKey(),
            'reason' => $reason,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ];

        return $this->apply(
            $wallet,
            $direction,
            WalletTxType::Adjustment,
            $amount,
            $idempotencyKey ?? 'adjust:'.Str::uuid(),
            null,
            'Balance adjustment',
            $meta,
        );
    }

    public function getTransactions(Wallet $wallet, ?WalletTxType $type = null, int $perPage = 15): LengthAwarePaginator
    {
        return WalletTransaction::query()
            ->where('wallet_id', $wallet->getKey())
            ->when($type, fn ($query) => $query->where('type', $type->value))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Single core for every balance change.
     */
    private function apply(
        Wallet $wallet,
        string $direction,
        WalletTxType $type,
        string $amount,
        string $idempotencyKey,
        ?Model $reference,
        ?string $description,
        array $meta = [],
    ): WalletTransaction {
        $amount = $this->assertAmount($amount);

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            throw new InvalidArgumentException('Idempotency key must be 1-120 characters.');
        }

        $referenceType = $reference ? Str::snake(class_basename($reference)) : null;
        $referenceId = $reference?->getKey();

        $transaction = DB::transaction(function () use (
            $wallet, $direction, $type, $amount, $idempotencyKey, $referenceType, $referenceId, $description, $meta
        ) {
            // 1. Lock the wallet row. All operations on this wallet are serialized from here.
            $locked = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            // 2. Idempotency (after the lock, so concurrent duplicates cannot both pass).
            $existing = WalletTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->wallet_id !== (int) $locked->id
                    || $existing->direction !== $direction
                    || Money::cmp((string) $existing->amount, $amount) !== 0) {
                    throw new LogicException('Idempotency key was already used for a different operation.');
                }

                return $existing;
            }

            // 3. Wallet state.
            if ($direction === WalletTransaction::DEBIT
                && $locked->status === WalletStatus::Frozen
                && $type !== WalletTxType::Adjustment) {
                throw new WalletFrozenException;
            }

            // 4. Balance math, checked under the lock.
            $before = Money::normalize((string) $locked->balance);
            $after = $direction === WalletTransaction::CREDIT
                ? Money::add($before, $amount)
                : Money::sub($before, $amount);

            if (Money::isNegative($after)) {
                throw new InsufficientBalanceException;
            }

            // 5. Immutable ledger row, then the cached balance.
            $tx = new WalletTransaction;
            $tx->forceFill([
                'wallet_id' => $locked->id,
                'user_id' => $locked->user_id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'currency' => $locked->currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'status' => WalletTransaction::STATUS_COMPLETED,
                'idempotency_key' => $idempotencyKey,
                'meta' => $meta === [] ? null : $meta,
            ])->save();

            $locked->forceFill(['balance' => $after])->save();

            $this->audit->log("wallet.{$direction}", $tx, [], [
                'type' => $type->value,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'meta' => $meta,
            ]);

            return $tx;
        }, 3);

        $wallet->refresh();

        return $transaction;
    }

    /**
     * Amount must be a positive decimal string with at most 4 decimals. No floats, no exponents.
     */
    private function assertAmount(string $amount): string
    {
        if (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $amount)) {
            throw new InvalidArgumentException('Amount must be a positive decimal with at most 4 decimal places.');
        }

        $normalized = Money::normalize($amount);

        if (Money::cmp($normalized, '0') <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $normalized;
    }
}