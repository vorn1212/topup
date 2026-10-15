<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Audit log</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            <form method="GET" action="{{ route('admin.audit.index') }}" class="flex flex-wrap items-end gap-3 bg-white shadow-sm sm:rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500">Search</label>
                    <input type="text" name="q" value="{{ $q }}" maxlength="100" placeholder="Action, staff e-mail, IP" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Action</label>
                    <select name="action" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach ($actions as $name)
                            <option value="{{ $name }}" @selected($action === $name)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Done by</label>
                    <select name="actor_type" class="rounded-md border-gray-300 text-sm">
                        <option value="">Anyone</option>
                        @foreach ($actorTypes as $type)
                            <option value="{{ $type }}" @selected($actorType === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">From</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">To</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-md border-gray-300 text-sm">
                </div>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Filter</button>
                <a href="{{ route('admin.audit.index') }}" class="text-sm text-gray-500 hover:underline">Reset</a>
            </form>

            <div class="bg-white shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="p-3">When</th><th>Who</th><th>Action</th><th>About</th><th>IP</th><th class="p-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr class="border-t">
                                <td class="p-3 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                                <td>
                                    @if ($log->actor)
                                        {{ $log->actor->email }}
                                    @else
                                        <span class="text-gray-500">{{ $log->actor_type === 'system' ? 'System' : 'Unknown' }}</span>
                                    @endif
                                </td>
                                <td class="font-mono text-xs">{{ $log->action }}</td>
                                <td class="text-xs text-gray-600">
                                    @if ($log->subject_type)
                                        {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-xs text-gray-600">{{ $log->ip ?? '-' }}</td>
                                <td class="p-3 text-right"><a href="{{ route('admin.audit.show', $log->id) }}" class="text-indigo-600 hover:underline">Details</a></td>
                            </tr>
                        @empty
                            <tr class="border-t"><td colspan="6" class="p-4 text-gray-500">No entries found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $logs->links() }}
        </div>
    </div>
</x-app-layout>