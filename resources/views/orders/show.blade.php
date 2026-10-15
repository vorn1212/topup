@php
    $status = $order->status->value;
    $final = in_array($status, ['completed', 'refunded', 'cancelled'], true);
    $money = fn (string $value) => \App\Support\Money::display($value, $order->currency);
    $slow = $order->providerOrder?->status === \App\Enums\ProviderOrderStatus::Review;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Order') }} {{ $order->order_number }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            {{-- Status --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">{{ __('Status') }}</h3>
                    <x-status-badge :status="$status" />
                </div>

                <div class="mt-3 text-sm text-gray-700">
                    @if ($status === 'completed')
                        <p>{{ __('Delivered. Your :product top-up for :game is complete.', ['product' => $order->product_name, 'game' => $order->game_name]) }}</p>
                    @elseif ($status === 'refunded')
                        <p>{{ __('The top-up could not be completed.') }} <strong>{{ $money((string) $order->selling_price) }}</strong> {{ __('was returned to your wallet.') }}</p>
                    @elseif ($status === 'cancelled')
                        <p>{{ __('This order was cancelled.') }}</p>
                    @elseif (in_array($status, ['failed', 'refunding'], true))
                        <p>{{ __('Something went wrong. Your money is being returned to your wallet.') }}</p>
                    @else
                        <p>{{ __('We are delivering your top-up. This usually takes a few minutes. You can leave this page: it updates by itself.') }}</p>
                        @if ($slow)
                            <p class="mt-2 rounded-md bg-yellow-50 p-3 text-yellow-800">{{ __('This is taking longer than usual. Our team is checking it and will contact you if needed.') }}</p>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Details --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="mb-3 font-semibold text-gray-900">{{ __('Details') }}</h3>

                <dl class="divide-y text-sm">
                    <div class="flex justify-between py-2"><dt class="text-gray-500">{{ __('Game') }}</dt><dd class="text-gray-900">{{ $order->game_name }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-gray-500">{{ __('Package') }}</dt><dd class="text-gray-900">{{ $order->product_name }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-gray-500">{{ __('Amount') }}</dt><dd class="font-semibold text-gray-900">{{ $money((string) $order->selling_price) }}</dd></div>

                    @foreach ((array) $order->player_fields as $key => $value)
                        <div class="flex justify-between py-2">
                            <dt class="text-gray-500">{{ __($labels[$key] ?? \Illuminate\Support\Str::headline($key)) }}</dt>
                            <dd class="text-gray-900">{{ $value }}</dd>
                        </div>
                    @endforeach

                    <div class="flex justify-between py-2"><dt class="text-gray-500">{{ __('Ordered') }}</dt><dd class="text-gray-900">{{ $order->created_at->translatedFormat('M d, Y H:i') }}</dd></div>
                    @if ($order->completed_at)
                        <div class="flex justify-between py-2"><dt class="text-gray-500">{{ __('Completed') }}</dt><dd class="text-gray-900">{{ $order->completed_at->translatedFormat('M d, Y H:i') }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Wallet --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="mb-3 font-semibold text-gray-900">{{ __('Wallet') }}</h3>

                <div class="space-y-2 text-sm">
                    @if ($order->walletTransaction)
                        <div class="flex justify-between">
                            <span class="text-gray-700">{{ __('Payment') }} · {{ $order->walletTransaction->created_at->translatedFormat('M d, Y H:i') }}</span>
                            <span class="font-semibold text-red-600">−{{ $money((string) $order->walletTransaction->amount) }}</span>
                        </div>
                    @endif
                    @if ($refund)
                        <div class="flex justify-between">
                            <span class="text-gray-700">{{ __('Refund') }} · {{ $refund->created_at->translatedFormat('M d, Y H:i') }}</span>
                            <span class="font-semibold text-green-600">+{{ $money((string) $refund->amount) }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex gap-4 text-sm">
                <a href="{{ route('orders.index') }}" class="text-indigo-600 hover:underline">← {{ __('All orders') }}</a>
                <a href="{{ route('games.index') }}" class="text-indigo-600 hover:underline">{{ __('Top up another game') }}</a>
            </div>
        </div>
    </div>

    @unless ($final)
        <script>
            (function () {
                var url = @js(route('orders.status', $order->order_number));
                var current = @js($status);

                var timer = setInterval(async function () {
                    try {
                        var response = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        if (!response.ok) return;

                        var json = await response.json();
                        if (json.data.status !== current) {
                            clearInterval(timer);
                            window.location.reload();
                        }
                    } catch (e) { /* try again on the next tick */ }
                }, 4000);
            })();
        </script>
    @endunless
</x-app-layout>