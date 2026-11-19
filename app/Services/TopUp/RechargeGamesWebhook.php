<?php

namespace App\Services\TopUp;

use App\Enums\ProviderOrderStatus;
use App\Jobs\CheckRechargeGamesOrderStatusJob;
use App\Models\ProviderOrder;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives RechargeGames webhooks (Standard Webhooks signing).
 *
 * The webhook is only a WAKE-UP CALL. Its content is never trusted to change an order:
 *  1. the signature must be valid and the timestamp recent;
 *  2. we read the provider order id (ORD-XXXXXX) from the payload;
 *  3. we ask the provider for the real status of that order (same code path as polling).
 * So a replayed, reordered or odd-looking payload can never complete or refund an order by itself.
 *
 * Signature: base64( HMAC-SHA256( "{webhook-id}.{webhook-timestamp}.{raw body}", key ) ), sent as
 * "v1,<signature>" (possibly several, space separated). key = base64-decoded part of the whsec_ secret.
 */
class RechargeGamesWebhook
{
    public const PROVIDER = 'rechargegames';

    public const MAX_BODY_BYTES = 65536;

    public function isEnabled(): bool
    {
        return filled(config('rechargegames.webhook_secret'));
    }

    public function signatureIsValid(Request $request, string $body): bool
    {
        $key = $this->key();

        if ($key === null) {
            return false;
        }

        $id = trim((string) $request->header('webhook-id', ''));
        $timestamp = trim((string) $request->header('webhook-timestamp', ''));
        $header = trim((string) $request->header('webhook-signature', ''));

        if ($id === '' || $header === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = max(1, (int) config('rechargegames.webhook_tolerance', 300));

        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));

        foreach (preg_split('/\s+/', $header) ?: [] as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');

            if ($version === 'v1' && $signature !== '' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store a verified delivery and wake up the status check of the order it mentions.
     *
     * @return array{status:string,note:string}
     */
    public function handle(string $body): array
    {
        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : null;

        $orderId = $payload !== null ? $this->findOrderId($payload) : null;

        try {
            $event = new WebhookEvent;
            $event->forceFill([
                'provider' => self::PROVIDER,
                'dedupe_key' => hash('sha256', $body),
                'event_type' => $payload !== null ? $this->findEventType($payload) : null,
                'provider_order_id' => $orderId,
                'status' => 'received',
                'payload' => $payload,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'duplicate', 'note' => 'This delivery was already received.'];
        }

        [$status, $note] = $this->process($orderId, $payload !== null);

        $event->forceFill([
            'status' => $status,
            'note' => $note,
            'processed_at' => now(),
        ])->save();

        return ['status' => $status, 'note' => $note];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function process(?string $orderId, bool $isJson): array
    {
        if (! $isJson) {
            return ['ignored', 'The body is not JSON.'];
        }

        if ($orderId === null) {
            return ['ignored', 'No order id in the payload.'];
        }

        $po = ProviderOrder::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_order_id', $orderId)
            ->first();

        if (! $po) {
            return ['ignored', "No order of ours has the provider id {$orderId}."];
        }

        if ($po->status !== ProviderOrderStatus::Processing) {
            return ['ignored', "Order is already {$po->status->value}; nothing to do."];
        }

        $po->forceFill(['next_check_at' => now()])->save();

        try {
            CheckRechargeGamesOrderStatusJob::dispatch($po->id);
        } catch (Throwable $e) {
            // Not fatal: orders:process-pending polls it within a minute.
            report($e);
        }

        return ['processed', 'A status check was queued.'];
    }

    private function key(): ?string
    {
        $secret = trim((string) config('rechargegames.webhook_secret'));

        if ($secret === '') {
            return null;
        }

        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        return $secret;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function findOrderId(array $data, int $depth = 0): ?string
    {
        if ($depth > 5) {
            return null;
        }

        if (isset($data['order_id']) && is_string($data['order_id']) && preg_match(RechargeGamesService::ORDER_ID_PATTERN, $data['order_id'])) {
            return $data['order_id'];
        }

        foreach ($data as $value) {
            if (is_array($value) && ($found = $this->findOrderId($value, $depth + 1)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function findEventType(array $data): ?string
    {
        foreach (['type', 'event', 'event_type'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return mb_substr($data[$key], 0, 60);
            }
        }

        return null;
    }
}
