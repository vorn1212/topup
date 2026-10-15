@can('provider.manage')
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <h3 class="font-semibold text-gray-900">Provider (FazerCards)</h3>

        @if (blank($game->provider_category_id))
            <p class="text-sm text-gray-600">
                This game is not linked to a provider category. Link it from
                <a href="{{ route('admin.provider.index') }}" class="text-indigo-600 hover:underline">Provider</a>,
                or type a category ID above and save.
            </p>
        @else
            <p class="text-sm text-gray-700">
                Linked category: <code>{{ $game->provider_category_id }}</code>
                @if ($mirror) · {{ $mirror->name }} @endif
                · {{ $offerCount }} offer(s) mirrored · {{ $importedCount }} product(s) linked
                @if ($mirror?->synced_at) · synced {{ $mirror->synced_at->diffForHumans() }} @endif
            </p>

            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('admin.games.provider.refresh', $game) }}">
                    @csrf
                    <button class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Refresh from provider</button>
                </form>
                <form method="POST" action="{{ route('admin.games.provider.fields', $game) }}">
                    @csrf
                    <button class="rounded-md border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Import player fields</button>
                </form>
            </div>

            @can('pricing.manage')
                <form method="POST" action="{{ route('admin.games.provider.offers', $game) }}" class="rounded-md border border-gray-200 p-4 space-y-3">
                    @csrf
                    <p class="text-sm font-medium text-gray-900">Import offers as products</p>
                    <p class="text-xs text-gray-500">Creates a product for every offer that is not a product yet. Existing products are never changed. Prices are calculated from the provider cost plus your markup.</p>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs text-gray-500" for="import_markup_type">Markup type</label>
                            <select id="import_markup_type" name="markup_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="percent_markup" @selected(old('markup_type') === 'percent_markup')>Percentage over cost</option>
                                <option value="fixed_markup" @selected(old('markup_type') === 'fixed_markup')>Fixed amount over cost (USD)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500" for="import_markup_value">Markup value</label>
                            <input id="import_markup_value" name="markup_value" type="text" inputmode="decimal" required
                                   value="{{ old('markup_value', '20') }}" placeholder="20 (percent) or 0.25 (USD)"
                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                            <x-input-error :messages="$errors->get('markup_value')" class="mt-1" />
                        </div>
                        <label class="flex items-end pb-2 text-sm text-gray-700">
                            <input type="checkbox" name="make_active" value="1" class="mr-2 rounded border-gray-300" checked> Make products active
                        </label>
                    </div>

                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Import offers</button>
                </form>
            @endcan
        @endif
    </div>
@endcan