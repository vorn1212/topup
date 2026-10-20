@php
    $editing = $game !== null;
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $editing ? 'Edit game: '.$game->name : 'New game' }}
        </h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-flash />

            <form method="POST" enctype="multipart/form-data"
                  action="{{ $editing ? route('admin.games.update', $game) : route('admin.games.store') }}"
                  class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                @csrf
                @if ($editing) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="name">Name</label>
                        <input id="name" name="name" type="text" required class="{{ $input }}" value="{{ old('name', $game?->name) }}" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="slug">URL slug <span class="text-gray-400">(blank = automatic)</span></label>
                        <input id="slug" name="slug" type="text" class="{{ $input }}" value="{{ old('slug', $game?->slug) }}" />
                        <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="description">Description</label>
                    <textarea id="description" name="description" rows="3" class="{{ $input }}">{{ old('description', $game?->description) }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="status">Status</label>
                        <select id="status" name="status" class="{{ $input }}">
                            @foreach (\App\Enums\CatalogStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(old('status', $game?->status?->value ?? 'active') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="sort_order">Sort order</label>
                        <input id="sort_order" name="sort_order" type="number" min="0" class="{{ $input }}" value="{{ old('sort_order', $game?->sort_order ?? 0) }}" />
                        <x-input-error :messages="$errors->get('sort_order')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="provider_category_id">Provider category ID</label>
                        <input id="provider_category_id" name="provider_category_id" type="text" class="{{ $input }}" value="{{ old('provider_category_id', $game?->provider_category_id) }}" />
                        <x-input-error :messages="$errors->get('provider_category_id')" class="mt-1" />
                    </div>

                    <div>
                    <label class="block text-sm font-medium text-gray-700" for="validation_category_id">Player check category <span class="text-gray-400">(optional)</span></label>
                    @if (! empty($validatable))
                        <select id="validation_category_id" name="validation_category_id" class="{{ $input }}">
                            <option value="">None (no player check)</option>
                            @foreach ($validatable as $item)
                                <option value="{{ $item['category_id'] }}" @selected(old('validation_category_id', $game?->validation_category_id) === $item['category_id'])>
                                    {{ $item['name'] }} ({{ $item['category_id'] }})
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input id="validation_category_id" name="validation_category_id" type="text" class="{{ $input }}"
                               value="{{ old('validation_category_id', $game?->validation_category_id) }}" />
                    @endif
                    <x-input-error :messages="$errors->get('validation_category_id')" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-500">Pick the provider's matching game to let customers check their Player ID before paying.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="meta_title">SEO title</label>
                        <input id="meta_title" name="meta_title" type="text" class="{{ $input }}" value="{{ old('meta_title', $game?->meta_title) }}" />
                        <x-input-error :messages="$errors->get('meta_title')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="meta_description">SEO description</label>
                        <input id="meta_description" name="meta_description" type="text" class="{{ $input }}" value="{{ old('meta_description', $game?->meta_description) }}" />
                        <x-input-error :messages="$errors->get('meta_description')" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="logo">Logo (JPG, PNG or WEBP, max 2 MB, at least 64x64)</label>
                        @if ($editing && $game->logoUrl())
                            <img src="{{ $game->logoUrl() }}" alt="" class="my-2 h-16 w-16 rounded object-cover" />
                            <label class="text-xs text-gray-600"><input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300"> Remove current logo</label>
                        @endif
                        <input id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm" />
                        <x-input-error :messages="$errors->get('logo')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="banner">Banner (JPG, PNG or WEBP, max 2 MB, at least 300x100)</label>
                        @if ($editing && $game->bannerUrl())
                            <img src="{{ $game->bannerUrl() }}" alt="" class="my-2 h-16 w-40 rounded object-cover" />
                            <label class="text-xs text-gray-600"><input type="checkbox" name="remove_banner" value="1" class="rounded border-gray-300"> Remove current banner</label>
                        @endif
                        <input id="banner" name="banner" type="file" accept=".jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm" />
                        <x-input-error :messages="$errors->get('banner')" class="mt-1" />
                    </div>
                </div>

                @if ($editing)
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="package_icon">Default package icon <span class="text-gray-400">(optional)</span></label>
                        <div class="my-2 flex items-center gap-3">
                            <x-package-icon :url="$game->packageIconUrl()" class="h-14 w-14 rounded bg-gray-100 p-1" />
                            <p class="text-xs text-gray-500">Shown on every package of this game that has no icon of its own. Without it, the built-in diamond is shown.</p>
                        </div>
                        @if ($game->packageIconUrl())
                            <label class="text-xs text-gray-600"><input type="checkbox" name="remove_package_icon" value="1" class="rounded border-gray-300"> Remove the default icon</label>
                        @endif
                        <input id="package_icon" name="package_icon" type="file" accept=".jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm" />
                        <p class="mt-1 text-xs text-gray-500">JPG, PNG or WEBP, max 1 MB. A PNG with a transparent background looks best.</p>
                        <x-input-error :messages="$errors->get('package_icon')" class="mt-1" />
                    </div>
                @endif
                <div class="flex items-center gap-3">
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Save</button>
                    <a href="{{ route('admin.games.index') }}" class="text-sm text-gray-600 hover:underline">Cancel</a>
                </div>
            </form>

            @if ($editing)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div>
                        <h3 class="font-semibold text-gray-900">Player fields</h3>
                        <p class="text-sm text-gray-500">What the customer must enter for this game (Player ID, Server ID, UID...). Rules are optional, for example <code>digits_between:5,15</code>. For a select field, list options separated by commas.</p>
                    </div>

                    @if ($errors->field->any())
                        <div class="rounded-md bg-red-50 p-3 text-sm text-red-700">
                            <ul class="list-disc pl-5">
                                @foreach ($errors->field->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @foreach ($game->fields as $field)
                        @include('admin.games._field-form', ['game' => $game, 'field' => $field])
                    @endforeach

                    <div class="border-t pt-4">
                        <p class="mb-2 text-sm font-medium text-gray-700">Add a field</p>
                        @include('admin.games._field-form', ['game' => $game, 'field' => null])
                    </div>

                    <p class="text-sm">
                        <a href="{{ route('admin.products.create', ['game_id' => $game->id]) }}" class="text-indigo-600 hover:underline">Add a product to this game →</a>
                    </p>
                       @include('admin.games._provider-panel')
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
                