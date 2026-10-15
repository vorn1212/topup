<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Users</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <form method="GET" class="flex flex-wrap items-end gap-3 bg-white shadow-sm sm:rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="q" value="{{ $q }}" placeholder="Name, e-mail or phone" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Role</label>
                    <select name="role" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach (\App\Enums\UserRole::cases() as $case)
                            <option value="{{ $case->value }}" @selected($role === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach (\App\Enums\UserStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Filter</button>
                <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-500 hover:underline">Reset</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">Name</th><th>E-mail</th><th>Role</th><th>Status</th><th>Balance</th><th>Joined</th><th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $row)
                            <tr class="border-t">
                                <td class="p-3 font-medium text-gray-900">{{ $row->name }}</td>
                                <td>{{ $row->email }}</td>
                                <td>{{ $row->role->label() }}</td>
                                <td><x-status-badge :status="$row->status->value" /></td>
                                <td>{{ \App\Support\Money::display((string) ($row->wallet?->balance ?? '0.0000')) }}</td>
                                <td>{{ $row->created_at->format('Y-m-d') }}</td>
                                <td class="p-3 text-right"><a class="text-indigo-600 hover:underline" href="{{ route('admin.users.show', $row) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="7" class="p-4 text-gray-500">No users found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>