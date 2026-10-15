<?php

namespace App\Services\TopUp;

use App\Enums\ProviderOrderStatus;
use App\Jobs\CheckFazerCardsOrderStatusJob;
use App\Models\ProviderOrder;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives FazerCards webhooks.
 *
 * The webhook is only a WAKE-UP CALL. Its content is never trusted to change an order:
 *  1. the signature (HMAC-SHA256 of the raw body, with our secret) must be valid;
 *  2. we look for a provider order id (ord-123) anywhere in the payload;
 *  3. we ask the provider for the real status of that order (same code path as polling).
 * So a replayed, reordered or odd-looking payload can never complete or refund an order by itself.
 */
class FazerCardsWebhook
{
    public const PROVIDER = 'fazercards';

    public const MAX_BODY_BYTES = 65536;

    /** Signature headers seen in the provider's documentation and SDK. Either one is accepted. */
    private const SIGNATURE_HEADERS = ['X-Webhook-Signature', 'X-FazerCards-Signature'];

    public function isEnabled(): bool
    {
        return filled(config('fazercards.webhook_secret'));
    }

    /**
     * The signature may be "sha256=<hex>" or plain "<hex>".
     */
    public function signatureIsValid(Request $request, string $body): bool
    {
        $secret = (string) config('fazercards.webhook_secret');

        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);

        foreach (self::SIGNATURE_HEADERS as $header) {
            $value = strtolower(trim((string) $request->header($header, '')));

            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, 'sha256=')) {
                $value = substr($value, 7);
            }

            if (hash_equals($expected, $value)) {
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

        // Look at it right now instead of waiting for the next poll.
        $po->forceFill(['next_check_at' => now()])->save();

        try {
            CheckFazerCardsOrderStatusJob::dispatch($po->id);
        } catch (Throwable $e) {
            // Not fatal: orders:process-pending polls it within a minute.
            report($e);
        }

        return ['processed', 'A status check was queued.'];
    }

    /**
     * The first value that looks like a provider order id (ord-123), searched through the payload.
     *
     * @param  array<mixed>  $data
     */
    private function findOrderId(array $data, int $depth = 0): ?string
    {
        if ($depth > 5) {
            return null;
        }

        foreach ($data as $value) {
            if (is_string($value) && preg_match(FazerCardsService::ORDER_ID_PATTERN, $value)) {
                return $value;
            }
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
        foreach (['event', 'type', 'event_type'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return mb_substr($data[$key], 0, 60);
            }
        }

        return null;
    }
}