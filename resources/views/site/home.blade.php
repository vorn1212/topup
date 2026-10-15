<x-public-layout>
    <section class="bg-indigo-600">
        <div class="mx-auto max-w-7xl px-4 py-14 text-center sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-white sm:text-4xl">{{ __('Game top-up, delivered in minutes') }}</h1>
            <p class="mt-3 text-indigo-100">{{ __('Deposit once, then top up your favourite games straight from your wallet.') }}</p>
            <a href="{{ route('games.index') }}"
               class="mt-6 inline-block rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-indigo-700 shadow hover:bg-indigo-50">{{ __('Browse games') }}</a>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-5 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-900">{{ __('Popular games') }}</h2>
            <a href="{{ route('games.index') }}" class="text-sm text-indigo-600 hover:underline">{{ __('View all') }}</a>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($games as $game)
                @include('site._game-card', ['game' => $game])
            @empty
                <p class="col-span-full text-sm text-gray-500">{{ __('Games are coming soon.') }}</p>
            @endforelse
        </div>
    </section>
</x-public-layout>