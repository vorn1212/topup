@php
    $fromText = $range['from']->format('Y-m-d');
    $toText = $range['to']->format('Y-m-d');
    $days = (int) round($range['from']->copy()->startOfDay()->diffInDays($range['to']->copy()->startOfDay())) + 1;
    $exportQuery = ['preset' => 'custom', 'from' => $fromText, 'to' => $toText];
    $profitClass = fn ($value) => bccomp((string) $value, '0', 4) < 0 ? 'text-red-600' : 'text-gray-900';
    $exportLabels = [
        'daily' => 'Days',
        'games' => 'Games',
        'products' => 'Packages',
        'customers' => 'Customers',
        'orders' => 'Orders',
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Reports</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            {{-- Period --}}
            <form method="GET" action="{{ route('admin.reports.index') }}" class="flex flex-wrap items-end gap-3 bg-white shadow-sm sm:rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500">Period</label>
                    <select name="preset" class="rounded-md border-gray-300 text-sm">
                        @foreach ($presets as $value => $label)
                            <option value="{{ $value }}" @selected($range['preset'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">From (custom range)</label>
                    <input type="date" name="from" value="{{ $range['preset'] === 'custom' ? $fromText : '' }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">To (custom range)</label>
                    <input type="date" name="to" value="{{ $range['preset'] === 'custom' ? $toText : '' }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Show</button>
                <a href="{{ route('admin.reports.index') }}" class="text-sm text-gray-500 hover:underline">Reset</a>
            </form>

            @if ($range['notice'])
                <div class="rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">{{ $range['notice'] }}</div>
            @endif

            <p class="text-sm text-gray-600">
                Showing <span class="font-medium text-gray-900">{{ $fromText }}</span> to <span class="font-medium text-gray-900">{{ $toText }}</span>
                ({{ $days }} {{ $days === 1 ? 'day' : 'days' }}). Amounts are in {{ $reports->currency() }}.
                Sales count on the day the top-up was completed, deposits on the day they were paid.
            </p>

            {{-- Key numbers --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Revenue</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">{{ $reports->money($summary['revenue']) }}</p>
                    <p class="text-xs text-gray-500">{{ $summary['orders'] }} completed {{ $summary['orders'] === 1 ? 'order' : 'orders' }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Provider cost</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">{{ $reports->money($summary['cost']) }}</p>
                    <p class="text-xs text-gray-500">Average order {{ $reports->money($summary['average']) }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Profit</p>
                    <p class="mt-1 text-xl font-bold {{ $profitClass($summary['profit']) }}">{{ $reports->money($summary['profit']) }}</p>
                    <p class="text-xs text-gray-500">Margin {{ $summary['margin'] }}%</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Deposits paid</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">{{ $reports->money($summary['deposited']) }}</p>
                    <p class="text-xs text-gray-500">{{ $summary['deposits'] }} {{ $summary['deposits'] === 1 ? 'deposit' : 'deposits' }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Refunded</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">{{ $reports->money($summary['refunded_amount']) }}</p>
                    <p class="text-xs text-gray-500">{{ $summary['refunded'] }} {{ $summary['refunded'] === 1 ? 'order' : 'orders' }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Customer wallets (now)</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">{{ $reports->money($summary['wallets_total']) }}</p>
                    <p class="text-xs text-gray-500">{{ $summary['wallets_funded'] }} of {{ $summary['wallets_count'] }} wallets hold money</p>
                </div>
            </div>

            {{-- Outcomes --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <h3 class="font-semibold text-gray-900">What happened to the orders created in this period</h3>
                <p class="text-sm text-gray-600">{{ $summary['created'] }} {{ $summary['created'] === 1 ? 'order was' : 'orders were' }} created.</p>
                <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-gray-500">Completed</dt>
                        <dd class="font-medium text-gray-900">{{ $summary['completed'] }} <span class="text-gray-500">({{ $summary['completion_rate'] }}%)</span></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Refunded</dt>
                        <dd class="font-medium text-gray-900">{{ $summary['refunded'] }} <span class="text-gray-500">({{ $summary['refund_rate'] }}%)</span></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Failed or cancelled</dt>
                        <dd class="font-medium text-gray-900">{{ $summary['failed'] }} <span class="text-gray-500">({{ $summary['failure_rate'] }}%)</span></dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Still open</dt>
                        <dd class="font-medium text-gray-900">{{ $summary['open'] }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Downloads --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <h3 class="font-semibold text-gray-900">Download as CSV</h3>
                <p class="text-sm text-gray-600">For the period shown above. Opens in Excel or Google Sheets. Every download is recorded in the audit log.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($exports as $type)
                        <a href="{{ route('admin.reports.export', ['type' => $type] + $exportQuery) }}" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">{{ $exportLabels[$type] ?? ucfirst($type) }}</a>
                    @endforeach
                </div>
            </div>

            {{-- Top lists --}}
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                    <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Top games</h3></div>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500"><th class="p-3">Game</th><th>Orders</th><th>Revenue</th><th>Profit</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($games as $row)
                                <tr class="border-t">
                                    <td class="p-3 font-medium text-gray-900">{{ $row['name'] }}</td>
                                    <td>{{ $row['orders'] }}</td>
                                    <td>{{ $reports->money($row['revenue']) }}</td>
                                    <td class="{{ $profitClass($row['profit']) }}">{{ $reports->money($row['profit']) }}</td>
                                </tr>
                            @empty
                                <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">No completed orders in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                    <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Top packages</h3></div>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500"><th class="p-3">Package</th><th>Orders</th><th>Revenue</th><th>Profit</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($products as $row)
                                <tr class="border-t">
                                    <td class="p-3"><span class="font-medium text-gray-900">{{ $row['name'] }}</span><span class="block text-xs text-gray-500">{{ $row['game'] }}</span></td>
                                    <td>{{ $row['orders'] }}</td>
                                    <td>{{ $reports->money($row['revenue']) }}</td>
                                    <td class="{{ $profitClass($row['profit']) }}">{{ $reports->money($row['profit']) }}</td>
                                </tr>
                            @empty
                                <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">No completed orders in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Top customers</h3></div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500"><th class="p-3">Customer</th><th>Orders</th><th>Spent</th><th>Profit</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $row)
                            <tr class="border-t">
                                <td class="p-3">
                                    @can('users.view')
                                        <a href="{{ route('admin.users.show', $row['id']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['email'] }}</a>
                                    @else
                                        <span class="font-medium text-gray-900">{{ $row['email'] }}</span>
                                    @endcan
                                    <span class="block text-xs text-gray-500">{{ $row['name'] }}</span>
                                </td>
                                <td>{{ $row['orders'] }}</td>
                                <td>{{ $reports->money($row['revenue']) }}</td>
                                <td class="{{ $profitClass($row['profit']) }}">{{ $reports->money($row['profit']) }}</td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="4" class="p-4 text-gray-500">No completed orders in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Day by day --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <div class="p-5 pb-2"><h3 class="font-semibold text-gray-900">Day by day</h3></div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Day</th><th>Orders</th><th>Revenue</th><th>Provider cost</th><th>Profit</th><th>Deposits</th><th>Deposited</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($daily as $row)
                            <tr class="border-t {{ $row['orders'] === 0 && $row['deposits'] === 0 ? 'text-gray-400' : '' }}">
                                <td class="p-3">{{ $row['day'] }}</td>
                                <td>{{ $row['orders'] }}</td>
                                <td>{{ $reports->money($row['revenue']) }}</td>
                                <td>{{ $reports->money($row['cost']) }}</td>
                                <td class="{{ $row['orders'] > 0 ? $profitClass($row['profit']) : '' }}">{{ $reports->money($row['profit']) }}</td>
                                <td>{{ $row['deposits'] }}</td>
                                <td>{{ $reports->money($row['deposited']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t bg-gray-50 font-medium text-gray-900">
                            <td class="p-3">Total</td>
                            <td>{{ $summary['orders'] }}</td>
                            <td>{{ $reports->money($summary['revenue']) }}</td>
                            <td>{{ $reports->money($summary['cost']) }}</td>
                            <td class="{{ $profitClass($summary['profit']) }}">{{ $reports->money($summary['profit']) }}</td>
                            <td>{{ $summary['deposits'] }}</td>
                            <td>{{ $reports->money($summary['deposited']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>