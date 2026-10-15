@php
    $editing = $product !== null;
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm';
    $cost = old('provider_cost', $editing ? \App\Support\Money::normalize((string) $product->provider_cost) : '0.0000');
    $type = old('markup_type', $editing ? $product->markup_type->value : 'fixed_markup');
    $value = old('markup_value', $editing ? \App\Support\Money::normalize((string) $product->markup_value) : '0.0000');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $editing ? 'Product: '.$product->name : 'New product' }}
        </h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            {{-- Price summary and quick buttons --}}
            @if ($editing)
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 text-sm">
                        <div><p class="text-gray-500">Game</p><p class="font-medium">{{ $product->game->name }}</p></div>
                        <div><p class="text-gray-500">Provider cost</p><p class="font-medium">{{ \App\Support\Money::display((string) $product->provider_cost, $product->currency) }}</p></div>
                        <div><p class="text-gray-500">Selling price</p><p class="text-lg font-bold text-indigo-600">{{ \App\Support\Money::display((string) $product->selling_price, $product->currency) }}</p></div>
                        <div><p class="text-gray-500">Markup / profit</p>
                            <p class="font-medium {{ $product->isBelowCost() ? 'text-red-600' : 'text-green-700' }}">{{ \App\Support\Money::display($product->profit(), $product->currency) }}</p></div>
                        <div><p class="text-gray-500">Status</p><x-status-badge :status="$product->status->value" /></div>
                    </div>

                    @can('pricing.manage')
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach (\App\Http\Requests\AdjustPriceRequest::STEPS as $delta)
                                <form method="POST" action="{{ route('admin.products.price.adjust', $product) }}">
                                    @csrf
                                    <input type="hidden" name="delta" value="{{ $delta }}" />
                                    <button class="rounded border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50">
                                        {{ str_starts_with($delta, '-') ? '− $'.substr($delta, 1) : '+ $'.$delta }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    @endcan
                </div>
            @endif

            {{-- Details --}}
            <form method="POST"
                  action="{{ $editing ? route('admin.products.update', $product) : route('admin.products.store') }}"
                  class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4"
                  @if (! $editing)
                      x-data='{ cost: @js($cost), type: @js($type), value: @js($value),
                                get selling() { const c = parseFloat(this.cost) || 0, v = parseFloat(this.value) || 0;
                                  if (this.type === "fixed_price") return v; if (this.type === "fixed_markup") return c + v; return c * (1 + v / 100); } }'
                  @endif>
                @csrf
                @if ($editing) @method('PATCH') @endif

                <h3 class="font-semibold text-gray-900">Details</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @unless ($editing)
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="game_id">Game</label>
                            <select id="game_id" name="game_id" required class="{{ $input }}">
                                <option value="">Choose a game</option>
                                @foreach ($games as $game)
                                    <option value="{{ $game->id }}" @selected((int) old('game_id', $selectedGameId) === $game->id)>{{ $game->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('game_id')" class="mt-1" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="sku">SKU <span class="text-gray-400">(blank = automatic)</span></label>
                            <input id="sku" name="sku" type="text" class="{{ $input }}" value="{{ old('sku') }}" />
                            <x-input-error :messages="$errors->get('sku')" class="mt-1" />
                        </div>
                    @else
                        <div>
                            <label class="block text-sm font-medium text-gray-700">SKU</label>
                            <p class="mt-2 text-sm text-gray-900">{{ $product->sku }}</p>
                        </div>
                    @endunless

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="name">Name</label>
                        <input id="name" name="name" type="text" required class="{{ $input }}" value="{{ old('name', $product?->name) }}" placeholder="86 Diamonds" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="provider_offer_id">Provider offer ID</label>
                        <input id="provider_offer_id" name="provider_offer_id" type="text" class="{{ $input }}" value="{{ old('provider_offer_id', $product?->provider_offer_id) }}" />
                        <x-input-error :messages="$errors->get('provider_offer_id')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="description">Description</label>
                    <textarea id="description" name="description" rows="2" class="{{ $input }}">{{ old('description', $product?->description) }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="status">Status</label>
                        <select id="status" name="status" class="{{ $input }}">
                            @foreach (\App\Enums\CatalogStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(old('status', $product?->status?->value ?? 'active') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="sort_order">Sort order</label>
                        <input id="sort_order" name="sort_order" type="number" min="0" class="{{ $input }}" value="{{ old('sort_order', $product?->sort_order ?? 0) }}" />
                        <x-input-error :messages="$errors->get('sort_order')" class="mt-1" />
                    </div>
                    <div class="flex items-end pb-2">
                        <label class="text-sm text-gray-700">
                            <input type="checkbox" name="is_available" value="1" class="rounded border-gray-300"
                                   @checked(old('is_available', $product?->is_available ?? true))> Available at provider
                        </label>
                    </div>
                </div>

                {{-- Price block for NEW products only --}}
                @unless ($editing)
                    <div class="border-t pt-4 space-y-4">
                        <h3 class="font-semibold text-gray-900">Price</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="provider_cost">Provider cost (USD)</label>
                                <input id="provider_cost" name="provider_cost" type="text" inputmode="decimal" x-model="cost" class="{{ $input }}" />
                                <x-input-error :messages="$errors->get('provider_cost')" class="mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="markup_type">Pricing mode</label>
                                <select id="markup_type" name="markup_type" x-model="type" class="{{ $input }}">
                                    @foreach (\App\Enums\MarkupType::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="markup_value">Value (amount, % or price)</label>
                                <input id="markup_value" name="markup_value" type="text" inputmode="decimal" x-model="value" class="{{ $input }}" />
                                <x-input-error :messages="$errors->get('markup_value')" class="mt-1" />
                            </div>
                        </div>
                        <p class="text-sm text-gray-600">
                            Estimated selling price: <strong x-text="'$' + selling.toFixed(2)"></strong>
                            · estimated profit: <strong x-text="'$' + (selling - (parseFloat(cost) || 0)).toFixed(2)"></strong>
                            <span class="text-gray-400">(estimate only; the server calculates the final price)</span>
                        </p>
                        @can('pricing.allow_loss')
                            <label class="text-sm text-gray-700">
                                <input type="checkbox" name="confirm_loss" value="1" class="rounded border-gray-300"> Allow a selling price below the provider cost
                            </label>
                        @endcan
                    </div>
                @endunless

                <div class="flex items-center gap-3">
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ $editing ? 'Save details' : 'Create product' }}</button>
                    <a href="{{ route('admin.products.index') }}" class="text-sm text-gray-600 hover:underline">Back to products</a>
                </div>
            </form>

            {{-- Edit price (existing products) --}}
            @if ($editing)
                @can('pricing.manage')
                    <form method="POST" action="{{ route('admin.products.price.update', $product) }}"
                          class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4"
                          x-data='{ cost: @js($cost), type: @js($type), value: @js($value),
                                    get selling() { const c = parseFloat(this.cost) || 0, v = parseFloat(this.value) || 0;
                                      if (this.type === "fixed_price") return v; if (this.type === "fixed_markup") return c + v; return c * (1 + v / 100); } }'>
                        @csrf @method('PATCH')

                        <h3 class="font-semibold text-gray-900">Edit price</h3>

                        @if ($errors->has('price'))
                            <div class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('price') }}</div>
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="p_cost">Provider cost (USD)</label>
                                <input id="p_cost" name="provider_cost" type="text" inputmode="decimal" x-model="cost" class="{{ $input }}" />
                                <x-input-error :messages="$errors->get('provider_cost')" class="mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="p_type">Pricing mode</label>
                                <select id="p_type" name="markup_type" x-model="type" class="{{ $input }}">
                                    @foreach (\App\Enums\MarkupType::cases() as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="p_value">Value (amount, % or price)</label>
                                <input id="p_value" name="markup_value" type="text" inputmode="decimal" x-model="value" class="{{ $input }}" />
                                <x-input-error :messages="$errors->get('markup_value')" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="p_reason">Reason for the change</label>
                            <input id="p_reason" name="reason" type="text" required class="{{ $input }}" value="{{ old('reason') }}" placeholder="FazerCards cost increase" />
                            <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                        </div>

                        <p class="text-sm text-gray-600">
                            Estimated selling price: <strong x-text="'$' + selling.toFixed(2)"></strong>
                            · estimated profit: <strong x-text="'$' + (selling - (parseFloat(cost) || 0)).toFixed(2)"></strong>
                            <span class="text-gray-400">(estimate only; the server calculates the final price)</span>
                        </p>

                        @can('pricing.allow_loss')
                            <label class="text-sm text-gray-700">
                                <input type="checkbox" name="confirm_loss" value="1" class="rounded border-gray-300"> Allow a selling price below the provider cost
                            </label>
                        @endcan

                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Save price</button>
                    </form>
                @endcan

                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-gray-900">Recent price changes</h3>
                        <a href="{{ route('admin.products.history', $product) }}" class="text-sm text-indigo-600 hover:underline">Full history →</a>
                    </div>
                    @forelse ($histories as $history)
                        <div class="flex items-center justify-between border-t first:border-t-0 py-2 text-sm">
                            <div>
                                <p class="text-gray-900">
                                    {{ \App\Support\Money::display((string) $history->old_price) }} → <strong>{{ \App\Support\Money::display((string) $history->new_price) }}</strong>
                                </p>
                                <p class="text-xs text-gray-500">{{ $history->reason }}</p>
                            </div>
                            <p class="text-xs text-gray-500 text-right">
                                {{ $history->admin?->email ?? 'System' }}<br>{{ $history->created_at?->format('M d, Y H:i') }}
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No changes recorded.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-app-layout>