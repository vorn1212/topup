<?php

namespace App\Services\TopUp;

use App\Contracts\TopUpProvider;
use App\Enums\OrderStatus;
use App\Enums\ProviderOrderStatus;
use App\Exceptions\ProviderException;
use App\Models\Order;
use App\Models\ProviderOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends orders to the provider and tracks them. Rules that protect money:
 *  - the provider is called OUTSIDE any database lock or transaction;
 *  - every (re)send of an order uses the SAME idempotency key;
 *  - a timeout or server error is never treated as a failure (no refund);
 *  - only a clear refusal on the FIRST attempt, or a provider "failed" status, refunds the customer;
 *  - anything odd after an unclear attempt goes to "review" for a human.
 */
class OrderProcessor
{
    public const MAX_SUBMIT_ATTEMPTS = 8;

    private const SUBMIT_BACKOFF = [30, 120, 300, 900, 1800, 3600];

    private const STUCK_AFTER_HOURS = 6;

    private const COMPLETED = ['completed', 'complete', 'done', 'success', 'succeeded', 'delivered'];

    private const FAILED = ['failed', 'fail', 'error', 'canceled', 'cancelled', 'rejected', 'refund', 'refunded'];

    public function __construct(
        private readonly TopUpProvider $provider,
        private readonly RefundService $refunds,
    ) {
    }

    // ---------------------------------------------------------------- submit

    public function submit(int $providerOrderId): void
    {
        $claim = $this->claim($providerOrderId);

        if ($claim === null) {
            return;
        }

        [$categoryId, $offerId, $fields, $key, $attempt] = $claim;

        try {
            $result = $this->provider->createOrder($categoryId, $offerId, $fields, $key);
        } catch (Throwable $e) {
            $this->submitFailed($providerOrderId, $e, $attempt);

            return;
        }

        $this->submitted($providerOrderId, $result);
    }

    /**
     * Take the order for sending. Returns null when it is not ours to send.
     *
     * @return array{0:string,1:string,2:array<string,mixed>,3:string,4:int}|null
     */
    private function claim(int $providerOrderId): ?array
    {
        $orderId = ProviderOrder::query()->whereKey($providerOrderId)->value('order_id');

        if ($orderId === null) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $providerOrderId) {
            [$order, $po] = $this->lockPair((int) $orderId, $providerOrderId);

            if (! $order || ! $po || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], true)) {
                return null;
            }

            $stale = $po->status === ProviderOrderStatus::Submitting
                && $po->updated_at !== null
                && $po->updated_at->lte(now()->subMinutes(3));

            if (! in_array($po->status, [ProviderOrderStatus::Queued, ProviderOrderStatus::Unknown], true) && ! $stale) {
                return null;
            }

            $po->forceFill([
                'status' => ProviderOrderStatus::Submitting,
                'attempts' => $po->attempts + 1,
            ])->save();

            return [
                $po->category_id,
                $po->offer_id,
                (array) ($po->request_payload['fields'] ?? []),
                $po->idempotency_key,
                $po->attempts,
            ];
        });
    }

    /**
     * @param  array{id:string,status:string,raw:array<string,mixed>}  $result
     */
    private function submitted(int $providerOrderId, array $result): void
    {
        $orderId = (int) ProviderOrder::query()->whereKey($providerOrderId)->value('order_id');

        DB::transaction(function () use ($orderId, $providerOrderId, $result) {
            [$order, $po] = $this->lockPair($orderId, $providerOrderId);

            if (! $order || ! $po) {
                return;
            }

            $state = $this->interpret($result['status']);

            $po->forceFill([
                'provider_order_id' => $result['id'],
                'response_payload' => $result['raw'],
                'submitted_at' => now(),
                'last_checked_at' => now(),
                'last_error' => null,
                'next_check_at' => now()->addSeconds(20),
                'status' => match ($state) {
                    'completed' => ProviderOrderStatus::Completed,
                    'failed' => ProviderOrderStatus::Failed,
                    default => ProviderOrderStatus::Processing,
                },
            ])->save();

            $this->advance($order, $state);
        });
    }

    private function submitFailed(int $providerOrderId, Throwable $e, int $attempt): void
    {
        $definitive = $e instanceof ProviderException && $e->isDefinitiveRejection();
        $message = Str::limit($e->getMessage(), 255, '');

        if (! $e instanceof ProviderException) {
            report($e);
        } elseif ($e->isAuthError()) {
            Log::critical('FazerCards rejected our API key while submitting an order.', ['provider_order' => $providerOrderId]);
        }

        $orderId = (int) ProviderOrder::query()->whereKey($providerOrderId)->value('order_id');

        DB::transaction(function () use ($orderId, $providerOrderId, $definitive, $message, $attempt) {
            [$order, $po] = $this->lockPair($orderId, $providerOrderId);

            // Someone else already resolved it while we were talking to the provider.
            if (! $order || ! $po || $po->status !== ProviderOrderStatus::Submitting) {
                return;
            }

            // A clear refusal of the very first attempt: no order exists at the provider.
            if ($definitive && $attempt === 1) {
                $po->forceFill(['status' => ProviderOrderStatus::Failed, 'last_error' => $message])->save();
                $this->refunds->failAndRefund($order, 'The provider rejected the top-up.');

                return;
            }

            if ($order->status === OrderStatus::Pending) {
                OrderStateMachine::transition($order, OrderStatus::Processing);
            }

            // After an unclear attempt, a refusal may only mean "this exact order already exists":
            // never refund automatically. Same when we simply ran out of attempts.
            if ($definitive || $po->attempts >= self::MAX_SUBMIT_ATTEMPTS) {
                $po->forceFill([
                    'status' => ProviderOrderStatus::Review,
                    'last_error' => $message,
                    'next_check_at' => null,
                ])->save();

                Log::critical('Order needs manual review', [
                    'order' => $order->order_number,
                    'attempts' => $po->attempts,
                    'reason' => $message,
                ]);

                return;
            }

            $delay = self::SUBMIT_BACKOFF[min($po->attempts - 1, count(self::SUBMIT_BACKOFF) - 1)];

            $po->forceFill([
                'status' => ProviderOrderStatus::Unknown,
                'last_error' => $message,
                'next_check_at' => now()->addSeconds($delay),
            ])->save();
        });
    }

    // ---------------------------------------------------------------- status checks

    public function check(int $providerOrderId): void
    {
        $po = ProviderOrder::query()->find($providerOrderId);

        if (! $po || $po->status !== ProviderOrderStatus::Processing || blank($po->provider_order_id)) {
            return;
        }

        try {
            $remote = $this->provider->getOrder($po->provider_order_id);
        } catch (Throwable $e) {
            $this->checkFailed($providerOrderId, $e);

            return;
        }

        $orderId = (int) $po->order_id;

        DB::transaction(function () use ($orderId, $providerOrderId, $remote) {
            [$order, $po] = $this->lockPair($orderId, $providerOrderId);

            if (! $order || ! $po || $po->status !== ProviderOrderStatus::Processing) {
                return;
            }

            $state = $this->interpret($remote['status']);

            $po->last_checked_at = now();
            if ($remote['raw'] !== []) {
                $po->response_payload = $remote['raw'];
            }

            if ($state === 'processing') {
                if ($po->created_at !== null && $po->created_at->lte(now()->subHours(self::STUCK_AFTER_HOURS))) {
                    $po->status = ProviderOrderStatus::Review;
                    $po->next_check_at = null;
                    $po->save();

                    Log::critical('Order has been processing too long: manual review needed.', ['order' => $order->order_number]);

                    return;
                }

                $po->next_check_at = now()->addSeconds($this->checkDelay($po));
                $po->save();

                return;
            }

            $po->status = $state === 'completed' ? ProviderOrderStatus::Completed : ProviderOrderStatus::Failed;
            $po->next_check_at = null;
            $po->save();

            $this->advance($order, $state);
        });
    }

    private function checkFailed(int $providerOrderId, Throwable $e): void
    {
        if ($e instanceof ProviderException && $e->isAuthError()) {
            Log::critical('FazerCards rejected our API key while checking an order.', ['provider_order' => $providerOrderId]);
        } elseif (! $e instanceof ProviderException) {
            report($e);
        }

        // Keep it processing and look again later. A failed lookup is never a failed order.
        ProviderOrder::query()
            ->whereKey($providerOrderId)
            ->where('status', ProviderOrderStatus::Processing->value)
            ->update([
                'last_checked_at' => now(),
                'last_error' => Str::limit($e->getMessage(), 255, ''),
                'next_check_at' => now()->addMinutes(2),
            ]);
    }

    private function checkDelay(ProviderOrder $po): int
    {
        $age = $po->created_at ? (int) $po->created_at->diffInSeconds(now()) : 0;

        return match (true) {
            $age < 120 => 20,
            $age < 600 => 60,
            default => 300,
        };
    }

    // ---------------------------------------------------------------- shared

    /**
     * Lock the order, then its provider order (always in this order). Call inside a transaction.
     *
     * @return array{0:?Order,1:?ProviderOrder}
     */
    private function lockPair(int $orderId, int $providerOrderId): array
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        $po = ProviderOrder::query()->whereKey($providerOrderId)->lockForUpdate()->first();

        return [$order, $po];
    }

    /**
     * Move the order along for a provider state: completed, failed (refund) or still processing.
     */
    private function advance(Order $order, string $state): void
    {
        if ($order->status === OrderStatus::Pending) {
            OrderStateMachine::transition($order, OrderStatus::Processing);
        }

        if ($state === 'completed' && $order->status === OrderStatus::Processing) {
            OrderStateMachine::transition($order, OrderStatus::Completed);
        } elseif ($state === 'failed') {
            $this->refunds->failAndRefund($order, 'The provider could not complete the order.');
        }
    }

    /**
     * @return 'completed'|'failed'|'processing'
     */
    private function interpret(string $status): string
    {
        $status = strtolower(trim($status));

        if (in_array($status, self::COMPLETED, true)) {
            return 'completed';
        }

        if (in_array($status, self::FAILED, true)) {
            return 'failed';
        }

        return 'processing'; // including anything we do not recognise: never guess "failed"
    }
}