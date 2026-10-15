<a href="{{ route('games.show', $game->slug) }}"
   class="group block overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 transition hover:shadow-md">
    <div class="aspect-video bg-indigo-50">
        @if ($game->bannerUrl())
            <img src="{{ $game->bannerUrl() }}" alt="{{ $game->name }}" loading="lazy" class="h-full w-full object-cover">
        @elseif ($game->logoUrl())
            <div class="flex h-full items-center justify-center">
                <img src="{{ $game->logoUrl() }}" alt="{{ $game->name }}" loading="lazy" class="h-20 w-20 rounded-xl object-cover">
            </div>
        @else
            <div class="flex h-full items-center justify-center text-4xl font-bold text-indigo-300">{{ mb_substr($game->name, 0, 1) }}</div>
        @endif
    </div>
    <div class="p-4">
        <h3 class="font-semibold text-gray-900 group-hover:text-indigo-600">{{ $game->name }}</h3>
        @if ($game->min_price !== null)
            <p class="mt-1 text-sm text-gray-500">{{ __('From') }} <span class="font-medium text-gray-900">{{ \App\Support\Money::display((string) $game->min_price) }}</span></p>
        @else
            <p class="mt-1 text-sm text-gray-400">{{ __('Coming soon') }}</p>
        @endif
    </div>
</a>