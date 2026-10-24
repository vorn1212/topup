<?php

namespace App\Http\Controllers\Site;

use App\Contracts\TopUpProvider;
use App\Enums\CatalogStatus;
use App\Enums\DepositStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameField;
use App\Models\Order;
use App\Models\Product;
use App\Models\WalletDeposit;
use App\Services\Checkout\CheckoutService;
use App\Services\Payment\DepositService;
use App\Services\Payment\PayWayLinkService;
use App\Services\TopUp\PlayerFields;
use App\Services\TopUp\ProviderBalanceMonitor;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Top up without an account: enter the player details, scan the QR, the top-up starts after payment. */
class CheckoutController extends Controller
{
    private const FINAL_ORDER = ['completed', 'refunded', 'cancelled'];

    /** Open payment pages one network address may have at the same time. */
    private const MAX_OPEN_PER_IP = 3;

    public function store(Request $request, CheckoutService $checkout, TopUpProvider $provider): RedirectResponse
    {
        $data = $request->validate([
            'game' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9\-]+$/'],
            'product_id' => ['required', 'integer', 'min:1'],
            'fields' => ['nullable', 'array'],
            'expected_price' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ]);

        $user = $request->user();

        if ($user && ! $user->canTransact()) {
            return back()->withInput()->with('error', 'Your account cannot place orders. Please contact support.');
        }

        if (! $provider->isConfigured()) {
            return back()->withInput()->with('error', 'Ordering is temporarily unavailable. Please try again later.');
        }

        if (blank(config('payway_link.url'))) {
            return back()->withInput()->with('error', 'Payments are temporarily unavailable. Please try again later.');
        }

        $game = Game::query()->active()->where('slug', $data['game'])->with('fields')->first();

        if (! $game || blank($game->provider_category_id)) {
            return back()->withInput()->with('error', 'This game is not available for ordering right now.');
        }

        $input = collect((array) ($data['fields'] ?? []))
            ->map(fn ($value) => is_scalar($value) ? (string) $value : '')
            ->all();

        $validator = PlayerFields::validator($game, $input);
        $validator->validate();
        $fields = PlayerFields::clean($game, $validator->validated());

        $product = Product::query()->where('game_id', $game->id)->whereKey((int) $data['product_id'])->first();

        if (! $product
            || $product->status !== CatalogStatus::Active
            || ! $product->is_available
            || blank($product->provider_offer_id)) {
            return back()->withInput()->with('error', 'This package is not available right now.');
        }

        $price = Money::normalize((string) $product->selling_price);
        $cost = Money::normalize((string) $product->provider_cost);

        if (Money::cmp($price, '0') <= 0 || Money::cmp($price, $cost) < 0
            || app(ProviderBalanceMonitor::class)->blocks($cost)) {
            return back()->withInput()->with('error', 'This package is temporarily unavailable. Please try again later.');
        }

        if (filled($data['expected_price'] ?? null) && Money::cmp($data['expected_price'], $price) !== 0) {
            return back()->withInput()->with(
                'error',
                'The price changed to '.Money::display($price).'. Please check it and confirm again.'
            );
        }

        if ($this->openFromThisAddress($request->ip()) >= self::MAX_OPEN_PER_IP) {
            return back()->withInput()->with('error', 'Too many payments are open from your network. Please finish them or wait for them to expire.');
        }

        $deposit = $checkout->start($user, $game, $product, $fields, $request->ip());

        return redirect()->route('checkout.show', [$deposit->deposit_number, $deposit->metadata['checkout']['token']]);
    }

    public function show(string $depositNumber, string $token): View
    {
        $deposit = $this->find($depositNumber, $token);
        $c = $deposit->metadata['checkout'];

        $game = Game::query()->where('slug', $c['game'])->first();
        $labels = $game ? GameField::query()->where('game_id', $game->id)->pluck('label', 'provider_field_name') : collect();

        return view('site.checkout.pay', [
            'deposit' => $deposit,
            'summary' => [
                'game' => $c['game_name'] ?? '',
                'package' => $c['product_name'] ?? '',
                'fields' => collect((array) ($c['fields'] ?? []))
                    ->mapWithKeys(fn ($value, $name) => [__($labels[$name] ?? $name) => $value])
                    ->all(),
            ],
            'statusUrl' => route('checkout.status', [$depositNumber, $token]),
        ]);
    }

    public function status(string $depositNumber, string $token, DepositService $deposits, CheckoutService $checkout): JsonResponse
    {
        $deposit = $this->find($depositNumber, $token);

        if ($deposit->gateway_stage === DepositService::STAGE_QUEUED && $deposit->expires_at?->isPast()) {
            $deposit = $deposits->transition($deposit->id, DepositStatus::Expired, [
                'gateway_stage' => DepositService::STAGE_EXPIRED,
            ]);
        }

        // Paid: start the top-up now instead of waiting for the background job.
        if ($deposit->status === DepositStatus::Paid) {
            $checkout->placeOrder($deposit->id);
            $deposit = $deposit->fresh();
        }

        $c = $deposit->metadata['checkout'];

        $qrValid = $deposit->gateway_stage === DepositService::STAGE_QR_READY
            && $deposit->qr_expires_at?->isFuture();

        $position = null;
        if ($deposit->gateway_stage === DepositService::STAGE_QUEUED) {
            $position = 1 + WalletDeposit::query()
                ->where('payment_method', DepositService::METHOD_PAYWAY_LINK)
                ->where('gateway_stage', DepositService::STAGE_QUEUED)
                ->where('expires_at', '>', now())
                ->where('id', '<', $deposit->id)
                ->count();
        }

        $order = null;
        if (! empty($c['order_number'])) {
            $found = Order::query()
                ->where('user_id', $deposit->user_id)
                ->where('order_number', $c['order_number'])
                ->first();

            if ($found) {
                $order = [
                    'number' => $found->order_number,
                    'status' => $found->status->value,
                    'label' => __($found->status->label()),
                    'final' => in_array($found->status->value, self::FINAL_ORDER, true),
                ];
            }
        }

        return ApiResponse::success([
            'status' => $deposit->status->value,
            'stage' => $deposit->gateway_stage,
            'qr_url' => $qrValid ? route('checkout.qr', [$depositNumber, $token]) : null,
            'seconds_left' => $qrValid ? max(0, (int) now()->diffInSeconds($deposit->qr_expires_at, false)) : null,
            'queue_position' => $position,
            'order' => $order,
            'problem' => ! empty($c['error']),
        ]);
    }

    public function qr(string $depositNumber, string $token): BinaryFileResponse|Response
    {
        $deposit = $this->find($depositNumber, $token);

        abort_unless(
            $deposit->gateway_stage === DepositService::STAGE_QR_READY && $deposit->qr_expires_at?->isFuture(),
            404
        );

        $path = PayWayLinkService::qrPath($deposit->deposit_number);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** The page address holds a long random token: without it the payment cannot be found. */
    private function find(string $depositNumber, string $token): WalletDeposit
    {
        $deposit = WalletDeposit::query()->where('deposit_number', $depositNumber)->first();
        $expected = $deposit?->metadata['checkout']['token'] ?? null;

        abort_unless(is_string($expected) && hash_equals($expected, $token), 404);

        return $deposit;
    }

    private function openFromThisAddress(?string $ip): int
    {
        if (blank($ip)) {
            return 0;
        }

        return WalletDeposit::query()
            ->where('payment_method', DepositService::METHOD_PAYWAY_LINK)
            ->whereIn('gateway_stage', [
                DepositService::STAGE_QUEUED,
                DepositService::STAGE_STARTING,
                DepositService::STAGE_QR_READY,
            ])
            ->where('expires_at', '>', now())
            ->get(['id', 'metadata'])
            ->filter(fn ($d) => ($d->metadata['checkout']['ip'] ?? null) === $ip)
            ->count();
    }
}
