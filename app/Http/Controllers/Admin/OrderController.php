<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProviderOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\WalletTransaction;
use App\Services\Admin\OrderNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $status = OrderStatus::tryFrom((string) $request->query('status'));
        $provider = ProviderOrderStatus::tryFrom((string) $request->query('provider'));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $orders = Order::query()
            ->when($q !== '', fn ($query) => $query->where(
                fn ($w) => $w->where('order_number', 'like', $like)
                    ->orWhere('game_name', 'like', $like)
                    ->orWhere('product_name', 'like', $like)
                    ->orWhere('player_fields', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like))
            ))
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->when($provider, fn ($query) => $query->whereHas('providerOrder', fn ($p) => $p->where('status', $provider->value)))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to->copy()->endOfDay()))
            ->with(['user:id,name,email', 'providerOrder:id,order_id,status'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'q' => $q,
            'status' => $status,
            'provider' => $provider,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }

    public function show(Order $order): View
    {
        $order->load(['user:id,name,email', 'providerOrder']);

        return view('admin.orders.show', [
            'order' => $order,
            'po' => $order->providerOrder,
            'notes' => OrderNote::query()->where('order_id', $order->id)->with('author:id,name,email')->orderByDesc('id')->get(),
            'timeline' => AuditLog::query()
                ->where('subject_type', $order->getMorphClass())
                ->where('subject_id', $order->id)
                ->with('actor:id,name,email')
                ->orderBy('id')
                ->get(),
            'ledger' => WalletTransaction::query()
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function addNote(Request $request, Order $order, OrderNoteService $notes): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1000']]);

        try {
            $notes->add($order, $request->user(), $data['body']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.orders.show', $order)->with('status', 'The note was added.');
    }

    private function date(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}