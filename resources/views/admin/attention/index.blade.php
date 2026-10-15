<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Needs attention</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <div class="grid gap-4 md:grid-cols-2">
                {{-- Provider balance --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900">Provider balance</h3>
                        @can('orders.manage')
                            @if ($balanceEnabled)
                                <form method="POST" action="{{ route('admin.attention.balance') }}">
                                    @csrf
                                    <button class="text-sm text-indigo-600 hover:underline">Check now</button>
                                </form>
                            @endif
                        @endcan
                    </div>

                    @if (! $balanceEnabled)
                        <p class="mt-2 text-sm text-gray-600">Not checked: no provider API key, or the fake provider is on.</p>
                    @elseif ($balance)
                        <p class="mt-2 text-2xl font-bold {{ $balanceLow ? 'text-red-600' : 'text-indigo-600' }}">
                            {{ \App\Support\Money::format($balance['balance'], $balance['currency']) }}
                        </p>
                        <p class="text-xs text-gray-500">Checked {{ \Illuminate\Support\Carbon::createFromTimestamp($balance['checked_at'])->diffForHumans() }}</p>
                        @if ($balanceLow)
                            <p class="mt-3 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">The balance is low. Top it up in the FazerCards panel. Orders that cost more than the balance are refused before the customer is charged.</p>
                        @endif
                    @else
                        <p class="mt-2 text-sm text-gray-600">No reading yet. Press "Check now", or wait for the scheduler (every 10 minutes).</p>
                    @endif
                </div>

                {{-- Webhook --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-900">Webhook</h3>
                    @if ($webhookEnabled)
                        <p class="mt-2 text-sm text-green-700">Enabled. Deliveries must be signed with the secret in your .env.</p>
                        <p class="mt-2 break-all rounded bg-gray-50 p-2 font-mono text-xs text-gray-700">{{ $webhookUrl }}</p>
                    @else
                        <p class="mt-2 text-sm text-gray-600">Off (FAZERCARDS_WEBHOOK_SECRET is empty). Orders are still tracked by polling every minute, so nothing is lost.</p>
                    @endif
                </div>
            </div>

            {{-- Review --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2">
                    <h3 class="font-semibold text-gray-900">Waiting for a decision ({{ $review->count() }})</h3>
                    <p class="text-sm text-gray-600">Automatic handling stopped. Check the order with the provider, then pick an action.</p>
                </div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Order</th><th>Customer</th><th>Game / package</th><th>Amount</th><th>Provider id</th><th>Since</th><th>Last error</th><th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($review as $po)
                            <tr class="border-t">
                                <td class="p-3 font-medium text-gray-900">{{ $po->order->order_number }}</td>
                                <td>{{ $po->order->user->email }}</td>
                                <td>{{ $po->order->game_name }} / {{ $po->order->product_name }}</td>
                                <td>{{ \App\Support\Money::display((string) $po->order->selling_price, $po->order->currency) }}</td>
                                <td class="font-mono text-xs">{{ $po->provider_order_id ?? 'none' }}</td>
                                <td>{{ $po->updated_at->diffForHumans() }}</td>
                                <td class="max-w-xs truncate text-xs text-gray-600" title="{{ $po->last_error }}">{{ $po->last_error }}</td>
                                <td class="p-3 text-right"><a class="text-indigo-600 hover:underline" href="{{ route('admin.attention.orders.show', $po) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="8" class="p-4 text-gray-500">Nothing is waiting. 🎉</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Stuck --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2">
                    <h3 class="font-semibold text-gray-900">Not sent for 30+ minutes ({{ $stuck->count() }})</h3>
                    <p class="text-sm text-gray-600">Normally empty. Make sure the queue worker and the scheduler are running.</p>
                </div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Order</th><th>Customer</th><th>Provider state</th><th>Attempts</th><th>Since</th><th>Last error</th><th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stuck as $po)
                            <tr class="border-t">
                                <td class="p-3 font-medium text-gray-900">{{ $po->order->order_number }}</td>
                                <td>{{ $po->order->user->email }}</td>
                                <td><x-status-badge :status="$po->status->value" /></td>
                                <td>{{ $po->attempts }}</td>
                                <td>{{ $po->updated_at->diffForHumans() }}</td>
                                <td class="max-w-xs truncate text-xs text-gray-600" title="{{ $po->last_error }}">{{ $po->last_error }}</td>
                                <td class="p-3 text-right"><a class="text-indigo-600 hover:underline" href="{{ route('admin.attention.orders.show', $po) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="7" class="p-4 text-gray-500">No stuck orders.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Webhook log --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Latest webhook deliveries</h3></div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Received</th><th>Event</th><th>Provider order</th><th>Result</th><th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr class="border-t">
                                <td class="p-3">{{ $event->created_at->diffForHumans() }}</td>
                                <td>{{ $event->event_type ?? '-' }}</td>
                                <td class="font-mono text-xs">{{ $event->provider_order_id ?? '-' }}</td>
                                <td>{{ ucfirst($event->status) }}</td>
                                <td class="text-xs text-gray-600">{{ $event->note }}</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="5" class="p-4 text-gray-500">No deliveries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>