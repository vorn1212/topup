<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Deposits</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <form method="GET" class="flex flex-wrap items-end gap-3 bg-white shadow-sm sm:rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="q" value="{{ $q }}" placeholder="Deposit no., e-mail, ABA transaction" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach (\App\Enums\DepositStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Filter</button>
                <a href="{{ route('admin.deposits.index') }}" class="text-sm text-gray-500 hover:underline">Reset</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Deposit</th><th>Customer</th><th>Amount</th><th>Status</th><th>Payment step</th><th>ABA transaction</th><th>Created</th><th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deposits as $deposit)
                            <tr class="border-t">
                                <td class="p-3 font-medium text-gray-900">{{ $deposit->deposit_number }}</td>
                                <td>{{ $deposit->user->email }}</td>
                                <td>{{ \App\Support\Money::display((string) $deposit->amount, $deposit->currency) }}</td>
                                <td><x-status-badge :status="$deposit->status->value" /></td>
                                <td class="text-xs text-gray-600">{{ $deposit->gateway_stage ?? '-' }}@if ($deposit->gateway_error) <span class="text-red-600">({{ $deposit->gateway_error }})</span>@endif</td>
                                <td class="font-mono text-xs">{{ $deposit->aba_transaction_id ?? '-' }}</td>
                                <td>{{ $deposit->created_at->format('Y-m-d H:i') }}</td>
                                <td>{{ $deposit->paid_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="8" class="p-4 text-gray-500">No deposits found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $deposits->links() }}
        </div>
    </div>
</x-app-layout>