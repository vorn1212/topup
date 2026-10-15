<?php

namespace App\Services\TopUp;

use App\Exceptions\ProviderException;
use App\Notifications\ProviderBalanceLowNotification;
use App\Services\Notifications\StaffNotifier;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a recent copy of the FazerCards wallet balance.
 *
 *  - refresh() asks the provider (scheduler: every 10 minutes) and warns staff when it is low;
 *  - blocks() tells OrderService that an order would certainly fail for lack of provider funds,
 *    so the customer is refused BEFORE being charged.
 *
 * An unknown or old balance never blocks anything: only a fresh, clearly too low number does.
 */
class ProviderBalanceMonitor
{
    public const CACHE_KEY = 'fazercards:balance';

    private const ALERT_KEY = 'fazercards:low-balance-alerted';

    public function __construct(private readonly FazerCardsService $api)
    {
    }

    public function enabled(): bool
    {
        return ! config('fazercards.fake') && $this->api->isConfigured();
    }

    /**
     * @return array{balance:string,currency:string,checked_at:int}|null  the new snapshot, or null when the provider could not be asked
     */
    public function refresh(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $balance = $this->api->getBalance();
        } catch (ProviderException $e) {
            Log::warning('Could not read the FazerCards balance.', ['status' => $e->httpStatus]);

            return null;
        }

        $snapshot = [
            'balance' => $balance['balance'],
            'currency' => $balance['currency'],
            'checked_at' => now()->getTimestamp(),
        ];

        Cache::put(self::CACHE_KEY, $snapshot, now()->addHours(6));

        $limit = Money::normalize((string) config('fazercards.low_balance', '20.00'));

        if (Money::cmp($snapshot['balance'], $limit) < 0) {
            // One warning per 6 hours, not one per check.
            if (Cache::add(self::ALERT_KEY, 1, now()->addHours(6))) {
                StaffNotifier::send(new ProviderBalanceLowNotification($snapshot['balance'], $limit, $snapshot['currency']));
            }
        } else {
            Cache::forget(self::ALERT_KEY);
        }

        return $snapshot;
    }

    /**
     * @return array{balance:string,currency:string,checked_at:int}|null
     */
    public function snapshot(): ?array
    {
        $snapshot = Cache::get(self::CACHE_KEY);

        return is_array($snapshot) && isset($snapshot['balance'], $snapshot['checked_at']) ? $snapshot : null;
    }

    public function isLow(?array $snapshot = null): bool
    {
        $snapshot ??= $this->snapshot();

        return $snapshot !== null
            && Money::cmp($snapshot['balance'], Money::normalize((string) config('fazercards.low_balance', '20.00'))) < 0;
    }

    /**
     * Would an order costing $providerCost fail because the provider wallet is too low?
     */
    public function blocks(string $providerCost): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return false;
        }

        $maxAge = max(1, (int) config('fazercards.balance_max_age_minutes', 30)) * 60;

        if (now()->getTimestamp() - (int) $snapshot['checked_at'] > $maxAge) {
            return false;
        }

        return Money::cmp($snapshot['balance'], Money::normalize($providerCost)) < 0;
    }
}