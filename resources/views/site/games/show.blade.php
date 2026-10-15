<x-public-layout :title="$game->seoTitle()"
                 :description="$game->seoDescription()"
                 :image="$game->seoImage()">
    @if ($game->bannerUrl())
        <div class="h-40 w-full bg-indigo-50 sm:h-64">
            <img src="{{ $game->bannerUrl() }}" alt="{{ $game->name }}" class="h-full w-full object-cover">
        </div>
    @endif

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex items-center gap-4">
            @if ($game->logoUrl())
                <img src="{{ $game->logoUrl() }}" alt="" class="h-16 w-16 rounded-xl object-cover ring-1 ring-gray-200">
            @endif
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __(':game Top-Up', ['game' => $game->name]) }}</h1>
                <nav class="text-xs text-gray-500">
                    <a href="{{ route('games.index') }}" class="hover:underline">{{ __('Games') }}</a> / {{ $game->name }}
                </nav>
            </div>
        </div>

        @if (filled($game->description))
            <p class="mt-4 whitespace-pre-line text-sm text-gray-700">{{ $game->description }}</p>
        @endif

        <h2 class="mb-3 mt-8 text-lg font-semibold text-gray-900">{{ __('Choose a package') }}</h2>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @forelse ($products as $product)
                <div class="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <div>
                        <p class="font-medium text-gray-900">{{ $product->name }}</p>
                        @if (filled($product->description))
                            <p class="text-xs text-gray-500">{{ $product->description }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <p class="font-semibold text-indigo-600">{{ \App\Support\Money::display((string) $product->selling_price, $product->currency) }}</p>
                        @if ($product->is_available)
                            <a href="{{ route('games.topup', [$game->slug, 'product' => $product->id]) }}"
                               class="mt-1 inline-block rounded bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-500">{{ __('Top up') }}</a>
                        @else
                            <span class="mt-1 inline-block text-xs text-gray-400">{{ __('Unavailable') }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="col-span-full text-sm text-gray-500">{{ __('Packages are coming soon.') }}</p>
            @endforelse
        </div>
    </div>
</x-public-layout>