<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Admin') }}</h2>
    </x-slot>
    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-sm text-gray-500">Total users</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $totalUsers }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-sm text-gray-500">Active users</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $activeUsers }}</p>
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <p class="text-sm text-gray-500">Suspended users</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $suspendedUsers }}</p>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <h3 class="font-semibold text-gray-900 mb-3">Staff</h3>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2">Name</th><th>Email</th><th>Role</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($staff as $member)
                            <tr class="border-t">
                                <td class="py-2">{{ $member->name }}</td>
                                <td>{{ $member->email }}</td>
                                <td>{{ $member->role->label() }}</td>
                                <td><x-status-badge :status="$member->status->value" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-gray-500">Full admin panel arrives in Phase 9.</p>
        </div>
    </div>
</x-app-layout>