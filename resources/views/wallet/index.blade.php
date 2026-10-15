<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Wallet') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @unless ($user->isActive())
                <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                    {{ __('Your account is') }} <strong>{{ __($user->status->label()) }}</strong>. {{ __('You can view your history but cannot deposit or place orders.') }}
                </div>
            @endunless

            @if ($wallet->isFrozen())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                    {{ __('Your wallet is frozen. Purchases are disabled. Please contact support.') }}
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="text-sm text-gray-500">{{ __('Available balance') }}</p>
                    <p class="text-4xl font-bold text-indigo-600">
                        {{ \App\Support\Money::format($balance, $wallet->currency) }}
                    </p>
                </div>
                <x-dash-button route="wallet.deposit.create" label="Deposit" :primary="true" />
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h3 class="font-semibold text-gray-900">{{ __('Transaction history') }}</h3>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <a href="{{ route('wallet.index') }}"
                           class="rounded-full px-3 py-1 border {{ $type === null ? 'bg-indigo-600 text-white border-indigo-600' : 'text-gray-600 border-gray-300 hover:bg-gray-50' }}">{{ __('All') }}</a>
                        @foreach (\App\Enums\WalletTxType::cases() as $case)
                            <a href="{{ route('wallet.index', ['type' => $case->value]) }}"
                               class="rounded-full px-3 py-1 border {{ $type === $case ? 'bg-indigo-600 text-white border-indigo-600' : 'text-gray-600 border-gray-300 hover:bg-gray-50' }}">{{ __($case->label()) }}</a>
                        @endforeach
                    </div>
                </div>

                @forelse ($transactions as $tx)
                    <div class="flex items-center justify-between border-t first:border-t-0 py-3">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $tx->description ? __($tx->description) : __($tx->type->label()) }}</p>
                            <p class="text-xs text-gray-500">
                                {{ __($tx->type->label()) }} · {{ $tx->created_at->translatedFormat('M d, Y H:i') }}
                                · {{ __('Balance after') }} {{ \App\Support\Money::format((string) $tx->balance_after, $tx->currency) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold {{ $tx->isCredit() ? 'text-green-600' : 'text-red-600' }}">
                                {{ $tx->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format((string) $tx->amount, $tx->currency) }}
                            </p>
                            <x-status-badge :status="$tx->status" />
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No transactions found.') }}</p>
                @endforelse

                <div class="mt-4">{{ $transactions->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>