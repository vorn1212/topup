<?php

namespace App\Services\TopUp;

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
use App\Contracts\TopUpProvider;

/**
 * Thin, defensive client for the FazerCards reseller API (v2).
 *
 * - The API key is read from config, sent only as the X-API-Key header, and never logged.
 * - GET requests are retried on connection errors and 429/502/503/504.
 * - POST requests (orders, validation) are NEVER retried here: the caller decides, and an
 *   order retry must reuse the same Idempotency-Key.
 * - Every response is validated before use.
 */
class FazerCardsService implements TopUpProvider
{
    public const ORDER_ID_PATTERN = '/^ord-\d{1,20}$/';

    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    private const MAX_PAGES = 50;

    private const PRICE = '/^\d{1,10}(\.\d{1,4})?$/';

    public function isConfigured(): bool
    {
        return filled(config('fazercards.api_key'));
    }

    // ---------------------------------------------------------------- account

    /** @return array<string,mixed> */
    public function getProfile(): array
    {
        return $this->get('/me');
    }

    /** @return array{balance:string,currency:string} */
    public function getBalance(): array
    {
        $body = $this->get('/balance');
        $balance = (string) ($body['balance'] ?? '');

        if (! preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $balance)) {
            throw new ProviderException('The provider returned an invalid balance.');
        }

        return [
            'balance' => Money::normalize($balance),
            'currency' => (string) ($body['currency'] ?? 'USD'),
        ];
    }

    // ---------------------------------------------------------------- catalog

    /**
     * All top-up categories (follows the provider's cursor pagination).
     *
     * @return list<array{category_id:string,name:string,note:string}>
     */
    public function getCategories(): array
    {
        $items = [];
        $cursor = null;
        $seen = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['limit' => 100] + ($cursor !== null ? ['cursor' => $cursor] : []);
            $body = $this->get('/topups', $query);

            foreach ((array) ($body['items'] ?? []) as $item) {
                $id = trim((string) ($item['category_id'] ?? ''));
                if ($id === '' || strlen($id) > 64) {
                    continue;
                }

                $items[$id] = [
                    'category_id' => $id,
                    'name' => Str::limit(trim((string) ($item['name'] ?? $id)), 160, ''),
                    'note' => Str::limit(trim((string) ($item['note'] ?? '')), 255, ''),
                ];
            }

            $meta = (array) ($body['meta'] ?? []);
            $next = isset($meta['next_cursor']) ? (string) $meta['next_cursor'] : null;

            if (! ($meta['has_more'] ?? false) || $next === null || $next === '' || isset($seen[$next])) {
                break;
            }

            $seen[$next] = true;
            $cursor = $next;
        }

        return array_values($items);
    }

    /**
     * Offers and buyer fields of one category. Malformed entries are skipped and counted.
     *
     * @return array{category_id:string,name:string,offers:list<array{offer_id:string,name:string,price_usd:string}>,fields:list<array{key:string,label:string,type:string}>,skipped:int}
     */
    public function getOffers(string $categoryId): array
    {
        $body = $this->get('/topups/offers', ['category_id' => $categoryId]);

        $offers = [];
        $skipped = 0;

        foreach ((array) ($body['offers'] ?? []) as $offer) {
            $id = trim((string) ($offer['offer_id'] ?? ''));
            $price = (string) ($offer['price_usd'] ?? '');

            if ($id === '' || strlen($id) > 64 || ! preg_match(self::PRICE, $price)) {
                $skipped++;

                continue;
            }

            $offers[$id] = [
                'offer_id' => $id,
                'name' => Str::limit(trim((string) ($offer['name'] ?? $id)), 160, ''),
                'price_usd' => Money::normalize($price),
            ];
        }

        return [
            'category_id' => $categoryId,
            'name' => Str::limit(trim((string) ($body['name'] ?? $categoryId)), 160, ''),
            'offers' => array_values($offers),
            'fields' => $this->parseFields((array) ($body['fields'] ?? [])),
            'skipped' => $skipped,
        ];
    }

    /**
     * Games that support Player ID validation, with the fields to send.
     *
     * @return list<array{category_id:string,name:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public function getValidatableGames(): array
    {
        $body = $this->get('/topups/validate-id');
        $games = [];

        foreach ((array) ($body['items'] ?? []) as $item) {
            $id = trim((string) ($item['category_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $games[] = [
                'category_id' => $id,
                'name' => Str::limit(trim((string) ($item['name'] ?? $id)), 160, ''),
                'fields' => $this->parseFields((array) ($item['fields'] ?? [])),
            ];
        }

        return $games;
    }

    // ---------------------------------------------------------------- player validation

    /**
     * @param  array<string,mixed>  $fields  provider field key => value
     * @return array{valid:bool,player_name:?string,region:?string}
     */
    public function validatePlayer(string $categoryId, array $fields): array
    {
        $body = $this->send(
            'POST',
            '/topups/validate-id',
            [],
            ['category_id' => $categoryId, 'fields' => (object) $this->cleanFields($fields)],
            [],
            false,
            (int) config('fazercards.validate_timeout', 10)
        );

        $name = trim((string) ($body['player_name'] ?? ''));
        $region = trim((string) ($body['region'] ?? ''));

        return [
            'valid' => ($body['valid'] ?? false) === true,
            'player_name' => $name !== '' ? Str::limit($name, 100, '') : null,
            'region' => $region !== '' ? Str::limit($region, 40, '') : null,
        ];
    }

    // ---------------------------------------------------------------- orders

    /**
     * Place a top-up order. NOT retried here. A caller that retries MUST pass the SAME
     * $idempotencyKey: the provider then returns the original order instead of charging again.
     *
     * @param  array<string,mixed>  $fields
     * @return array{id:string,status:string,raw:array<string,mixed>}
     *
     * @throws ProviderException (isDefinitiveRejection() tells "refused" from "unknown")
     */
    public function createOrder(string $categoryId, string $offerId, array $fields, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('The idempotency key must be 1-255 characters.');
        }

        $fields = $this->orderFields($categoryId, $fields);

        $body = $this->send(
            'POST',
            '/topups/order',
            [],
            ['category_id' => $categoryId, 'offer_id' => $offerId, 'fields' => (object) $this->cleanFields($fields)],
            ['Idempotency-Key' => $idempotencyKey],
            false,
            (int) config('fazercards.order_timeout', 30)
        );

        $order = (array) ($body['order'] ?? []);
        $id = (string) ($order['id'] ?? '');

        if (! preg_match(self::ORDER_ID_PATTERN, $id)) {
            // The provider answered 2xx but we cannot identify the order: unknown state, not a refusal.
            throw new ProviderException('The provider accepted the request but returned no valid order id.', 200);
        }

        return [
            'id' => $id,
            'status' => strtolower((string) ($order['status'] ?? 'processing')),
            'raw' => $order,
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

        $body = $this->get('/orders/'.$orderId);
        $order = (array) ($body['order'] ?? []);

        return [
            'id' => (string) ($order['id'] ?? $orderId),
            'status' => strtolower((string) ($order['status'] ?? '')),
            'raw' => $order,
        ];
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function getOrders(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $body = $this->get('/orders', ['page' => $page, 'limit' => $limit]);

        return [
            'items' => array_values((array) ($body['items'] ?? [])),
            'total' => (int) ($body['total'] ?? 0),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    // ---------------------------------------------------------------- transport

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, [], [], true, (int) config('fazercards.timeout', 15));
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $json
     * @param  array<string,string>  $headers
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query, array $json, array $headers, bool $retry, int $timeout): array
    {
        $attempts = $retry ? max(1, (int) config('fazercards.retries', 3)) : 1;
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = $this->client($timeout)->withHeaders($headers);
                $response = $method === 'GET' ? $client->get($path, $query) : $client->post($path, $json);
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    $this->pause(null);

                    continue;
                }

                Log::warning('FazerCards connection problem', ['method' => $method, 'path' => $path]);

                throw new ProviderTimeoutException('Could not reach FazerCards (timeout or connection error).');
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
        $key = (string) config('fazercards.api_key');

        if ($key === '') {
            throw new ProviderException('The FazerCards API key is not configured (FAZERCARDS_API_KEY).');
        }

        return Http::baseUrl(rtrim((string) config('fazercards.base_url'), '/'))
            ->withHeaders(['X-API-Key' => $key])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('fazercards.connect_timeout', 5))
            ->timeout($timeout);
    }

    /**
     * @return array<string,mixed>
     */
    private function parse(Response $response, string $method, string $path): array
    {
        $status = $response->status();
        $body = $response->json();

        if ($response->successful() && is_array($body) && ($body['ok'] ?? true) !== false) {
            return $body;
        }

        if ($response->successful() && ! is_array($body)) {
            throw new ProviderException('Unexpected (non-JSON) response from FazerCards.', $status);
        }

        $message = is_array($body) && isset($body['error']) && is_string($body['error']) && $body['error'] !== ''
            ? Str::limit($body['error'], 200, '')
            : "FazerCards returned HTTP {$status}.";
        $code = is_array($body) && isset($body['code']) && is_scalar($body['code']) ? (string) $body['code'] : null;

        Log::warning('FazerCards request failed', ['method' => $method, 'path' => $path, 'status' => $status, 'code' => $code, 'message' => $message]);

        throw new ProviderException($message, $status, $code);
    }

    private function pause(?string $retryAfter): void
    {
        $ms = (int) config('fazercards.retry_sleep_ms', 400);

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
     * @param  array<int|string,mixed>  $raw
     * @return list<array{key:string,label:string,type:string}>
     */
    private function parseFields(array $raw): array
    {
        $fields = [];

        foreach ($raw as $field) {
            $key = (string) ($field['key'] ?? '');
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,59}$/', $key)) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');

            $fields[] = [
                'key' => $key,
                'label' => Str::limit(trim((string) ($field['label'] ?? $key)), 80, ''),
                'type' => in_array($type, ['text', 'number', 'select'], true) ? $type : 'text',
            ];
        }

        return $fields;
    }

    /**
     * Mobile Legends orders name the server field "server_id", while the player check uses "zone_id".
     * Rename it for the order only (never overwrite a server_id that is already there).
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    private function orderFields(string $categoryId, array $fields): array
    {
        if (str_starts_with($categoryId, 'mobile_legends')
            && array_key_exists('zone_id', $fields)
            && ! array_key_exists('server_id', $fields)) {
            $fields['server_id'] = $fields['zone_id'];
            unset($fields['zone_id']);
        }

        return $fields;
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