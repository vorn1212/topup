<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Wallet transactions</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <form method="GET" class="flex flex-wrap items-end gap-3 bg-white shadow-sm sm:rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="q" value="{{ $q }}" placeholder="E-mail, description, key" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Type</label>
                    <select name="type" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach (\App\Enums\WalletTxType::cases() as $case)
                            <option value="{{ $case->value }}" @selected($type === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Direction</label>
                    <select name="direction" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        <option value="credit" @selected($direction === 'credit')>Credit</option>
                        <option value="debit" @selected($direction === 'debit')>Debit</option>
                    </select>
                </div>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Filter</button>
                <a href="{{ route('admin.transactions.index') }}" class="text-sm text-gray-500 hover:underline">Reset</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Date</th><th>Customer</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $tx)
                            <tr class="border-t">
                                <td class="p-3">{{ $tx->created_at->format('Y-m-d H:i:s') }}</td>
                                <td>{{ $tx->user->email }}</td>
                                <td>{{ $tx->type->label() }}</td>
                                <td class="{{ $tx->direction === 'credit' ? 'text-green-700' : 'text-red-700' }}">{{ $tx->direction === 'credit' ? '+' : '-' }}{{ \App\Support\Money::display((string) $tx->amount, $tx->currency) }}</td>
                                <td>{{ \App\Support\Money::display((string) $tx->balance_after, $tx->currency) }}</td>
                                <td class="text-xs text-gray-600">{{ $tx->description }}@if (! empty($tx->meta['reason'])) - {{ $tx->meta['reason'] }}@endif</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="6" class="p-4 text-gray-500">No transactions found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $transactions->links() }}
        </div>
    </div>
</x-app-layout>