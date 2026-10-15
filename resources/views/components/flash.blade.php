@php
    // Messages are written in English in the code; they are translated here. One of them carries a price.
    $flash = function (?string $message): ?string {
        if ($message === null || $message === '') {
            return null;
        }

        if (preg_match('/^The price changed to (.+)\. Please check it and confirm again\.$/s', $message, $m)) {
            return __('The price changed to :price. Please check it and confirm again.', ['price' => $m[1]]);
        }

        return __($message);
    };
@endphp
@if (session('status'))
    <div class="rounded-md bg-green-50 p-4 text-sm text-green-700">{{ $flash(session('status')) }}</div>
@endif
@if (session('error'))
    <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">{{ $flash(session('error')) }}</div>
@endif