<?php

namespace App\Services\TopUp;

use App\Contracts\TopUpProvider;
use App\Enums\CatalogStatus;
use App\Enums\OrderStatus;
use App\Enums\ProviderOrderStatus;
use App\Enums\WalletTxType;
use App\Exceptions\OrderRejectedException;
use App\Exceptions\PriceChangedException;
use App\Jobs\ProcessFazerCardsOrderJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderOrder;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\NumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrderService
{
    public function __construct(
        private readonly TopUpProvider $provider,
        private readonly WalletService $wallets,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Place an order. Everything money-related happens in ONE transaction:
     * lock the product, read the price from the database, insert the order, debit the wallet
     * (under its row lock, funds checked after the lock), create the provider order.
     *
     * @param  array<string,mixed>  $fieldsInput  player fields as sent by the browser
     *
     * @throws OrderRejectedException  a reason that is safe to show to the customer
     * @throws PriceChangedException   the displayed price is no longer the real price
     * @throws \App\Exceptions\InsufficientBalanceException
     * @throws \Illuminate\Validation\ValidationException  invalid player details
     */
    public function create(
        User $user,
        string $gameSlug,
        int $productId,
        array $fieldsInput,
        ?string $requestToken = null,
        ?string $expectedPrice = null,
        ?string $ip = null,
    ): Order {
        if (! $user->canTransact()) {
            throw new OrderRejectedException('Your account cannot place orders. Please contact support.');
        }

        if (! $this->provider->isConfigured()) {
            throw new OrderRejectedException('Ordering is temporarily unavailable. Please try again later.');
        }

        $requestToken = ($requestToken !== null && preg_match('/^[A-Za-z0-9\-]{16,64}$/', $requestToken)) ? $requestToken : null;

        // A repeated submit (double click, back button) returns the order it already created.
        if ($requestToken !== null && ($existing = $this->existing($user, $requestToken))) {
            return $existing;
        }

        $game = Game::query()->active()->where('slug', $gameSlug)->with('fields')->first();

        if (! $game || blank($game->provider_category_id)) {
            throw new OrderRejectedException('This game is not available for ordering right now.');
        }

        $validator = PlayerFields::validator($game, $fieldsInput);
        $validator->validate();
        $fields = PlayerFields::clean($game, $validator->validated());

        try {
            $order = DB::transaction(function () use ($user, $game, $productId, $fields, $requestToken, $expectedPrice, $ip) {
                $product = Product::query()
                    ->whereKey($productId)
                    ->where('game_id', $game->id)
                    ->lockForUpdate()
                    ->first();

                if (! $product
                    || $product->status !== CatalogStatus::Active
                    || ! $product->is_available
                    || blank($product->provider_offer_id)) {
                    throw new OrderRejectedException('This package is not available right now.');
                }

                // The price is ALWAYS the database price. Never anything the browser sent.
                $price = Money::normalize((string) $product->selling_price);
                $cost = Money::normalize((string) $product->provider_cost);

                if (Money::cmp($price, '0') <= 0 || Money::cmp($price, $cost) < 0) {
                    throw new OrderRejectedException('This package is temporarily unavailable.');
                }

                if ($expectedPrice !== null
                    && (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $expectedPrice) || Money::cmp($expectedPrice, $price) !== 0)) {
                    throw new PriceChangedException($price);
                }

                $order = new Order;
                $order->forceFill([
                    'order_number' => 'ORD-'.now()->format('Ymd').'-'.NumberGenerator::random(6),
                    'user_id' => $user->id,
                    'game_id' => $game->id,
                    'product_id' => $product->id,
                    'game_name' => $game->name,
                    'product_name' => $product->name,
                    'player_fields' => $fields,
                    'provider_cost' => $cost,
                    'selling_price' => $price,
                    'profit' => Money::sub($price, $cost),
                    'currency' => $product->currency,
                    'request_token' => $requestToken,
                    'status' => OrderStatus::Pending,
                    'ip_address' => $ip,
                ])->save();

                $wallet = $this->wallets->createWallet($user);

                $transaction = $this->wallets->debit(
                    $wallet,
                    $price,
                    'purchase:order:'.$order->id,
                    WalletTxType::Purchase,
                    $order,
                    "Game top-up: {$game->name} {$product->name}",
                );

                $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();

                $providerOrder = new ProviderOrder;
                $providerOrder->forceFill([
                    'order_id' => $order->id,
                    'provider' => 'fazercards',
                    // One random key per logical order, reused by EVERY retry of this order.
                    'idempotency_key' => (string) Str::uuid(),
                    'category_id' => $game->provider_category_id,
                    'offer_id' => $product->provider_offer_id,
                    'request_payload' => ['fields' => $fields],
                    'status' => ProviderOrderStatus::Queued,
                ])->save();

                $this->audit->log('order.created', $order, [], [
                    'order_number' => $order->order_number,
                    'amount' => $price,
                    'product_id' => $product->id,
                ], $user);

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same token won the race: return its order.
            if ($requestToken !== null && ($existing = $this->existing($user, $requestToken))) {
                return $existing;
            }

            throw $e;
        }

        $this->dispatchSubmission($order);

        return $order;
    }

    private function existing(User $user, string $token): ?Order
    {
        return Order::query()->where('user_id', $user->id)->where('request_token', $token)->first();
    }

    private function dispatchSubmission(Order $order): void
    {
        try {
            $id = ProviderOrder::query()->where('order_id', $order->id)->value('id');
            ProcessFazerCardsOrderJob::dispatch((int) $id);
        } catch (Throwable $e) {
            // The order is safely stored: orders:process-pending will pick it up within a minute.
            report($e);
        }
    }
}