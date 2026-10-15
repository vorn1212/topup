@php
    $fields = (array) $order->player_fields;
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

            <a href="{{ route('admin.orders.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; All orders</a>

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div><p class="text-gray-500">Order</p><x-status-badge :status="$order->status->value" /></div>
                    <div><p class="text-gray-500">Provider</p>@if ($po)<x-status-badge :status="$po->status->value" />@else<span>-</span>@endif</div>
                    <div>
                        <p class="text-gray-500">Customer</p>
                        @can('users.view')
                            <a class="font-medium text-indigo-600 hover:underline" href="{{ route('admin.users.show', $order->user_id) }}">{{ $order->user->email }}</a>
                        @else
                            <p class="font-medium">{{ $order->user->email }}</p>
                        @endcan
                    </div>
                    <div><p class="text-gray-500">Created</p><p class="font-medium">{{ $order->created_at->format('Y-m-d H:i') }}</p></div>
                    <div><p class="text-gray-500">Game</p><p class="font-medium">{{ $order->game_name }}</p></div>
                    <div><p class="text-gray-500">Package</p><p class="font-medium">{{ $order->product_name }}</p></div>
                    <div><p class="text-gray-500">Customer paid</p><p class="font-medium">{{ \App\Support\Money::display((string) $order->selling_price, $order->currency) }}</p></div>
                    <div><p class="text-gray-500">Our cost / profit</p><p class="font-medium">{{ \App\Support\Money::display((string) $order->provider_cost, $order->currency) }} / {{ \App\Support\Money::display((string) $order->profit, $order->currency) }}</p></div>
                </div>

                <dl class="mt-5 space-y-1 text-sm">
                    @foreach ($fields as $key => $value)
                        <div class="flex gap-2"><dt class="w-40 text-gray-500">{{ $key }}</dt><dd class="font-mono">{{ is_scalar($value) ? $value : json_encode($value) }}</dd></div>
                    @endforeach
                    @if ($order->failure_reason)
                        <div class="flex gap-2"><dt class="w-40 text-gray-500">Failure reason</dt><dd class="text-red-700">{{ $order->failure_reason }}</dd></div>
                    @endif
                    @if ($order->completed_at)
                        <div class="flex gap-2"><dt class="w-40 text-gray-500">Completed</dt><dd>{{ $order->completed_at->format('Y-m-d H:i') }}</dd></div>
                    @endif
                    @if ($po)
                        <div class="flex gap-2"><dt class="w-40 text-gray-500">Provider order id</dt><dd class="font-mono">{{ $po->provider_order_id ?? 'none' }}</dd></div>
                        <div class="flex gap-2"><dt class="w-40 text-gray-500">Attempts</dt><dd>{{ $po->attempts }}</dd></div>
                        @if ($po->last_error)
                            <div class="flex gap-2"><dt class="w-40 text-gray-500">Last error</dt><dd class="text-red-700">{{ $po->last_error }}</dd></div>
                        @endif
                    @endif
                </dl>

                @if ($po && $po->status === \App\Enums\ProviderOrderStatus::Review)
                    @can('orders.view')
                        <a href="{{ route('admin.attention.orders.show', $po) }}" class="mt-4 inline-block rounded-md bg-orange-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-orange-400">This order needs a decision</a>
                    @endcan
                @endif
            </div>

            {{-- Money --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Wallet entries of this order</h3></div>
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="p-3">Date</th><th>Type</th><th>Amount</th><th>Balance after</th></tr></thead>
                    <tbody>
                        @forelse ($ledger as $tx)
                            <tr class="border-t">
                                <td class="p-3">{{ $tx->created_at->format('Y-m-d H:i:s') }}</td>
                                <td>{{ $tx->type->label() }}</td>
                                <td class="{{ $tx->direction === 'credit' ? 'text-green-700' : 'text-red-700' }}">{{ $tx->direction === 'credit' ? '+' : '-' }}{{ \App\Support\Money::display((string) $tx->amount, $tx->currency) }}</td>
                                <td>{{ \App\Support\Money::display((string) $tx->balance_after, $tx->currency) }}</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">None.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Timeline --}}
            @can('audit.view')
                <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                    <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Timeline</h3></div>
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="p-3">When</th><th>By</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                            @forelse ($timeline as $log)
                                <tr class="border-t">
                                    <td class="p-3">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $log->actor?->email ?? 'system' }}</td>
                                    <td>{{ $log->action }}</td>
                                    <td class="text-xs text-gray-600">{{ json_encode($log->new_values) }}</td>
                                </tr>
                            @empty
                                <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">Nothing recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endcan

            {{-- Notes --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <h3 class="font-semibold text-gray-900">Staff notes</h3>
                <p class="text-sm text-gray-600">Private. The customer never sees them. Notes cannot be edited or deleted.</p>

                @can('orders.manage')
                    <form method="POST" action="{{ route('admin.orders.notes.store', $order) }}" class="mt-3 space-y-2">
                        @csrf
                        <textarea name="body" rows="2" required minlength="2" maxlength="1000" class="w-full rounded-md border-gray-300 text-sm" placeholder="Write a note about this order"></textarea>
                        <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Add note</button>
                    </form>
                @endcan

                <div class="mt-4 space-y-3">
                    @forelse ($notes as $note)
                        <div class="rounded-md bg-gray-50 p-3 text-sm">
                            <p class="whitespace-pre-line text-gray-800">{{ $note->body }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $note->author?->email ?? 'removed user' }} - {{ $note->created_at->format('Y-m-d H:i') }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No notes yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>