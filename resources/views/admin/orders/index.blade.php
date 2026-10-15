<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Orders</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <form method="GET" class="flex flex-wrap items-end gap-3 bg-white shadow-sm sm:rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="q" value="{{ $q }}" placeholder="Order no., e-mail, player ID, game" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Order status</label>
                    <select name="status" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach (\App\Enums\OrderStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Provider status</label>
                    <select name="provider" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach (\App\Enums\ProviderOrderStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected($provider === $case)>{{ ucfirst($case->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">From</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">To</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Filter</button>
                <a href="{{ route('admin.orders.index') }}" class="text-sm text-gray-500 hover:underline">Reset</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Order</th><th>Customer</th><th>Game / package</th><th>Amount</th><th>Order</th><th>Provider</th><th>Date</th><th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr class="border-t">
                                <td class="p-3 font-medium text-gray-900">{{ $order->order_number }}</td>
                                <td>{{ $order->user->email }}</td>
                                <td>{{ $order->game_name }} / {{ $order->product_name }}</td>
                                <td>{{ \App\Support\Money::display((string) $order->selling_price, $order->currency) }}</td>
                                <td><x-status-badge :status="$order->status->value" /></td>
                                <td>@if ($order->providerOrder)<x-status-badge :status="$order->providerOrder->status->value" />@endif</td>
                                <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                                <td class="p-3 text-right"><a class="text-indigo-600 hover:underline" href="{{ route('admin.orders.show', $order) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="8" class="p-4 text-gray-500">No orders found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $orders->links() }}
        </div>
    </div>
</x-app-layout>