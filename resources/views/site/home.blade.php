@inject('site', 'App\Services\SiteSettings')

<x-public-layout>
    {{-- The main page heading stays for search engines and screen readers, but is not drawn. --}}
    <h1 class="sr-only">{{ __('Game top-up, delivered in minutes') }}</h1>

    @include('site._banner-slider', ['slides' => $site->slides()])

    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-5 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-white">{{ __('Popular games') }}</h2>
            <a href="{{ route('games.index') }}" class="text-sm text-sky-300 hover:underline">{{ __('View all') }}</a>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3">
            @forelse ($games as $game)
                @include('site._game-card', ['game' => $game])
            @empty
                <p class="col-span-full text-sm text-blue-200">{{ __('Games are coming soon.') }}</p>
            @endforelse
        </div>
    </section>
</x-public-layout>
