<?php

namespace App\Services\Checkout;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OrderRejectedException;
use App\Exceptions\PriceChangedException;
use App\Enums\DepositStatus;
use App\Jobs\PlaceCheckoutOrderJob;
use App\Jobs\RunPayWayLinkSessionJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletDeposit;
use App\Notifications\CheckoutOrderFailedNotification;
use App\Services\Notifications\StaffNotifier;
use App\Services\Payment\DepositService;
use App\Services\TopUp\OrderService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pay-by-QR checkout. The customer pays the exact price of one package by ABA QR; when the payment is
 * confirmed the money lands in the wallet of the paying account and the order is placed from it.
 * A visitor without an account gets a one-off hidden account that holds only this payment and order.
 */
class CheckoutService
{
    /** How long a refused order (for example "provider balance low") is retried before staff are told. */
    private const RETRY_MINUTES = 10;

    public function __construct(
        private readonly DepositService $deposits,
        private readonly OrderService $orders,
    ) {
    }

    /**
     * @param  array<string,string>  $fields  validated player fields
     */
    public function start(?User $user, Game $game, Product $product, array $fields, ?string $ip): WalletDeposit
    {
        $user ??= $this->makeGuest();

        // A package can cost less than the normal minimum deposit: this payment is exactly the package price.
        config(['topup.deposit.min' => '0.01']);

        $deposit = $this->deposits->create(
            $user,
            Money::normalize((string) $product->selling_price),
            DepositService::METHOD_PAYWAY_LINK,
        );

        $deposit->metadata = array_merge($deposit->metadata ?? [], ['checkout' => [
            'token' => Str::random(40),
            'game' => $game->slug,
            'game_name' => $game->name,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'fields' => $fields,
            'ip' => $ip,
            'guest' => $this->isGuest($user),
        ]]);
        $deposit->save();

        RunPayWayLinkSessionJob::dispatch($deposit->id);
        // Places the order even if the customer closes the page after paying.
        PlaceCheckoutOrderJob::dispatch($deposit->id)->delay(now()->addSeconds(15));

        return $deposit;
    }

    public function isGuest(User $user): bool
    {
        return str_ends_with((string) $user->email, '@'.User::GUEST_DOMAIN);
    }

    private function makeGuest(): User
    {
        $user = new User([
            'name' => 'Guest',
            'email' => Str::lower(Str::random(24)).'@'.User::GUEST_DOMAIN,
            'password' => Str::random(48),
        ]);
        $user->forceFill(['role' => 'user', 'status' => 'active'])->save();

        return $user;
    }

    /**
     * Place the order of a paid checkout. Safe to call again and again: the order token makes the
     * order service return the same order, and a failure is recorded once.
     */
    public function placeOrder(int $depositId): ?Order
    {
        $deposit = WalletDeposit::query()->find($depositId);
        $checkout = $deposit?->metadata['checkout'] ?? null;

        if (! $deposit || ! $checkout || $deposit->status !== DepositStatus::Paid) {
            return null;
        }

        if (! empty($checkout['error'])) {
            return null;
        }

        $user = User::query()->find($deposit->user_id);

        try {
            $order = $this->orders->create(
                $user,
                (string) $checkout['game'],
                (int) $checkout['product_id'],
                (array) $checkout['fields'],
                (string) $checkout['token'],
                null,
                $checkout['ip'] ?? null,
            );
        } catch (OrderRejectedException $e) {
            // Often temporary (provider balance, package switched off): keep trying for a few minutes.
            if ($deposit->paid_at && $deposit->paid_at->gt(now()->subMinutes(self::RETRY_MINUTES))) {
                return null;
            }

            $this->fail($deposit, $e->getMessage());

            return null;
        } catch (InsufficientBalanceException|PriceChangedException|ValidationException $e) {
            $this->fail($deposit, $e instanceof ValidationException ? 'The player details are not valid.' : 'The package price changed after payment.');

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $this->remember($deposit->id, ['order_number' => $order->order_number]);

        return $order;
    }

    /** True while a paid checkout still has to place its order (the job keeps trying). */
    public function waitingForOrder(WalletDeposit $deposit): bool
    {
        $c = $deposit->metadata['checkout'] ?? null;

        return $c !== null && $deposit->status === DepositStatus::Paid && empty($c['order_number']) && empty($c['error']);
    }

    private function fail(WalletDeposit $deposit, string $reason): void
    {
        $first = false;

        DB::transaction(function () use ($deposit, $reason, &$first) {
            $locked = WalletDeposit::query()->whereKey($deposit->id)->lockForUpdate()->first();
            $meta = $locked->metadata ?? [];

            if (! empty($meta['checkout']['error'])) {
                return;
            }

            $meta['checkout']['error'] = $reason;
            $locked->metadata = $meta;
            $locked->save();
            $first = true;
        });

        if ($first) {
            StaffNotifier::send(new CheckoutOrderFailedNotification(
                $deposit->deposit_number,
                Money::format((string) $deposit->amount, (string) $deposit->currency),
                $reason,
            ));
        }
    }

    /** @param array<string,mixed> $values */
    private function remember(int $depositId, array $values): void
    {
        DB::transaction(function () use ($depositId, $values) {
            $locked = WalletDeposit::query()->whereKey($depositId)->lockForUpdate()->firstOrFail();
            $meta = $locked->metadata ?? [];
            $meta['checkout'] = array_merge($meta['checkout'] ?? [], $values);
            $locked->metadata = $meta;
            $locked->save();
        });
    }
}
