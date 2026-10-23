<x-public-layout :title="__('Game Top-Up')"
                 :description="__('Browse all games and top up diamonds, UC and in-game credits instantly with your wallet balance.')"
                 :canonical="$canonical"
                 :noindex="$search !== ''">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold text-gray-900">{{ __('Games') }}</h1>
            <form method="GET" action="{{ route('games.index') }}" class="flex gap-2">
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search games') }}"
                       class="w-56 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ __('Search') }}</button>
            </form>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3">
            @forelse ($games as $game)
                @include('site._game-card', ['game' => $game])
            @empty
                <p class="col-span-full text-sm text-gray-500">{{ __('No games found.') }}</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $games->links() }}</div>
    </div>
</x-public-layout>