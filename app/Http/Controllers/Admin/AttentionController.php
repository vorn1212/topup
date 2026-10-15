<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ProviderOrder;
use App\Models\WebhookEvent;
use App\Services\TopUp\FazerCardsWebhook;
use App\Services\TopUp\OrderRecoveryService;
use App\Services\TopUp\ProviderBalanceMonitor;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

/**
 * "Needs attention": orders that stopped automatic handling, orders that seem stuck, the provider
 * balance and the latest webhook deliveries. Viewing needs orders.view, acting needs orders.manage.
 */
class AttentionController extends Controller
{
    public function index(ProviderBalanceMonitor $monitor, FazerCardsWebhook $webhook): View
    {
        $snapshot = $monitor->snapshot();

        return view('admin.attention.index', [
            'review' => ProviderOrder::query()
                ->where('status', ProviderOrderStatus::Review->value)
                ->with('order.user')
                ->orderBy('updated_at')
                ->limit(100)
                ->get(),
            'stuck' => ProviderOrder::query()
                ->whereIn('status', [
                    ProviderOrderStatus::Queued->value,
                    ProviderOrderStatus::Submitting->value,
                    ProviderOrderStatus::Unknown->value,
                ])
                ->where('updated_at', '<=', now()->subMinutes(30))
                ->with('order.user')
                ->orderBy('updated_at')
                ->limit(100)
                ->get(),
            'events' => WebhookEvent::query()->latest('id')->limit(15)->get(),
            'balance' => $snapshot,
            'balanceLow' => $monitor->isLow($snapshot),
            'balanceEnabled' => $monitor->enabled(),
            'webhookEnabled' => $webhook->isEnabled(),
            'webhookUrl' => route('webhooks.fazercards'),
        ]);
    }

    public function show(ProviderOrder $providerOrder): View
    {
        $providerOrder->load('order.user');

        return view('admin.attention.show', [
            'po' => $providerOrder,
            'order' => $providerOrder->order,
        ]);
    }

    public function refreshBalance(ProviderBalanceMonitor $monitor): RedirectResponse
    {
        if (! $monitor->enabled()) {
            return back()->with('error', 'The provider is not configured (or the fake provider is on).');
        }

        $snapshot = $monitor->refresh();

        return $snapshot === null
            ? back()->with('error', 'Could not read the provider balance right now.')
            : back()->with('status', 'Provider balance updated.');
    }

    public function recheck(Request $request, ProviderOrder $providerOrder, OrderRecoveryService $recovery): RedirectResponse
    {
        return $this->run(fn () => $recovery->recheck($providerOrder, $request->user()), 'The provider will be asked about this order now.', $providerOrder);
    }

    public function resend(Request $request, ProviderOrder $providerOrder, OrderRecoveryService $recovery): RedirectResponse
    {
        return $this->run(fn () => $recovery->resend($providerOrder, $request->user()), 'The order will be sent again (same idempotency key).', $providerOrder);
    }

    public function complete(Request $request, ProviderOrder $providerOrder, OrderRecoveryService $recovery): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return $this->run(fn () => $recovery->markCompleted($providerOrder, $request->user(), $data['reason']), 'The order was marked as completed.', $providerOrder);
    }

    public function refund(Request $request, ProviderOrder $providerOrder, OrderRecoveryService $recovery): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Please confirm that you checked with the provider that nothing was delivered.',
        ]);

        return $this->run(fn () => $recovery->refund($providerOrder, $request->user(), $data['reason']), 'The customer was refunded.', $providerOrder);
    }

    private function run(callable $action, string $success, ProviderOrder $providerOrder): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException|InvalidArgumentException|LogicException $e) {
            return redirect()->route('admin.attention.orders.show', $providerOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.attention.orders.show', $providerOrder)->with('status', $success);
    }
}