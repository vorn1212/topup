<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('My orders') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">{{ __('Order') }}</th><th>{{ __('Game') }}</th><th>{{ __('Package') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th><th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr class="border-t">
                                <td class="p-3 font-medium text-gray-900">{{ $order->order_number }}</td>
                                <td>{{ $order->game_name }}</td>
                                <td>{{ $order->product_name }}</td>
                                <td>{{ \App\Support\Money::display((string) $order->selling_price, $order->currency) }}</td>
                                <td><x-status-badge :status="$order->status->value" /></td>
                                <td class="whitespace-nowrap text-gray-500">{{ $order->created_at->translatedFormat('M d, Y H:i') }}</td>
                                <td class="p-3 text-right">
                                    <a href="{{ route('orders.show', $order->order_number) }}" class="text-indigo-600 hover:underline">{{ __('View details') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="p-6 text-center text-gray-500">
                                    {{ __('No orders yet.') }}
                                    <a href="{{ route('games.index') }}" class="text-indigo-600 hover:underline">{{ __('Browse games') }}</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $orders->links() }}
        </div>
    </div>
</x-app-layout>