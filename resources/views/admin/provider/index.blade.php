<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Provider (FazerCards)</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            {{-- Connection --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Connection</h3>
                    <a href="{{ route('admin.provider.index', ['refresh' => 1]) }}" class="text-sm text-indigo-600 hover:underline">Refresh status</a>
                </div>

                @if ($error)
                    <p class="mt-2 text-sm text-red-700">{{ $error }}</p>
                @elseif ($status)
                    <div class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <div><p class="text-gray-500">Account</p><p class="font-medium">{{ $status['login'] }}</p></div>
                        <div><p class="text-gray-500">Plan</p><p class="font-medium">{{ ucfirst($status['plan']) }}</p></div>
                        <div>
                            <p class="text-gray-500">Subscription</p>
                            <p class="font-medium {{ $status['subscription_active'] ? 'text-green-700' : 'text-red-700' }}">{{ $status['subscription_active'] ? 'Active' : 'Inactive' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Provider balance</p>
                            <p class="text-lg font-bold {{ $lowBalance ? 'text-red-600' : 'text-indigo-600' }}">{{ \App\Support\Money::format($status['balance'], $status['currency']) }}</p>
                        </div>
                    </div>
                    @if ($lowBalance)
                        <p class="mt-3 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">Balance is low. Top it up in the FazerCards panel, or customer orders will fail.</p>
                    @endif
                    @unless ($status['subscription_active'])
                        <p class="mt-3 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">The subscription is inactive. The provider may refuse catalog and order requests.</p>
                    @endunless
                @endif
            </div>

            {{-- Sync --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900">Catalog sync</h3>
                        @if ($lastSync)
                            <p class="text-sm text-gray-600">
                                Last run {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->diffForHumans() }}:
                                {{ $lastSync['categories'] }} categories, {{ $lastSync['offers'] }} offers,
                                {{ $lastSync['price_changes'] }} cost change(s), {{ $lastSync['products_unavailable'] }} product(s) unavailable.
                            </p>
                            @foreach (array_slice($lastSync['errors'] ?? [], 0, 3) as $syncError)
                                <p class="text-xs text-red-600">{{ $syncError }}</p>
                            @endforeach
                        @else
                            <p class="text-sm text-gray-600">Not run yet. Run it once to load the provider's categories.</p>
                        @endif
                        <p class="text-xs text-gray-500">It also runs automatically every 30 minutes.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.provider.sync') }}">
                        @csrf
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Sync now</button>
                    </form>
                </div>
            </div>

            {{-- Categories --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-900">Provider categories</h3>
                    <form method="GET" class="flex gap-2">
                        <input type="text" name="q" value="{{ $search }}" placeholder="Search name or ID"
                               class="w-56 rounded-md border-gray-300 text-sm shadow-sm" />
                        <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">Search</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2">Name</th><th>Category ID</th><th>Offers</th><th>Linked game</th><th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($categories as $category)
                                @php $game = $linked[$category->provider_category_id] ?? null; @endphp
                                <tr class="border-t align-top">
                                    <td class="py-2 font-medium text-gray-900">{{ $category->name }}</td>
                                    <td class="text-gray-500"><code>{{ $category->provider_category_id }}</code></td>
                                    <td>{{ $offerCounts[$category->provider_category_id] ?? '—' }}</td>
                                    <td>
                                        @if ($game)
                                            <a href="{{ route('admin.games.edit', $game) }}" class="text-indigo-600 hover:underline">{{ $game->name }}</a>
                                        @else
                                            <span class="text-gray-400">Not linked</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right">
                                        @unless ($game)
                                            <form method="POST" action="{{ route('admin.provider.categories.create-game', $category) }}" class="inline">
                                                @csrf
                                                <button class="rounded bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-500">Create game</button>
                                            </form>
                                            @if ($linkableGames->isNotEmpty())
                                                <form method="POST" action="{{ route('admin.provider.categories.link', $category) }}" class="mt-1 inline-flex gap-1">
                                                    @csrf
                                                    <select name="game_id" class="rounded-md border-gray-300 py-1 text-xs shadow-sm">
                                                        @foreach ($linkableGames as $candidate)
                                                            <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50">Link</button>
                                                </form>
                                            @endif
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-gray-500">No categories yet. Click "Sync now".</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $categories->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>