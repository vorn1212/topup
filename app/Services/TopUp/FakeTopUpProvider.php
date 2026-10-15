<?php

namespace App\Services\TopUp;

use App\Contracts\TopUpProvider;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Local/testing stand-in for FazerCards. Spends no money. The FIRST player field decides the outcome:
 *   ends with 000 -> the provider refuses the order (customer is refunded)
 *   ends with 111 -> the order is created but the first answer is lost (timeout); a retry with the
 *                    same idempotency key returns the original order (the hardest real-world case)
 *   ends with 999 -> the order is accepted, then FAILS after 15 seconds (customer is refunded)
 *   anything else -> accepted, completes after 15 seconds
 */
class FakeTopUpProvider implements TopUpProvider
{
    private const DELAY_SECONDS = 15;

    public function isConfigured(): bool
    {
        return true;
    }

    public function createOrder(string $categoryId, string $offerId, array $fields, string $idempotencyKey): array
    {
        $existing = Cache::get('fake-provider:key:'.$idempotencyKey);

        if ($existing !== null) {
            return $this->shape($existing);
        }

        $player = (string) (array_values($fields)[0] ?? '');

        if (str_ends_with($player, '000')) {
            throw new ProviderException('Fake provider: this player was refused.', 400);
        }

        $id = $this->store($idempotencyKey, $player);

        if (str_ends_with($player, '111') && ! Cache::has('fake-provider:timeout:'.$idempotencyKey)) {
            Cache::put('fake-provider:timeout:'.$idempotencyKey, true, now()->addDay());

            throw new ProviderTimeoutException('Fake provider: the answer was lost (timeout).');
        }

        return $this->shape($id);
    }

    public function getOrder(string $orderId): array
    {
        if (! preg_match(FazerCardsService::ORDER_ID_PATTERN, $orderId)) {
            throw new \InvalidArgumentException('Invalid provider order id.');
        }

        $order = Cache::get('fake-provider:order:'.$orderId);

        if ($order === null) {
            throw new ProviderException('Fake provider: unknown order.', 404);
        }

        $age = now()->timestamp - $order['created_at'];
        $status = $age < self::DELAY_SECONDS ? 'processing' : $order['outcome'];

        return [
            'id' => $orderId,
            'status' => $status,
            'raw' => ['id' => $orderId, 'kind' => 'topup', 'status' => $status],
        ];
    }

    private function store(string $idempotencyKey, string $player): string
    {
        do {
            $id = 'ord-'.random_int(100000, 999999);
        } while (Cache::has('fake-provider:order:'.$id));

        Cache::put('fake-provider:order:'.$id, [
            'created_at' => now()->timestamp,
            'outcome' => str_ends_with($player, '999') ? 'failed' : 'completed',
        ], now()->addDay());
        Cache::put('fake-provider:key:'.$idempotencyKey, $id, now()->addDay());

        return $id;
    }

    /** @return array{id:string,status:string,raw:array<string,mixed>} */
    private function shape(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'processing',
            'raw' => ['id' => $id, 'kind' => 'topup', 'status' => 'processing'],
        ];
    }
}