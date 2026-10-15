<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">{{ __(session('error')) }}</div>
            @endif
            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ __(session('status')) }}</div>
            @endif
            @unless ($user->isActive())
                <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                    {{ __('Your account is') }} <strong>{{ __($user->status->label()) }}</strong>. {{ __('You can view your history but cannot deposit or place orders. Please contact support.') }}
                </div>
            @endunless

            {{-- Welcome + wallet --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-gray-500 text-sm">{{ __('Welcome back,') }}</p>
                    <h3 class="text-2xl font-semibold text-gray-900">{{ $user->name }}</h3>
                </div>
                <div class="sm:text-right">
                    <p class="text-gray-500 text-sm">{{ __('Wallet balance') }}</p>
                    <p class="text-3xl font-bold text-indigo-600">
                        {{ \App\Support\Money::format($stats['balance'], $stats['currency']) }}
                    </p>
                </div>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-sm text-gray-500">{{ __('Total orders') }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $stats['total_orders'] }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-sm text-gray-500">{{ __('Pending orders') }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $stats['pending_orders'] }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-sm text-gray-500">{{ __('Completed orders') }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $stats['completed_orders'] }}</p>
                </div>
            </div>

            {{-- Actions --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5 flex flex-wrap gap-3">
                <x-dash-button route="wallet.deposit.create" label="Deposit" :primary="true" />
                <x-dash-button route="games.index" label="Top Up Game" :primary="true" />
                <x-dash-button route="orders.index" label="Orders" />
                <x-dash-button route="wallet.index" label="Wallet" />
                <x-dash-button route="profile.edit" label="Profile" />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Recent orders --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h4 class="font-semibold text-gray-900 mb-3">{{ __('Recent orders') }}</h4>
                    @forelse ($stats['recent_orders'] as $order)
                        <div class="flex items-center justify-between border-t first:border-t-0 py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $order->game_name }} — {{ $order->product_name }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $order->order_number }} · {{ \Illuminate\Support\Carbon::parse($order->created_at)->translatedFormat('M d, Y H:i') }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold">{{ \App\Support\Money::format((string) $order->selling_price) }}</p>
                                <x-status-badge :status="$order->status" />
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No orders yet.') }}</p>
                    @endforelse
                </div>

                {{-- Recent wallet transactions --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h4 class="font-semibold text-gray-900 mb-3">{{ __('Recent wallet transactions') }}</h4>
                    @forelse ($stats['recent_transactions'] as $tx)
                        <div class="flex items-center justify-between border-t first:border-t-0 py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $tx->description ? __($tx->description) : __(ucfirst($tx->type)) }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ \Illuminate\Support\Carbon::parse($tx->created_at)->translatedFormat('M d, Y H:i') }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold {{ $tx->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $tx->direction === 'credit' ? '+' : '−' }}{{ \App\Support\Money::format((string) $tx->amount) }}
                                </p>
                                <x-status-badge :status="$tx->status" />
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No transactions yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>