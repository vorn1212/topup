<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">System health</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="rounded-lg border p-4 {{ $bannerClass }}">
                <div class="font-semibold">{{ $bannerText }}</div>
                <div class="text-sm mt-1">
                    {{ $summary['pass'] }} passed, {{ $summary['warn'] }} warning(s), {{ $summary['fail'] }} failure(s)
                    &middot; environment: <span class="font-mono">{{ $environment }}</span>
                </div>
                <div class="text-sm mt-1">Command line version: <span class="font-mono">php artisan ops:check</span></div>
            </div>

            @foreach ($groups as $group => $items)
                <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                    <div class="px-5 py-3 border-b font-semibold text-gray-800">{{ $group }}</div>
                    <ul class="divide-y">
                        @foreach ($items as $item)
                            <li class="px-5 py-3 flex gap-3 items-start">
                                <span class="mt-0.5 shrink-0 rounded px-2 py-0.5 text-xs font-semibold {{ $badgeClasses[$item['level']] }}">{{ strtoupper($item['level']) }}</span>
                                <div class="text-sm">
                                    <div class="font-medium text-gray-900">{{ $item['name'] }}</div>
                                    <div class="text-gray-600 break-words">{{ $item['message'] }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <p class="text-xs text-gray-500">Checks are read-only. Secret values (keys, passwords, the payment link) are never shown here.</p>
        </div>
    </div>
</x-app-layout>