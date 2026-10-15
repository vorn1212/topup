<?php

namespace App\Services\TopUp;

use App\Enums\OrderStatus;
use App\Enums\ProviderOrderStatus;
use App\Jobs\CheckFazerCardsOrderStatusJob;
use App\Jobs\ProcessFazerCardsOrderJob;
use App\Models\Order;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * What a human can do with an order that stopped automatic handling ("review"), or is stuck.
 * Every action locks the order and its provider order (always in that order), checks the state
 * again under the lock, and writes an audit entry with the staff member and the reason.
 *
 * Money rules:
 *  - re-check and re-send never move money (a re-send reuses the SAME idempotency key, so the
 *    provider can never deliver it twice);
 *  - "mark completed" never refunds;
 *  - "refund" can only be done once (RefundService is idempotent) and never on a completed order.
 */
class OrderRecoveryService
{
    /** Provider-order states a human may resolve. */
    private const RESOLVABLE = [
        ProviderOrderStatus::Review,
        ProviderOrderStatus::Unknown,
        ProviderOrderStatus::Processing,
    ];

    /** Order states that are still open. */
    private const OPEN = [OrderStatus::Pending, OrderStatus::Processing];

    public function __construct(
        private readonly RefundService $refunds,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Ask the provider again about an order it already knows. Puts it back into automatic polling.
     */
    public function recheck(ProviderOrder $providerOrder, User $actor): void
    {
        DB::transaction(function () use ($providerOrder, $actor) {
            [$order, $po] = $this->lock($providerOrder);

            if ($po->status !== ProviderOrderStatus::Review) {
                throw new DomainException('Only an order in review can be re-checked.');
            }

            if (blank($po->provider_order_id)) {
                throw new DomainException('The provider never gave this order an id. Use "Send again" instead.');
            }

            $this->mustBeOpen($order);

            $po->forceFill([
                'status' => ProviderOrderStatus::Processing,
                'next_check_at' => now(),
                'last_error' => null,
            ])->save();

            $this->audit->log('order.recheck_requested', $order, ['provider_status' => 'review'], [
                'provider_status' => 'processing',
                'order_number' => $order->order_number,
            ], $actor);
        });

        $this->dispatch(fn () => CheckFazerCardsOrderStatusJob::dispatch($providerOrder->id));
    }

    /**
     * Send an order the provider never confirmed. The SAME idempotency key is reused: if the first
     * attempt did reach the provider, it returns that order instead of creating a second one.
     */
    public function resend(ProviderOrder $providerOrder, User $actor): void
    {
        DB::transaction(function () use ($providerOrder, $actor) {
            [$order, $po] = $this->lock($providerOrder);

            if ($po->status !== ProviderOrderStatus::Review) {
                throw new DomainException('Only an order in review can be sent again.');
            }

            if (filled($po->provider_order_id)) {
                throw new DomainException('The provider already has this order. Use "Check again" instead.');
            }

            $this->mustBeOpen($order);

            $attempts = $po->attempts;

            $po->forceFill([
                'status' => ProviderOrderStatus::Unknown,
                'attempts' => 0,
                'next_check_at' => now(),
                'last_error' => null,
            ])->save();

            $this->audit->log('order.resend_requested', $order, ['attempts' => $attempts], [
                'order_number' => $order->order_number,
                'idempotency_key_reused' => true,
            ], $actor);
        });

        $this->dispatch(fn () => ProcessFazerCardsOrderJob::dispatch($providerOrder->id));
    }

    /**
     * Staff confirmed with the provider that the top-up was delivered.
     */
    public function markCompleted(ProviderOrder $providerOrder, User $actor, string $reason): void
    {
        $reason = $this->reason($reason);

        DB::transaction(function () use ($providerOrder, $actor, $reason) {
            [$order, $po] = $this->lock($providerOrder);

            $this->mustBeResolvable($po);
            $this->mustBeOpen($order);

            $before = ['provider_status' => $po->status->value, 'order_status' => $order->status->value];

            $po->forceFill([
                'status' => ProviderOrderStatus::Completed,
                'next_check_at' => null,
                'last_error' => null,
            ])->save();

            if ($order->status === OrderStatus::Pending) {
                OrderStateMachine::transition($order, OrderStatus::Processing);
            }

            OrderStateMachine::transition($order, OrderStatus::Completed);

            $this->audit->log('order.manually_completed', $order, $before, [
                'order_number' => $order->order_number,
                'reason' => $reason,
            ], $actor);
        });
    }

    /**
     * Staff confirmed with the provider that nothing was delivered: give the money back.
     */
    public function refund(ProviderOrder $providerOrder, User $actor, string $reason): void
    {
        $reason = $this->reason($reason);

        DB::transaction(function () use ($providerOrder, $actor, $reason) {
            [$order, $po] = $this->lock($providerOrder);

            $this->mustBeResolvable($po);

            if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing, OrderStatus::Failed, OrderStatus::Refunding], true)) {
                throw new DomainException("The order is {$order->status->value} and cannot be refunded.");
            }

            $before = ['provider_status' => $po->status->value, 'order_status' => $order->status->value];

            $po->forceFill([
                'status' => ProviderOrderStatus::Failed,
                'next_check_at' => null,
            ])->save();

            $this->refunds->failAndRefund($order, 'Refunded by staff: '.$reason);

            $this->audit->log('order.manually_refunded', $order, $before, [
                'order_number' => $order->order_number,
                'amount' => (string) $order->selling_price,
                'reason' => $reason,
            ], $actor);
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{0:Order,1:ProviderOrder}
     */
    private function lock(ProviderOrder $providerOrder): array
    {
        $order = Order::query()->whereKey($providerOrder->order_id)->lockForUpdate()->firstOrFail();
        $po = ProviderOrder::query()->whereKey($providerOrder->id)->lockForUpdate()->firstOrFail();

        return [$order, $po];
    }

    private function mustBeResolvable(ProviderOrder $po): void
    {
        if (! in_array($po->status, self::RESOLVABLE, true)) {
            throw new DomainException("The provider order is {$po->status->value}; there is nothing to resolve.");
        }
    }

    private function mustBeOpen(Order $order): void
    {
        if (! in_array($order->status, self::OPEN, true)) {
            throw new DomainException("The order is already {$order->status->value}.");
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

    /**
     * Queue work after the database change is saved. If the queue is down, the minute scheduler
     * (orders:process-pending) picks the order up anyway.
     */
    private function dispatch(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}