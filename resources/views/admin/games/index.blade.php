<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Games</h2>
            <a href="{{ route('admin.games.create') }}"
               class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">New game</a>
        </div>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <form method="GET" class="flex gap-2">
                <input type="text" name="q" value="{{ $search }}" placeholder="Search name or slug"
                       class="rounded-md border-gray-300 text-sm shadow-sm w-64" />
                <button class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm hover:bg-gray-50">Search</button>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Game</th><th>Slug</th><th>Products</th><th>Sort</th><th>Status</th><th class="text-right p-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($games as $game)
                            <tr class="border-t">
                                <td class="p-3">
                                    <div class="flex items-center gap-3">
                                        @if ($game->logoUrl())
                                            <img src="{{ $game->logoUrl() }}" alt="" class="h-9 w-9 rounded object-cover" />
                                        @else
                                            <span class="flex h-9 w-9 items-center justify-center rounded bg-indigo-100 font-semibold text-indigo-700">{{ mb_substr($game->name, 0, 1) }}</span>
                                        @endif
                                        <span class="font-medium text-gray-900">{{ $game->name }}</span>
                                    </div>
                                </td>
                                <td class="text-gray-500">{{ $game->slug }}</td>
                                <td>{{ $game->products_count }}</td>
                                <td>{{ $game->sort_order }}</td>
                                <td><x-status-badge :status="$game->status->value" /></td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.games.edit', $game) }}" class="text-indigo-600 hover:underline">Edit</a>
                                    <form method="POST" action="{{ route('admin.games.toggle', $game) }}" class="inline">
                                        @csrf @method('PATCH')
                                        <button class="ml-3 text-gray-600 hover:underline">{{ $game->status->value === 'active' ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.games.destroy', $game) }}" class="inline"
                                          onsubmit="return confirm('Delete this game? This cannot be undone.')">
                                        @csrf @method('DELETE')
                                        <button class="ml-3 text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-6 text-center text-gray-500">No games yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $games->links() }}
        </div>
    </div>
</x-app-layout>