@php
    $bulkHints = collect(\App\Enums\BulkOperation::cases())
        ->mapWithKeys(fn ($case) => [$case->value => $case->hint()])
        ->all();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Products</h2>
            @can('pricing.manage')
                <a href="{{ route('admin.products.create') }}"
                   class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">New product</a>
            @endcan
        </div>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />
            <x-input-error :messages="$errors->get('delete')" />

            <form method="GET" class="flex flex-wrap gap-2">
                <input type="text" name="q" value="{{ $search }}" placeholder="Search name or SKU"
                       class="rounded-md border-gray-300 text-sm shadow-sm w-56" />
                <select name="game_id" class="rounded-md border-gray-300 text-sm shadow-sm">
                    <option value="">All games</option>
                    @foreach ($games as $game)
                        <option value="{{ $game->id }}" @selected($gameId === $game->id)>{{ $game->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-md border-gray-300 text-sm shadow-sm">
                    <option value="">Any status</option>
                    @foreach (\App\Enums\CatalogStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">Filter</button>
            </form>

            @can('pricing.manage')
                {{-- Bulk pricing: tick products in the table, choose an action, preview. Nothing changes until you confirm. --}}
                <form id="bulk-form" method="POST" action="{{ route('admin.products.bulk.preview') }}"
                      class="bg-white shadow-sm sm:rounded-lg p-4"
                      x-data="{ op: @js(old('operation', 'add_amount')), hints: @js($bulkHints) }">
                    @csrf
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs text-gray-500" for="bulk_operation">Bulk action for ticked products</label>
                            <select id="bulk_operation" name="operation" x-model="op" class="rounded-md border-gray-300 text-sm shadow-sm">
                                @foreach (\App\Enums\BulkOperation::cases() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500" for="bulk_value">Value</label>
                            <input id="bulk_value" name="value" type="text" inputmode="decimal" required
                                   value="{{ old('value') }}" :placeholder="hints[op]"
                                   class="rounded-md border-gray-300 text-sm shadow-sm w-44" />
                        </div>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Preview changes</button>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Tick products in the table first. You will see every old and new price before anything changes.</p>
                    <x-input-error :messages="$errors->get('product_ids')" class="mt-1" />
                    <x-input-error :messages="$errors->get('product_ids.*')" class="mt-1" />
                    <x-input-error :messages="$errors->get('operation')" class="mt-1" />
                    <x-input-error :messages="$errors->get('value')" class="mt-1" />
                </form>
            @endcan

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            @can('pricing.manage')
                                <th class="p-3 w-8"><input type="checkbox" id="select-all" class="rounded border-gray-300" title="Select all on this page"></th>
                            @endcan
                            <th class="p-3">Game</th><th>Product</th><th>Cost</th><th>Selling</th><th>Profit</th><th>Status</th><th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            <tr class="border-t">
                                @can('pricing.manage')
                                    <td class="p-3">
                                        <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" form="bulk-form"
                                               class="row-check rounded border-gray-300" />
                                    </td>
                                @endcan
                                <td class="p-3 text-gray-600">{{ $product->game->name }}</td>
                                <td>
                                    <p class="font-medium text-gray-900">{{ $product->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $product->sku }}</p>
                                </td>
                                <td>{{ \App\Support\Money::display((string) $product->provider_cost, $product->currency) }}</td>
                                <td class="font-semibold">{{ \App\Support\Money::display((string) $product->selling_price, $product->currency) }}</td>
                                <td class="{{ $product->isBelowCost() ? 'text-red-600' : 'text-green-700' }}">
                                    {{ \App\Support\Money::display($product->profit(), $product->currency) }}
                                </td>
                                <td><x-status-badge :status="$product->status->value" /></td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    @can('pricing.manage')
                                        @foreach (['-0.10' => '− $0.10', '0.10' => '+ $0.10'] as $delta => $text)
                                            <form method="POST" action="{{ route('admin.products.price.adjust', $product) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="delta" value="{{ $delta }}" />
                                                <button class="mr-1 rounded border border-gray-300 px-2 py-0.5 text-xs hover:bg-gray-50">{{ $text }}</button>
                                            </form>
                                        @endforeach
                                    @endcan
                                    <a href="{{ route('admin.products.edit', $product) }}" class="ml-1 text-indigo-600 hover:underline">Edit</a>
                                    <a href="{{ route('admin.products.history', $product) }}" class="ml-3 text-gray-600 hover:underline">History</a>
                                    <form method="POST" action="{{ route('admin.products.toggle', $product) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <button class="ml-3 text-gray-600 hover:underline">{{ $product->status->value === 'active' ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="inline"
                                      onsubmit="return confirm('Delete this product permanently?');">
                                    @csrf @method('DELETE')
                                    <button class="ml-3 text-red-600 hover:underline">Delete</button>
                                </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="p-6 text-center text-gray-500">No products found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $products->links() }}
        </div>
    </div>

    <script>
        (function () {
            var all = document.getElementById('select-all');
            if (!all) return;
            all.addEventListener('change', function () {
                document.querySelectorAll('input.row-check').forEach(function (box) { box.checked = all.checked; });
            });
        })();
    </script>
</x-app-layout>