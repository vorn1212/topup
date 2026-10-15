<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\Wallet\WalletService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * role and status are intentionally NOT mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        // Every user gets exactly one wallet (registration, seeders, factories).
        static::created(function (User $user) {
            app(WalletService::class)->createWallet($user);
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function walletDeposits(): HasMany
    {
        return $this->hasMany(WalletDeposit::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function isBanned(): bool
    {
        return $this->status === UserStatus::Banned;
    }

    public function isStaff(): bool
    {
        return $this->role?->isStaff() ?? false;
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $value = $role instanceof UserRole ? $role->value : $role;
            if ($this->role?->value === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only active accounts can hold permissions.
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive() || $this->role === null) {
            return false;
        }

        $granted = config('permissions.roles.'.$this->role->value, []);

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /**
     * Can this user deposit, purchase or create orders?
     */
    public function canTransact(): bool
    {
        return $this->isActive();
    }
}