<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Price history: {{ $product->name }}</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">When</th><th>Admin</th><th>Selling price</th><th>Cost</th><th>Markup</th><th>Mode</th><th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($histories as $history)
                            <tr class="border-t align-top">
                                <td class="p-3 whitespace-nowrap">{{ $history->created_at?->format('M d, Y H:i') }}</td>
                                <td>{{ $history->admin?->email ?? 'System' }}</td>
                                <td class="whitespace-nowrap">
                                    {{ \App\Support\Money::display((string) $history->old_price) }} →
                                    <strong>{{ \App\Support\Money::display((string) $history->new_price) }}</strong>
                                </td>
                                <td class="whitespace-nowrap">
                                    {{ \App\Support\Money::display((string) $history->old_cost) }} → {{ \App\Support\Money::display((string) $history->new_cost) }}
                                </td>
                                <td class="whitespace-nowrap">
                                    {{ \App\Support\Money::display((string) $history->old_markup) }} → {{ \App\Support\Money::display((string) $history->new_markup) }}
                                </td>
                                <td class="text-xs text-gray-500">{{ str_replace('_', ' ', $history->new_markup_type) }}</td>
                                <td>
                                    {{ $history->reason }}
                                    @if ($history->batch_id)
                                        <span class="block text-xs text-gray-400">batch {{ \Illuminate\Support\Str::limit($history->batch_id, 8, '') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-6 text-center text-gray-500">No price changes yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $histories->links() }}

            <a href="{{ route('admin.products.edit', $product) }}" class="text-sm text-indigo-600 hover:underline">← Back to product</a>
        </div>
    </div>
</x-app-layout>