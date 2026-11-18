<?php

namespace App\Services\TopUp;

use App\Enums\ProviderOrderStatus;
use App\Jobs\CheckHarikiOrderStatusJob;
use App\Models\ProviderOrder;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Receives Hariki webhooks. X-Hariki-Signature "t=<ts>,v1=..,v2=..", X-Hariki-Timestamp, X-Hariki-Content-Digest.
 *
 * The webhook is only a WAKE-UP CALL. Its content never changes an order by itself:
 *  1. signature, digest and timestamp must be valid;
 *  2. we read our ref_order from the payload and find our provider order;
 *  3. we ask Hariki once (GET /order/{ref}) for the real status. The wake flag lets that one lookup through.
 *
 * Hariki has not published how v1..v5 differ, so a delivery is accepted when ANY of them equals the
 * HMAC-SHA256 (hex or base64) of "<timestamp>.<raw body>" under the secret (as text or base64-decoded).
 */
class HarikiWebhook
{
    public const PROVIDER = 'hariki';

    public const MAX_BODY_BYTES = 65536;

    public function isEnabled(): bool
    {
        return filled(config('hariki.webhook_secret'));
    }

    public function signatureIsValid(Request $request, string $body): bool
    {
        $secret = trim((string) config('hariki.webhook_secret'));
        $timestamp = trim((string) $request->header('X-Hariki-Timestamp', ''));
        $header = trim((string) $request->header('X-Hariki-Signature', ''));

        if ($secret === '' || $header === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > max(1, (int) config('hariki.webhook_tolerance', 300))) {
            return false;
        }

        $digest = trim((string) $request->header('X-Hariki-Content-Digest', ''));

        if ($digest !== '' && ! hash_equals('sha256='.hash('sha256', $body), strtolower($digest))) {
            return false;
        }

        $keys = [$secret];

        if (str_starts_with($secret, 'whsec_')) {
            $keys[] = substr($secret, 6);
            $decoded = base64_decode(substr($secret, 6), true);

            if ($decoded !== false && $decoded !== '') {
                $keys[] = $decoded;
            }
        }

        $candidates = [];

        foreach ($keys as $key) {
            $raw = hash_hmac('sha256', $timestamp.'.'.$body, $key, true);
            $candidates[] = bin2hex($raw);
            $candidates[] = base64_encode($raw);
        }

        $ok = false;

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if (! preg_match('/^v[1-5]$/', $name) || $value === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $ok = hash_equals($candidate, $value) || $ok;
            }
        }

        return $ok;
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
            return ['ignored', 'No ref_order in the payload.'];
        }

        $po = ProviderOrder::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_order_id', $orderId)
            ->first();

        if (! $po) {
            return ['ignored', "No order of ours has the reference {$orderId}."];
        }

        if ($po->status !== ProviderOrderStatus::Processing) {
            return ['ignored', "Order is already {$po->status->value}; nothing to do."];
        }

        // Lets exactly one status lookup through the "do not poll" guard.
        Cache::put('hariki:wake:'.$orderId, true, now()->addMinutes(10));

        $po->forceFill(['next_check_at' => now()])->save();

        try {
            CheckHarikiOrderStatusJob::dispatch($po->id);
        } catch (Throwable $e) {
            report($e);
        }

        return ['processed', 'A status check was queued.'];
    }

    /**
     * @param  array<mixed>  $data
     */
    private function findOrderId(array $data, int $depth = 0): ?string
    {
        if ($depth > 5) {
            return null;
        }

        if (isset($data['ref_order']) && is_string($data['ref_order']) && preg_match(HarikiService::ORDER_ID_PATTERN, $data['ref_order'])) {
            return $data['ref_order'];
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
