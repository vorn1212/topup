@php
    $canLoss = auth()->user()->can('pricing.allow_loss');
    $blocked = $summary['invalid'] > 0
        || $summary['writes'] === 0
        || ($summary['below_cost'] > 0 && ! $canLoss);

    $badges = [
        'ok' => 'bg-green-100 text-green-800',
        'unchanged' => 'bg-gray-100 text-gray-700',
        'below_cost' => 'bg-yellow-100 text-yellow-800',
        'invalid' => 'bg-red-100 text-red-800',
    ];
    $labels = ['ok' => 'Will change', 'unchanged' => 'No change', 'below_cost' => 'Below cost', 'invalid' => 'Invalid'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Review bulk price change</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <p class="text-sm text-gray-500">Action</p>
                <p class="font-medium text-gray-900">{{ $operation->describe($value) }}</p>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $summary['total'] }} selected ·
                    <strong>{{ $summary['writes'] }}</strong> will change ·
                    {{ $summary['unchanged'] }} unchanged
                    @if ($summary['below_cost'] > 0) · <span class="text-yellow-700">{{ $summary['below_cost'] }} below cost</span> @endif
                    @if ($summary['invalid'] > 0) · <span class="text-red-700">{{ $summary['invalid'] }} invalid</span> @endif
                </p>
            </div>

            @if ($summary['invalid'] > 0)
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                    Some products would get an invalid price. The whole batch is blocked: nothing can be applied until you go back and untick them or change the value.
                </div>
            @endif
            @if ($summary['below_cost'] > 0)
                <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                    @if ($canLoss)
                        Some products would be sold <strong>below their provider cost</strong>. Tick the loss confirmation below only if this is intended.
                    @else
                        Some products would be sold <strong>below their provider cost</strong>. Only a super admin can approve that, so this batch is blocked. Go back and adjust it.
                    @endif
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Game</th><th>Product</th><th>Cost</th><th>Old price</th><th>New price</th><th>Change</th><th>Profit after</th><th class="p-3">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $product = $row['product'];
                                $hasNew = $row['new_price'] !== null;
                                $diff = $hasNew ? \App\Support\Money::sub($row['new_price'], $row['old_price']) : null;
                                $diffText = $diff === null ? '—'
                                    : ((\App\Support\Money::cmp($diff, '0') > 0 ? '+' : (\App\Support\Money::isNegative($diff) ? '−' : ''))
                                        .\App\Support\Money::display(ltrim($diff, '-')));
                            @endphp
                            <tr class="border-t align-top">
                                <td class="p-3 text-gray-600">{{ $product->game->name }}</td>
                                <td>
                                    <p class="font-medium text-gray-900">{{ $product->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $product->sku }}</p>
                                </td>
                                <td>{{ \App\Support\Money::display($row['old_cost']) }}</td>
                                <td>{{ \App\Support\Money::display($row['old_price']) }}</td>
                                <td class="font-semibold">
                                    {{ $hasNew ? \App\Support\Money::display($row['new_price']) : '—' }}
                                    @if ($hasNew && $row['new_type'] !== $row['old_type'])
                                        <span class="block text-xs font-normal text-gray-500">mode: {{ str_replace('_', ' ', $row['new_type']->value) }}</span>
                                    @endif
                                </td>
                                <td>{{ $diffText }}</td>
                                <td>{{ $hasNew ? \App\Support\Money::display(\App\Support\Money::sub($row['new_price'], $row['old_cost'])) : '—' }}</td>
                                <td class="p-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badges[$row['state']] }}">{{ $labels[$row['state']] }}</span>
                                    @if ($row['message'])
                                        <span class="block text-xs text-gray-500">{{ $row['message'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('admin.products.bulk.apply') }}" class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                @csrf
                @foreach ($productIds as $id)
                    <input type="hidden" name="product_ids[]" value="{{ $id }}" />
                @endforeach
                <input type="hidden" name="operation" value="{{ $operation->value }}" />
                <input type="hidden" name="value" value="{{ $value }}" />
                <input type="hidden" name="fingerprint" value="{{ $fingerprint }}" />

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="reason">Reason for this change</label>
                    <input id="reason" name="reason" type="text" required value="{{ old('reason') }}" placeholder="FazerCards cost increase"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                </div>

                <label class="block text-sm text-gray-700">
                    <input type="checkbox" name="confirm" value="1" class="rounded border-gray-300">
                    I reviewed the table above and want to change {{ $summary['writes'] }} price(s).
                </label>
                <x-input-error :messages="$errors->get('confirm')" class="mt-1" />

                @if ($summary['below_cost'] > 0 && $canLoss)
                    <label class="block text-sm text-yellow-800">
                        <input type="checkbox" name="confirm_loss" value="1" class="rounded border-gray-300">
                        I confirm selling {{ $summary['below_cost'] }} product(s) below cost.
                    </label>
                @endif

                <div class="flex items-center gap-3">
                    <button @disabled($blocked)
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                        Apply changes
                    </button>
                    <a href="{{ route('admin.products.index') }}" class="text-sm text-gray-600 hover:underline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>