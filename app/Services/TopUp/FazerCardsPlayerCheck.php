<?php

namespace App\Services\TopUp;

use App\Exceptions\ProviderException;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Player ID check through FazerCards. It never places orders and never moves money.
 *
 * - The API key is sent only as the X-API-Key header and never logged.
 * - The list of games is retried on connection errors and 429/502/503/504; the check itself is not retried.
 */
class FazerCardsPlayerCheck
{
    private const RETRYABLE_STATUS = [429, 502, 503, 504];

    public function isConfigured(): bool
    {
        return filled(config('fazercards.api_key'));
    }

    /**
     * Games that support Player ID validation, with the fields to send.
     *
     * @return list<array{category_id:string,name:string,fields:list<array{key:string,label:string,type:string}>}>
     */
    public function getValidatableGames(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $body = $this->send('GET', '/topups/validate-id', [], true, (int) config('fazercards.timeout', 15));
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

    /**
     * @param  array<string,mixed>  $fields  provider field key => value
     * @return array{valid:bool,player_name:?string,region:?string}
     */
    public function validatePlayer(string $categoryId, array $fields): array
    {
        $body = $this->send(
            'POST',
            '/topups/validate-id',
            ['category_id' => $categoryId, 'fields' => (object) $this->cleanFields($fields)],
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

    // ---------------------------------------------------------------- transport

    /**
     * @param  array<string,mixed>  $data  query (GET) or JSON body (POST)
     * @return array<string,mixed>
     */
    private function send(string $method, string $path, array $data, bool $retry, int $timeout): array
    {
        $attempts = $retry ? max(1, (int) config('fazercards.retries', 3)) : 1;
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = $this->client($timeout);
                $response = $method === 'GET' ? $client->get($path, $data) : $client->post($path, $data);
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

    /** @return array<string,mixed> */
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

        Log::warning('FazerCards request failed', ['method' => $method, 'path' => $path, 'status' => $status, 'code' => $code]);

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
