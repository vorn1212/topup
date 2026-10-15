<?php

namespace App\Services\TopUp;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RefundService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Mark an order failed and give the money back. Safe to call any number of times:
     * the wallet credit uses the fixed key refund:order:{id}, and a refunded order returns early.
     * A completed order can never be refunded here.
     */
    public function failAndRefund(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Refunded) {
                return $locked;
            }

            if (in_array($locked->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                throw new LogicException("Order {$locked->order_number} is {$locked->status->value} and cannot be refunded.");
            }

            if (in_array($locked->status, [OrderStatus::Pending, OrderStatus::Processing], true)) {
                OrderStateMachine::transition($locked, OrderStatus::Failed, [
                    'failure_reason' => Str::limit($reason, 255, ''),
                ]);
            }

            if ($locked->status === OrderStatus::Failed) {
                OrderStateMachine::transition($locked, OrderStatus::Refunding);
            }

            $wallet = Wallet::query()->where('user_id', $locked->user_id)->firstOrFail();

            $this->wallets->refund(
                $wallet,
                (string) $locked->selling_price,
                'refund:order:'.$locked->id,
                $locked,
                "Refund for order {$locked->order_number}",
            );

            OrderStateMachine::transition($locked, OrderStatus::Refunded);

            $this->audit->log('order.refunded', $locked, [], [
                'amount' => (string) $locked->selling_price,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }
}