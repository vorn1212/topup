@inject('site', 'App\Services\SiteSettings')

<x-public-layout>
    <section class="relative overflow-hidden">
        <div class="mx-auto grid max-w-7xl items-center gap-6 px-4 py-12 sm:px-6 md:grid-cols-2 lg:px-8 lg:py-16">
            <div class="text-center md:text-left">
                <h1 class="text-3xl font-bold leading-tight text-white sm:text-5xl">{{ __('Game top-up, delivered in minutes') }}</h1>
                <p class="mt-4 text-blue-100">{{ __('Deposit once, then top up your favourite games straight from your wallet.') }}</p>
                <div class="mt-7 flex flex-wrap items-center justify-center gap-3 md:justify-start">
                    <a href="{{ route('games.index') }}"
                       class="inline-block rounded-md bg-sky-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-sky-500/30 hover:bg-sky-400">{{ __('Browse games') }}</a>
                    <a href="{{ route('contact') }}"
                       class="inline-block rounded-md border border-blue-300/50 px-6 py-3 text-sm font-semibold text-blue-100 hover:bg-white/10">{{ __('Contact Us') }}</a>
                </div>
            </div>

            <div class="mx-auto h-64 w-64 sm:h-80 sm:w-80 md:h-96 md:w-96">
                <div class="gg-float h-full w-full">
                    @if ($site->bannerUrl())
                        <img src="{{ $site->bannerUrl() }}" alt="{{ config('app.name') }}" class="h-full w-full object-contain drop-shadow-[0_0_30px_rgba(56,189,248,.45)]">
                    @else
                        @include('site._banner-character')
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-5 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-white">{{ __('Popular games') }}</h2>
            <a href="{{ route('games.index') }}" class="text-sm text-sky-300 hover:underline">{{ __('View all') }}</a>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($games as $game)
                @include('site._game-card', ['game' => $game])
            @empty
                <p class="col-span-full text-sm text-blue-200">{{ __('Games are coming soon.') }}</p>
            @endforelse
        </div>
    </section>
</x-public-layout>
