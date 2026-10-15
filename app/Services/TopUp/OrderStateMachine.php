<?php

namespace App\Services\TopUp;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\AuditService;
use LogicException;

/**
 * The only place an order's status changes. Illegal transitions throw.
 * The order passed in MUST be a freshly locked row (lockForUpdate) inside a transaction.
 */
final class OrderStateMachine
{
    /** @var array<string,list<OrderStatus>> */
    private const ALLOWED = [
        'pending' => [OrderStatus::Processing, OrderStatus::Failed, OrderStatus::Cancelled],
        'processing' => [OrderStatus::Completed, OrderStatus::Failed],
        'failed' => [OrderStatus::Refunding],
        'refunding' => [OrderStatus::Refunded],
        'completed' => [],
        'refunded' => [],
        'cancelled' => [],
    ];

    public static function can(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * @param  array<string,mixed>  $attributes  extra columns to set together with the status
     */
    public static function transition(Order $order, OrderStatus $to, array $attributes = []): Order
    {
        $from = $order->status;

        if (! self::can($from, $to)) {
            throw new LogicException("Illegal order transition {$from->value} -> {$to->value} (order {$order->order_number}).");
        }

        $order->forceFill(array_merge($attributes, ['status' => $to]));

        if ($to === OrderStatus::Completed && $order->completed_at === null) {
            $order->completed_at = now();
        }

        $order->save();

        app(AuditService::class)->log('order.status_changed', $order, ['status' => $from->value], [
            'status' => $to->value,
            'order_number' => $order->order_number,
        ]);

        return $order;
    }
}