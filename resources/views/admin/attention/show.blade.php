@php
    $status = $po->status->value;
    $open = in_array($order->status->value, ['pending', 'processing'], true);
    $resolvable = in_array($status, ['review', 'unknown', 'processing'], true);
    $fields = (array) $order->player_fields;
    $payload = $po->response_payload;
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Order {{ $order->order_number }}</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('admin.attention.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to the list</a>

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><p class="text-gray-500">Order</p><x-status-badge :status="$order->status->value" /></div>
                    <div><p class="text-gray-500">Provider order</p><x-status-badge :status="$status" /></div>
                    <div><p class="text-gray-500">Customer</p><p class="font-medium">{{ $order->user->email }}</p></div>
                    <div><p class="text-gray-500">Paid</p><p class="font-medium">{{ \App\Support\Money::display((string) $order->selling_price, $order->currency) }}</p></div>
                    <div><p class="text-gray-500">Game</p><p class="font-medium">{{ $order->game_name }}</p></div>
                    <div><p class="text-gray-500">Package</p><p class="font-medium">{{ $order->product_name }}</p></div>
                    <div><p class="text-gray-500">Our cost</p><p class="font-medium">{{ \App\Support\Money::display((string) $order->provider_cost, $order->currency) }}</p></div>
                    <div><p class="text-gray-500">Created</p><p class="font-medium">{{ $order->created_at->format('Y-m-d H:i') }}</p></div>
                </div>

                <dl class="mt-5 space-y-1 text-sm">
                    @foreach ($fields as $key => $value)
                        <div class="flex gap-2"><dt class="w-40 text-gray-500">{{ $key }}</dt><dd class="font-mono">{{ is_scalar($value) ? $value : json_encode($value) }}</dd></div>
                    @endforeach
                    <div class="flex gap-2"><dt class="w-40 text-gray-500">Provider order id</dt><dd class="font-mono">{{ $po->provider_order_id ?? 'none' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-40 text-gray-500">Idempotency key</dt><dd class="break-all font-mono text-xs">{{ $po->idempotency_key }}</dd></div>
                    <div class="flex gap-2"><dt class="w-40 text-gray-500">Attempts</dt><dd>{{ $po->attempts }}</dd></div>
                    <div class="flex gap-2"><dt class="w-40 text-gray-500">Last checked</dt><dd>{{ $po->last_checked_at?->diffForHumans() ?? 'never' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-40 text-gray-500">Last error</dt><dd class="text-red-700">{{ $po->last_error ?? 'none' }}</dd></div>
                </dl>

                @if ($payload)
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-indigo-600">Last answer from the provider</summary>
                        <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-3 text-xs">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            </div>

            @can('orders.manage')
                @if (! $open || ! $resolvable)
                    <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700">
                        Nothing to do: the order is {{ $order->status->value }} and the provider order is {{ $status }}.
                    </div>
                @else
                    <div class="grid gap-4 md:grid-cols-2">
                        @if ($status === 'review' && filled($po->provider_order_id))
                            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                                <h3 class="font-semibold text-gray-900">Check again</h3>
                                <p class="mt-1 text-sm text-gray-600">Ask the provider for the order's real status. If it is final, the order finishes by itself. Safe: no money moves.</p>
                                <form method="POST" action="{{ route('admin.attention.orders.recheck', $po) }}" class="mt-3">
                                    @csrf
                                    <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Check again</button>
                                </form>
                            </div>
                        @endif

                        @if ($status === 'review' && blank($po->provider_order_id))
                            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                                <h3 class="font-semibold text-gray-900">Send again</h3>
                                <p class="mt-1 text-sm text-gray-600">The provider never confirmed this order. It is sent again with the same idempotency key, so it can never be delivered twice. Safe: no money moves.</p>
                                <form method="POST" action="{{ route('admin.attention.orders.resend', $po) }}" class="mt-3">
                                    @csrf
                                    <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Send again</button>
                                </form>
                            </div>
                        @endif

                        <div class="bg-white shadow-sm sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-900">Mark as completed</h3>
                            <p class="mt-1 text-sm text-gray-600">Use only after you saw in the FazerCards panel that the top-up was delivered. The customer keeps what they paid for.</p>
                            <form method="POST" action="{{ route('admin.attention.orders.complete', $po) }}" class="mt-3 space-y-2">
                                @csrf
                                <textarea name="reason" rows="2" required minlength="5" maxlength="255" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reason, for example: delivered, checked order ord-123 in the provider panel"></textarea>
                                <button class="rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-500">Mark completed</button>
                            </form>
                        </div>

                        <div class="bg-white shadow-sm sm:rounded-lg p-5">
                            <h3 class="font-semibold text-gray-900">Refund the customer</h3>
                            <p class="mt-1 text-sm text-gray-600">Use only after you confirmed that nothing was delivered. The money returns to the customer's wallet, once.</p>
                            <form method="POST" action="{{ route('admin.attention.orders.refund', $po) }}" class="mt-3 space-y-2">
                                @csrf
                                <textarea name="reason" rows="2" required minlength="5" maxlength="255" class="w-full rounded-md border-gray-300 text-sm" placeholder="Reason, for example: provider confirmed it was not delivered"></textarea>
                                <label class="flex items-start gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-gray-300" required>
                                    <span>I checked with the provider that this top-up was NOT delivered.</span>
                                </label>
                                <button class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500">Refund customer</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>
</x-app-layout>