<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Notifications') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}" class="text-right">
                    @csrf
                    <button class="text-sm text-indigo-600 hover:underline">{{ __('Mark all as read') }} ({{ $unread }})</button>
                </form>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg divide-y">
                @forelse ($notifications as $notification)
                    @php
                        $data = (array) $notification->data;
                        $url = (string) ($data['url'] ?? '');
                        $safeUrl = str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : null;

                        // Notifications are stored as finished English sentences. The three that customers
                        // receive are recognised here and shown in the current language.
                        $title = __((string) ($data['title'] ?? 'Notification'));
                        $body = (string) ($data['body'] ?? '');

                        if (preg_match('/^(.+) was added to your wallet \(deposit (.+)\)\.$/s', $body, $m)) {
                            $body = __(':amount was added to your wallet (deposit :number).', ['amount' => $m[1], 'number' => $m[2]]);
                        } elseif (preg_match('/^(.+) - (.+) was delivered \(order (.+)\)\.$/s', $body, $m)) {
                            $body = __(':game - :product was delivered (order :number).', ['game' => $m[1], 'product' => $m[2], 'number' => $m[3]]);
                        } elseif (preg_match('/^Order (\S+) could not be completed\. (.+) was returned to your wallet\.$/s', $body, $m)) {
                            $body = __('Order :number could not be completed. :amount was returned to your wallet.', ['number' => $m[1], 'amount' => $m[2]]);
                        } else {
                            $body = __($body);
                        }
                    @endphp
                    <div class="p-4 {{ $notification->read_at ? '' : 'bg-indigo-50' }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium text-gray-900">{{ $title }}</p>
                                <p class="mt-0.5 text-sm text-gray-600">{{ $body }}</p>
                                @if ($safeUrl)
                                    <a href="{{ $safeUrl }}" class="mt-1 inline-block text-sm text-indigo-600 hover:underline">{{ __('Open') }}</a>
                                @endif
                            </div>
                            <p class="shrink-0 text-xs text-gray-500">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-gray-500">{{ __('You have no notifications yet.') }}</p>
                @endforelse
            </div>

            {{ $notifications->links() }}
        </div>
    </div>
</x-app-layout>