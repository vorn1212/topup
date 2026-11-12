<?php

namespace App\Services\TopUp;

use App\Contracts\TopUpProvider;
use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTimeoutException;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Thin, defensive client for the RechargeGames reseller API (v1).
 *
 * - The API key is sent only as the X-API-Key header and never logged.
 * - GET requests are retried on connection errors and 429/502/503/504.
 * - POST /v1/orders is NEVER retried here. A retry by the caller reuses the same buyer_ref
 *   (our idempotency key): the provider then returns the original order instead of a second one.
 *
 * Mapping to our catalog:
 *   category id = "<category slug>/<region slug>"   e.g. free-fire/brazil
 *   offer id    = the provider's product_key        e.g. topup/free-fire/brazil/100 Diamonds
 */
class RechargeGamesService implements TopUpProvider
{
    public const ORDER_ID_PATTERN = '/^ord-[A-Za-z0-9]{4,24}$/i';

    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    private const NUMBER = '/^\d{1,10}(\.\d+)?$/';

    public function isConfigured(): bool
    {
        return filled(config('rechargegames.api_key'));
    }

    // ---------------------------------------------------------------- account

    /**
     * Shaped like the old provider profile so the admin page and commands keep working.
     *
     * @return array{login:string,plan:string,subscriptionActive:bool,planExpiresAt:null}
     */
    public function getProfile(): array
    {
        $body = $this->get('/v1/ping');

        return [
            'login' => (string) ($body['buyer'] ?? '?'),
            'plan' => 'RechargeGames',
            'subscriptionActive' => ($body['ok'] ?? false) === true,
            'planExpiresAt' => null,
        ];
    }

    /** @return array{balance:string,currency:string} USDT is treated as USD (1:1). */
    public function getBalance(): array
    {
        $body = $this->get('/v1/balance');
        $balance = $this->money($body['balance_usdt'] ?? null, false);

        if ($balance === null) {
            throw new ProviderException('The provider returned an invalid balance.');
        }

        return ['balance' => $balance, 'currency' => 'USD'];
    }

    // ---------------------------------------------------------------- catalog

    /**
     * Every category + region pair. One call (the full catalog is large, so it uses the catalog timeout).
     *
     * @return list<array{category_id:string,name:string,note:string}>
     */
    public function getCategories(): array
    {
        $body = $this->get('/v1/products', ['all' => 'true'], (int) config('rechargegames.catalog_timeout', 60));
        $items = [];

        foreach ((array) ($body['categories'] ?? []) as $category) {
            $slug = trim((string) ($category['slug'] ?? ''));

            if ($slug === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
                continue;
            }

            $name = trim((string) ($category['name'] ?? $slug));
            $kind = (string) ($category['kind'] ?? 'topup');

            foreach ((array) ($category['groups'] ?? []) as $group) {
                $region = trim((string) ($group['slug'] ?? ''));
                $id = $slug.'/'.$region;

                if ($region === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $region) || strlen($id) > 64) {
                    continue;
                }

                $items[$id] = [
                    'category_id' => $id,
                    'name' => Str::limit($name.' ('.Str::headline($region).')', 160, ''),
                    'note' => $kind,
                ];
            }
        }

        return array_values($items);
    }

    /**
     * Offers of one category + region. Out-of-stock offers are left out, so linked products become
     * unavailable until they are back in stock.
     *
     * @return array{category_id:string,name:string,offers:list<array{offer_id:string,name:string,price_usd:string}>,fields:list<array{key:string,label:string,type:string}>,skipped:int}
     */
    public function getOffers(string $categoryId): array
    {
        [$slug, $region] = $this->splitCategory($categoryId);

        $body = $this->get('/v1/products', ['game' => $slug]);

        $category = null;
        foreach ((array) ($body['categories'] ?? []) as $candidate) {
            if (($candidate['slug'] ?? null) === $slug) {
                $category = (array) $candidate;
                break;
            }
        }

        if ($category === null) {
            throw new ProviderException("The category \"{$slug}\" was not found at the provider.", 404, 'not_found');
        }

        $offers = [];
        $skipped = 0;
        $needsPlayer = false;

        foreach ((array) ($category['groups'] ?? []) as $group) {
            if (($group['slug'] ?? null) !== $region) {
                continue;
            }

            foreach ((array) ($group['products'] ?? []) as $product) {
                if (($product['delivery'] ?? 'player_id') === 'player_id') {
                    $needsPlayer = true;
                }

                if (($product['out_of_stock'] ?? false) === true) {
                    continue;
                }

                $key = trim((string) ($product['product_key'] ?? ''));
                $price = $this->money($product['price'] ?? null, true);

                if ($key === '' || strlen($key) > 255 || $price === null) {
                    $skipped++;

                    continue;
                }

                $offers[$key] = [
                    'offer_id' => $key,
                    'name' => Str::limit(trim((string) ($product['name'] ?? $key)), 160, ''),
                    'price_usd' => $price,
                ];
            }
        }

        $fields = [];

        if ($needsPlayer) {
            $fields[] = ['key' => 'player_id', 'label' => 'Player ID', 'type' => 'text'];

            if (str_contains($slug, 'mobile-legends')) {
                $fields[] = ['key' => 'server_id', 'label' => 'Server ID', 'type' => 'text'];
            }
        }

        return [
            'category_id' => $categoryId,
            'name' => Str::limit(trim((string) ($category['name'] ?? $slug)).' ('.Str::headline($region).')', 160, ''),
            'offers' => array_values($offers),
            'fields' => $fields,
            'skipped' => $skipped,
        ];
    }

    // ---------------------------------------------------------------- player validation

    /**
     * RechargeGames has no general Player ID check, so the site's player check is switched off.
     *
     * @return list<array{category_id:string,name:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public function getValidatableGames(): array
    {
        return [];
    }

    /**
     * @param  array<string,mixed>  $fields
     * @return array{valid:bool,player_name:?string,region:?string}
     */
    public function validatePlayer(string $categoryId, array $fields): array
    {
        throw new ProviderException('RechargeGames does not offer a Player ID check.');
    }

    // ---------------------------------------------------------------- orders

    /**
     * Place an order. NOT retried here. The idempotency key is sent as buyer_ref.
     *
     * @param  array<string,mixed>  $fields
     * @return array{id:string,status:string,raw:array<string,mixed>}
     *
     * @throws ProviderException (isDefinitiveRejection() tells "refused" from "unknown")
     */
    public function createOrder(string $categoryId, string $offerId, array $fields, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 200) {
            throw new InvalidArgumentException('The idempotency key must be 1-200 characters.');
        }

        [, $region] = $this->splitCategory($categoryId);

        // product_key = <kind>/<category>/<region>/<product name>
        $name = explode('/', $offerId, 4)[3] ?? $offerId;

        $payload = [
            'product' => Str::limit($name, 500, ''),
            'product_key' => $offerId,
            'buyer_ref' => $idempotencyKey,
            'region' => $region,
            'quantity' => 1,
        ];

        $clean = $this->cleanFields($fields);

        foreach (['player_id', 'server_id'] as $key) {
            if (isset($clean[$key]) && $clean[$key] !== '') {
                $payload[$key] = $clean[$key];
            }
        }

        if (config('rechargegames.test_orders')) {
            $payload['test'] = true;
        }

        $body = $this->send('POST', '/v1/orders', [], $payload, false, (int) config('rechargegames.order_timeout', 20));

        $id = (string) ($body['order_id'] ?? '');

        if (! preg_match(self::ORDER_ID_PATTERN, $id)) {
            // 2xx but we cannot identify the order: unknown state, not a refusal.
            throw new ProviderException('The provider accepted the request but returned no valid order id.', 200);
        }

        return [
            'id' => $id,
            'status' => strtolower((string) ($body['status'] ?? 'pending')),
            'raw' => $body,
        ];
    }

    /**
     * @return array{id:string,status:string,raw:array<string,mixed>}
     */
    public function getOrder(string $orderId): array
    {
        if (! preg_match(self::ORDER_ID_PATTERN, $orderId)) {
            throw new InvalidArgumentException('Invalid provider order id.');
        }

        $body = $this->get('/v1/orders/'.$orderId);

        return [
            'id' => (string) ($body['order_id'] ?? $orderId),
            'status' => strtolower((string) ($body['status'] ?? '')),
            'raw' => $body,
        ];
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function getOrders(int $page = 1, int $limit = 20): array
    {
        $limit = min(500, max(1, $limit));
        $body = $this->get('/v1/orders', ['limit' => $limit]);
        $items = array_values((array) ($body['orders'] ?? $body['items'] ?? (array_is_list($body) ? $body : [])));

        return ['items' => $items, 'total' => count($items), 'page' => max(1, $page), 'limit' => $limit];
    }

    // ---------------------------------------------------------------- transport

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = [], ?int $timeout = null): array
    {
        return $this->send('GET', $path, $query, [], true, $timeout ?? (int) config('rechargegames.timeout', 15));
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query, array $json, bool $retry, int $timeout): array
    {
        $attempts = $retry ? max(1, (int) config('rechargegames.retries', 3)) : 1;
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = $this->client($timeout);
                $response = $method === 'GET' ? $client->get($path, $query) : $client->post($path, $json);
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    $this->pause(null);

                    continue;
                }

                Log::warning('RechargeGames connection problem', ['method' => $method, 'path' => $path]);

                throw new ProviderTimeoutException('Could not reach RechargeGames (timeout or connection error).');
            }

            if ($attempt < $attempts && in_array($response->status(), self::RETRYABLE_STATUS, true)) {
                $this->pause($response->header('Retry-After'));

                continue;
            }

            break;
        }

        return $this->parse($response, $method, $path);
    }

    private function client(int $timeout): PendingRequest
    {
        $key = (string) config('rechargegames.api_key');

        if ($key === '') {
            throw new ProviderException('The RechargeGames API key is not configured (RECHARGEGAMES_API_KEY).');
        }

        return Http::baseUrl(rtrim((string) config('rechargegames.base_url'), '/'))
            ->withHeaders(['X-API-Key' => $key])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('rechargegames.connect_timeout', 5))
            ->timeout($timeout);
    }

    /**
     * @return array<string,mixed>
     */
    private function parse(Response $response, string $method, string $path): array
    {
        $status = $response->status();
        $body = $response->json();

        if ($response->successful() && is_array($body)) {
            return $body;
        }

        if ($response->successful()) {
            throw new ProviderException('Unexpected (non-JSON) response from RechargeGames.', $status);
        }

        $error = is_array($body) ? ($body['error'] ?? null) : null;
        $message = is_array($error) && is_string($error['message'] ?? null) && $error['message'] !== ''
            ? Str::limit($error['message'], 200, '')
            : (is_string($error) && $error !== '' ? Str::limit($error, 200, '') : "RechargeGames returned HTTP {$status}.");
        $code = is_array($error) && is_scalar($error['code'] ?? null) ? (string) $error['code'] : null;

        Log::warning('RechargeGames request failed', ['method' => $method, 'path' => $path, 'status' => $status, 'code' => $code]);

        throw new ProviderException($message, $status, $code);
    }

    private function pause(?string $retryAfter): void
    {
        $ms = (int) config('rechargegames.retry_sleep_ms', 400);

        if ($ms <= 0) {
            return;
        }

        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            $ms = max($ms, min((int) $retryAfter, 10) * 1000);
        }

        usleep($ms * 1000);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array{0:string,1:string}
     */
    private function splitCategory(string $categoryId): array
    {
        $parts = explode('/', $categoryId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ProviderException("\"{$categoryId}\" is not a RechargeGames category (expected slug/region). Link the game again.", 422, 'invalid_request');
        }

        return [$parts[0], $parts[1]];
    }

    /** Positive money with at most 4 decimals (rounded UP, so the cost is never understated). */
    private function money(mixed $value, bool $roundUp): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }

        $value = trim((string) $value);

        if (! preg_match(self::NUMBER, $value)) {
            return null;
        }

        $number = (float) $value;
        $scaled = round($number * 10000, 6);
        $scaled = $roundUp ? ceil($scaled) : floor($scaled);

        return Money::normalize(number_format($scaled / 10000, 4, '.', ''));
    }

    /**
     * @param  array<int|string,mixed>  $fields
     * @return array<string,string>
     */
    private function cleanFields(array $fields): array
    {
        $clean = [];

        foreach ($fields as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $clean[$key] = Str::limit(trim((string) $value), 100, '');
            }
        }

        return $clean;
    }
}
