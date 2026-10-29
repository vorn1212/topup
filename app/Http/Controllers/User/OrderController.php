<?php

namespace App\Http\Controllers\User;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OrderRejectedException;
use App\Exceptions\PriceChangedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Models\GameField;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Services\TopUp\OrderService;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    private const FINAL_STATUSES = ['completed', 'refunded', 'cancelled'];

    public function store(CreateOrderRequest $request, OrderService $orders): RedirectResponse
    {
        // Invalid player details raise a ValidationException inside the service:
        // Laravel sends the customer back to the form with the messages under each field.
        try {
            $order = $orders->create(
                $request->user(),
                (string) $request->validated('game'),
                (int) $request->validated('product_id'),
                (array) ($request->validated('fields') ?? []),
                $request->validated('request_token'),
                $request->validated('expected_price'),
                $request->ip(),
            );
        } catch (PriceChangedException $e) {
            return back()->withInput()->with(
                'error',
                'The price changed to '.Money::display($e->newPrice).'. Please check it and confirm again.'
            );
        } catch (InsufficientBalanceException) {
            return back()->withInput()
                ->with('insufficient', true)
                ->with('error', 'Insufficient funds. Please add funds.');
        } catch (OrderRejectedException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('orders.show', $order->order_number)
            ->with('status', $order->wasRecentlyCreated
                ? 'Order placed. We are sending your top-up to the game now.'
                : 'This order was already placed.');
    }

    public function index(Request $request): View
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(15);

        return view('orders.index', compact('orders'));
    }

    public function show(Request $request, string $orderNumber): View
    {
        $order = $this->ownOrder($request, $orderNumber, ['walletTransaction', 'providerOrder:id,order_id,status']);

        return view('orders.show', [
            'order' => $order,
            'labels' => GameField::query()->where('game_id', $order->game_id)->pluck('label', 'provider_field_name'),
            'refund' => WalletTransaction::query()
                ->where('user_id', $order->user_id)
                ->where('idempotency_key', 'refund:order:'.$order->id)
                ->first(),
        ]);
    }

    public function status(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->ownOrder($request, $orderNumber);

        return ApiResponse::success([
            'status' => $order->status->value,
            'label' => $order->status->label(),
            'final' => in_array($order->status->value, self::FINAL_STATUSES, true),
        ]);
    }

    /**
     * @param  list<string>  $with
     */
    private function ownOrder(Request $request, string $orderNumber, array $with = []): Order
    {
        return Order::query()
            ->with($with)
            ->where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();
    }
}