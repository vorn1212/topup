<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\TopUp\FazerCardsWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FazerCardsWebhookController extends Controller
{
    public function __invoke(Request $request, FazerCardsWebhook $webhook): JsonResponse
    {
        // No secret configured = the endpoint does not exist.
        if (! $webhook->isEnabled()) {
            abort(404);
        }

        $body = $request->getContent();

        if (strlen($body) > FazerCardsWebhook::MAX_BODY_BYTES) {
            return response()->json(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        if (! $webhook->signatureIsValid($request, $body)) {
            // Not stored: anyone on the internet can send this, so it must not fill the database.
            Log::warning('FazerCards webhook with an invalid signature.', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Invalid signature.'], 401);
        }

        $result = $webhook->handle($body);

        return response()->json(['ok' => true, 'result' => $result['status']]);
    }
}