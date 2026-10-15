<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Audit entry #{{ $log->id }}</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('admin.audit.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to the list</a>

            <div class="bg-white shadow-sm sm:rounded-lg p-5">
                <dl class="space-y-2 text-sm">
                    <div class="flex gap-2"><dt class="w-32 shrink-0 text-gray-500">When</dt><dd>{{ $log->created_at->format('Y-m-d H:i:s') }}</dd></div>
                    <div class="flex gap-2"><dt class="w-32 shrink-0 text-gray-500">Action</dt><dd class="font-mono">{{ $log->action }}</dd></div>
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 text-gray-500">Done by</dt>
                        <dd>
                            @if ($log->actor)
                                {{ $log->actor->name }} ({{ $log->actor->email }}) - {{ $log->actor_type }}
                            @else
                                {{ $log->actor_type === 'system' ? 'System (scheduler, queue or command)' : 'Unknown' }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 text-gray-500">About</dt>
                        <dd>
                            @if ($log->subject_type)
                                @if ($subjectUrl)
                                    <a href="{{ $subjectUrl }}" class="text-indigo-600 hover:underline">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</a>
                                @else
                                    {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                @endif
                            @else
                                -
                            @endif
                        </dd>
                    </div>
                    <div class="flex gap-2"><dt class="w-32 shrink-0 text-gray-500">IP address</dt><dd>{{ $log->ip ?? '-' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-32 shrink-0 text-gray-500">Browser</dt><dd class="break-all text-xs text-gray-600">{{ $log->user_agent ?? '-' }}</dd></div>
                </dl>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-900">Before</h3>
                    @if ($old)
                        <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-3 text-xs">{{ $old }}</pre>
                    @else
                        <p class="mt-2 text-sm text-gray-500">Nothing recorded.</p>
                    @endif
                </div>
                <div class="bg-white shadow-sm sm:rounded-lg p-5">
                    <h3 class="font-semibold text-gray-900">After</h3>
                    @if ($new)
                        <pre class="mt-2 overflow-x-auto rounded bg-gray-50 p-3 text-xs">{{ $new }}</pre>
                    @else
                        <p class="mt-2 text-sm text-gray-500">Nothing recorded.</p>
                    @endif
                </div>
            </div>

            <p class="text-xs text-gray-500">Audit entries can never be edited or deleted. Passwords, tokens and API keys are replaced by [REDACTED] before they are stored.</p>
        </div>
    </div>
</x-app-layout>