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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Client for the Hariki TopUp reseller API.
 *
 * - Key goes only in the X-API-Key header and is never logged.
 * - POST /api/v1/order is never retried; a retry by the caller reuses the same ref_order.
 * - Hariki has NO polling: final status arrives by webhook. GET /order/{ref} is an audit check only.
 *
 * Catalog mapping: category id = game_code (mlbb), offer id = product_code (mlbb_275).
 */
class HarikiService implements TopUpProvider
{
    /** Our provider order id is the ref_order we send (our idempotency key). */
    public const ORDER_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/';

    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    public function isConfigured(): bool
    {
        return filled(config('hariki.api_key'));
    }

    /** @return array{login:string,plan:string,subscriptionActive:bool,planExpiresAt:null} */
    public function getProfile(): array
    {
        $data = $this->data($this->send('GET', '/api/v1/profile'));

        $sub = is_array($data['subscription'] ?? null) ? $data['subscription'] : [];

        return [
            'login' => (string) ($data['email'] ?? $data['reseller_id'] ?? 'Hariki'),
            'plan' => (string) ($sub['plan'] ?? 'Hariki TopUp'),
            'subscriptionActive' => ($sub['active'] ?? true) === true,
            'planExpiresAt' => null,
        ];
    }

    /** @return array{balance:string,currency:string} */
    public function getBalance(): array
    {
        $data = $this->data($this->send('GET', '/api/v1/profile'));

        foreach (['balance', 'balance_usd', 'wallet_balance', 'credit'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return ['balance' => Money::normalize(number_format((float) $data[$key], 4, '.', '')), 'currency' => 'USD'];
            }
        }

        throw new ProviderException('The provider profile has no balance field.');
    }

    /** @return list<array{category_id:string,name:string,note:string}> */
    public function getCategories(): array
    {
        $games = [];

        foreach ($this->products(null) as $p) {
            $games[$p['game_code']] = ($games[$p['game_code']] ?? 0) + 1;
        }

        $items = [];
        foreach ($games as $code => $count) {
            $items[] = ['category_id' => $code, 'name' => Str::upper($code), 'note' => $count.' products'];
        }

        return $items;
    }

    /** @return array{category_id:string,name:string,offers:list<array{offer_id:string,name:string,price_usd:string}>,fields:list<array{key:string,label:string,type:string}>,skipped:int} */
    public function getOffers(string $categoryId): array
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $categoryId)) {
            throw new InvalidArgumentException('Invalid game code.');
        }

        $offers = [];
        $skipped = 0;

        foreach ($this->products($categoryId) as $p) {
            if ($p['game_code'] !== $categoryId) {
                continue;
            }

            if (strtolower((string) ($p['status'] ?? 'active')) !== 'active') {
                continue; // unavailable offers drop out, linked products become unavailable
            }

            $price = is_numeric($p['price'] ?? null)
                ? Money::normalize(number_format(ceil(((float) $p['price'] + (float) config('hariki.fee_per_order', 0)) * 10000 - 0.000001) / 10000, 4, '.', ''))
                : null;

            if ($price === null || $p['product_code'] === '') {
                $skipped++;

                continue;
            }

            $offers[$p['product_code']] = [
                'offer_id' => $p['product_code'],
                'name' => Str::limit(trim((string) ($p['name'] ?? $p['product_code'])), 160, ''),
                'price_usd' => $price,
            ];
        }

        $fields = [['key' => 'player_id', 'label' => 'Player ID', 'type' => 'text']];

        if (in_array($categoryId, (array) config('hariki.server_games'), true)) {
            $fields[] = ['key' => 'server_id', 'label' => 'Server ID', 'type' => 'text'];
        }

        return [
            'category_id' => $categoryId,
            'name' => Str::upper($categoryId),
            'offers' => array_values($offers),
            'fields' => $fields,
            'skipped' => $skipped,
        ];
    }

    /** @return list<array{category_id:string,name:string,fields:list<array{key:string,label:string,type:string}>}> */
    public function getValidatableGames(): array
    {
        // Hariki can check any game; we offer the check for the games that need a Server ID plus every
        // game the shop already links (CatalogSync/the site decide which games to show).
        $out = [];

        foreach ($this->getCategories() as $c) {
            $fields = [['key' => 'player_id', 'label' => 'Player ID', 'type' => 'text']];
            if (in_array($c['category_id'], (array) config('hariki.server_games'), true)) {
                $fields[] = ['key' => 'server_id', 'label' => 'Server ID', 'type' => 'text'];
            }
            $out[] = ['category_id' => $c['category_id'], 'name' => $c['name'], 'fields' => $fields];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $fields
     * @return array{valid:bool,player_name:?string,region:?string}
     */
    public function validatePlayer(string $categoryId, array $fields): array
    {
        $clean = $this->cleanFields($fields);
        $payload = ['game' => $categoryId, 'user_id' => $clean['player_id'] ?? ''];

        if (! empty($clean['server_id'])) {
            $payload['server_id'] = $clean['server_id'];
        }

        try {
            $data = $this->data($this->send('POST', '/api/v1/check-player', [], $payload, true));
        } catch (ProviderException $e) {
            // "player not found" style answers are a normal result, not a provider failure.
            if (in_array($e->httpStatus, [400, 404, 422], true) && ! $e->isAuthError()) {
                return ['valid' => false, 'player_name' => null, 'region' => null];
            }

            throw $e;
        }

        $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? Str::limit($data['name'], 80, '') : null;

        return ['valid' => ($data['valid'] ?? false) === true, 'player_name' => $name, 'region' => null];
    }

    /**
     * @param  array<string,mixed>  $fields
     * @return array{id:string,status:string,raw:array<string,mixed>}
     */
    public function createOrder(string $categoryId, string $offerId, array $fields, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new InvalidArgumentException('The idempotency key must be 1-100 characters.');
        }

        $clean = $this->cleanFields($fields);

        $payload = [
            'game_code' => $categoryId,
            'product_code' => $offerId,
            'user_id' => $clean['player_id'] ?? '',
            'ref_order' => $idempotencyKey,
            'callback_url' => (string) config('hariki.callback_url'),
        ];

        if (! empty($clean['server_id'])) {
            $payload['server_id'] = $clean['server_id'];
        }

        $body = $this->send('POST', '/api/v1/order', [], $payload, false, (int) config('hariki.order_timeout', 20));
        $data = $this->data($body);

        // Our ref_order is the order id we track (Hariki's own id, when given, stays in raw).
        return ['id' => $idempotencyKey, 'status' => $this->status($data['status'] ?? 'pending'), 'raw' => $body];
    }

    /**
     * Audit lookup of one order (Hariki asks not to poll this).
     *
     * @return array{id:string,status:string,raw:array<string,mixed>}
     */
    public function getOrder(string $orderId): array
    {
        if (! preg_match(self::ORDER_ID_PATTERN, $orderId)) {
            throw new InvalidArgumentException('Invalid order reference.');
        }

        if (config('hariki.poll_guard', true)) {
            $woken = Cache::pull('hariki:wake:'.$orderId);

            if (! $woken && ! Cache::add('hariki:poll:'.$orderId, 1, max(1, (int) config('hariki.poll_interval', 600)))) {
                // Hariki says: do not poll. The webhook (or the slow safety net) will bring the answer.
                return ['id' => $orderId, 'status' => 'processing', 'raw' => ['note' => 'waiting for the webhook']];
            }
        }

        $body = $this->send('GET', '/api/v1/order/'.rawurlencode($orderId));
        $data = $this->data($body);

        return ['id' => $orderId, 'status' => $this->status($data['status'] ?? ''), 'raw' => $body];
    }

    /** SUCCESS -> completed, FAILED -> failed, anything else keeps the order processing. */
    public function status(mixed $raw): string
    {
        return match (strtoupper(trim((string) $raw))) {
            'SUCCESS' => 'completed',
            'FAILED' => 'failed',
            default => 'processing',
        };
    }

    // ---------------------------------------------------------------- transport

    /** @return list<array<string,mixed>> */
    private function products(?string $game): array
    {
        $body = $this->send('GET', '/api/v1/products', $game ? ['game' => $game] : [], [], true, (int) config('hariki.catalog_timeout', 60));
        $list = $body['data']['products'] ?? $body['data'] ?? $body['products'] ?? [];
        $out = [];

        foreach ((array) $list as $p) {
            if (is_array($p) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) ($p['game_code'] ?? '')) && isset($p['product_code'])) {
                $out[] = $p + ['status' => 'active'];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function data(array $body): array
    {
        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $query = [], array $json = [], bool $retry = true, ?int $timeout = null): array
    {
        $attempts = $retry ? max(1, (int) config('hariki.retries', 3)) : 1;
        $timeout ??= (int) config('hariki.timeout', 15);
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = $this->client($timeout);
                $response = $method === 'GET' ? $client->get($path, $query) : $client->post($path, $json);
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    $this->pause();

                    continue;
                }

                Log::warning('Hariki connection problem', ['method' => $method, 'path' => $path]);

                throw new ProviderTimeoutException('Could not reach Hariki (timeout or connection error).');
            }

            if ($attempt < $attempts && in_array($response->status(), self::RETRYABLE_STATUS, true)) {
                $this->pause();

                continue;
            }

            break;
        }

        return $this->parse($response, $method, $path);
    }

    private function client(int $timeout): PendingRequest
    {
        $key = (string) config('hariki.api_key');

        if ($key === '') {
            throw new ProviderException('The Hariki API key is not configured (HARIKI_API_KEY).');
        }

        return Http::baseUrl(rtrim((string) config('hariki.base_url'), '/'))
            ->withHeaders(['X-API-Key' => $key])
            ->acceptJson()->asJson()
            ->connectTimeout((int) config('hariki.connect_timeout', 5))
            ->timeout($timeout);
    }

    /** @return array<string,mixed> */
    private function parse(Response $response, string $method, string $path): array
    {
        $status = $response->status();
        $body = $response->json();

        if ($response->successful() && is_array($body) && ($body['success'] ?? true) !== false) {
            return $body;
        }

        if ($response->successful() && ! is_array($body)) {
            throw new ProviderException('Unexpected (non-JSON) response from Hariki.', $status);
        }

        $error = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;
        $code = is_array($error) && is_scalar($error['code'] ?? null) ? (string) $error['code'] : (is_array($body) && is_scalar($body['code'] ?? null) ? (string) $body['code'] : null);
        $text = is_array($error) ? ($error['message'] ?? null) : $error;
        $message = is_string($text) && $text !== '' ? Str::limit($text, 200, '') : "Hariki returned HTTP {$status}.";

        Log::warning('Hariki request failed', ['method' => $method, 'path' => $path, 'status' => $status, 'code' => $code]);

        // A 2xx with success:false is a refusal; use 422 so it counts as a definitive rejection.
        throw new ProviderException($message, $response->successful() ? 422 : $status, $code);
    }

    private function pause(): void
    {
        $ms = (int) config('hariki.retry_sleep_ms', 400);

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /** @param array<int|string,mixed> $fields @return array<string,string> */
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
