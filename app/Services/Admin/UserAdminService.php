<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\Wallet\WalletService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Staff actions on customer and staff accounts.
 *
 * Two independent checks protect every action: the permission (users.manage / roles.manage) and the
 * RANK rule: you can only act on someone ranking BELOW you, never on yourself. So an admin can manage
 * customers and support staff, a super admin can also manage admins, and nobody can touch a super admin
 * through the website (those are managed with tinker on the server).
 */
class UserAdminService
{
    private const RANK = ['user' => 0, 'support' => 1, 'admin' => 2, 'super_admin' => 3];

    public function __construct(
        private readonly AuditService $audit,
        private readonly WalletService $wallets,
    ) {
    }

    public static function rank(User $user): int
    {
        return self::RANK[$user->role->value] ?? 0;
    }

    public function canManage(User $actor, User $target): bool
    {
        return $actor->isActive()
            && $actor->isStaff()
            && $actor->id !== $target->id
            && self::rank($actor) > self::rank($target);
    }

    public function setStatus(User $target, UserStatus $status, User $actor, string $reason): User
    {
        $this->authorize($actor, 'users.manage');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($target, $status, $actor, $reason) {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManage($actor, $locked);

            if ($locked->status === $status) {
                throw new DomainException("The account is already {$status->value}.");
            }

            $old = $locked->status;
            $locked->forceFill(['status' => $status])->save();

            $this->audit->log('user.status_changed', $locked, ['status' => $old->value], [
                'status' => $status->value,
                'reason' => $reason,
            ], $actor);

            return $locked;
        });
    }

    public function setRole(User $target, UserRole $role, User $actor, string $reason): User
    {
        $this->authorize($actor, 'roles.manage');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($target, $role, $actor, $reason) {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManage($actor, $locked);

            // Nobody can hand out a rank equal to or above their own.
            if (self::RANK[$role->value] >= self::rank($actor)) {
                throw new AuthorizationException('You cannot assign this role.');
            }

            if ($locked->role === $role) {
                throw new DomainException("The account is already {$role->label()}.");
            }

            $old = $locked->role;
            $locked->forceFill(['role' => $role])->save();

            $this->audit->log('user.role_changed', $locked, ['role' => $old->value], [
                'role' => $role->value,
                'reason' => $reason,
            ], $actor);

            return $locked;
        });
    }

    /**
     * A frozen wallet cannot pay for orders. Deposits, refunds and admin adjustments still work.
     */
    public function setWalletFrozen(User $target, bool $frozen, User $actor, string $reason): Wallet
    {
        $this->authorize($actor, 'users.manage');
        $reason = $this->reason($reason);
        $wallet = $this->wallets->createWallet($target);

        return DB::transaction(function () use ($target, $frozen, $actor, $reason, $wallet) {
            $lockedUser = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManage($actor, $lockedUser);

            $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $to = $frozen ? WalletStatus::Frozen : WalletStatus::Active;

            if ($locked->status === $to) {
                throw new DomainException('The wallet is already '.$to->value.'.');
            }

            $old = $locked->status;
            $locked->forceFill(['status' => $to])->save();

            $this->audit->log($frozen ? 'wallet.frozen' : 'wallet.unfrozen', $locked, ['status' => $old->value], [
                'status' => $to->value,
                'user_id' => $lockedUser->id,
                'reason' => $reason,
            ], $actor);

            return $locked;
        });
    }

    // ---------------------------------------------------------------- helpers

    private function authorize(User $actor, string $permission): void
    {
        if (! Gate::forUser($actor)->allows($permission)) {
            throw new AuthorizationException('You do not have permission to do this.');
        }
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new AuthorizationException('You cannot do this to your own account.');
        }

        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException('You cannot manage an account of the same or a higher rank.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Please write a reason (at least 5 characters).');
        }

        return Str::limit($reason, 255, '');
    }
}