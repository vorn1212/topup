<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Deposit') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">{{ __(session('error')) }}</div>
            @endif

            @unless ($user->isActive())
                <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                    {{ __('Your account is') }} <strong>{{ __($user->status->label()) }}</strong>. {{ __('Deposits are disabled. Please contact support.') }}
                </div>
            @endunless

            <div class="bg-white shadow-sm sm:rounded-lg p-6" x-data="{ amount: '{{ old('amount') }}' }">
                <p class="text-sm text-gray-500">{{ __('Current balance') }}</p>
                <p class="text-2xl font-bold text-indigo-600 mb-4">
                    {{ \App\Support\Money::format($balance, $wallet->currency) }}
                </p>

                @unless ($gatewayReady)
                    <div class="rounded-md bg-blue-50 p-3 text-sm text-blue-800 mb-4">
                        {{ __('Online deposits are not available right now.') }}
                    </div>
                @endunless

                <form method="POST" action="{{ $gatewayReady ? route('wallet.deposit.store') : '#' }}">
                    @csrf
                    <fieldset @disabled(! $gatewayReady || ! $user->isActive())>
                        <label for="amount" class="block text-sm font-medium text-gray-700">
                            {{ __('Deposit amount') }} ({{ $wallet->currency }})
                        </label>
                        <div class="flex flex-wrap gap-2 my-2">
                            @foreach ([5, 10, 20, 50] as $preset)
                                <button type="button" @click="amount = '{{ $preset }}'"
                                        class="rounded-md border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50">
                                    ${{ $preset }}
                                </button>
                            @endforeach
                        </div>
                        <input id="amount" name="amount" type="number" step="0.01" inputmode="decimal"
                               min="{{ $min }}" max="{{ $max }}" x-model="amount" required
                               class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100"
                               placeholder="{{ \App\Support\Money::format($min) }} – {{ \App\Support\Money::format($max) }}" />
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />

                        <button type="submit"
                                class="mt-4 inline-flex items-center rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ __('Deposit') }}
                        </button>
                    </fieldset>
                </form>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <h3 class="font-semibold text-gray-900 mb-3">{{ __('Recent deposits') }}</h3>
                @forelse ($deposits as $deposit)
                    <a href="{{ route('wallet.deposit.show', $deposit->deposit_number) }}"
                       class="flex items-center justify-between border-t first:border-t-0 py-3 hover:bg-gray-50">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $deposit->deposit_number }}</p>
                            <p class="text-xs text-gray-500">{{ $deposit->created_at->translatedFormat('M d, Y H:i') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold">{{ \App\Support\Money::format((string) $deposit->amount, $deposit->currency) }}</p>
                            <x-status-badge :status="$deposit->status->value" />
                        </div>
                    </a>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No deposits yet.') }}</p>
                @endforelse
            </div>

            <a href="{{ route('wallet.index') }}" class="text-sm text-indigo-600 hover:underline">← {{ __('Back to wallet') }}</a>
        </div>
    </div>
</x-app-layout>