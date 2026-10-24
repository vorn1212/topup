<x-public-layout :title="__('Pay by QR')" :description="__('Scan the QR code with your banking app to pay and start your top-up.')" :noindex="true">
    <style>
        .qr-wrap { min-height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .6rem; padding: max(env(safe-area-inset-top), .75rem) .75rem max(env(safe-area-inset-bottom), .75rem); text-align: center; }
        .qr-img { display: block; width: auto; height: 50vh; max-width: 92vw; object-fit: contain; }
        @media (max-width: 639px) { .qr-img { height: 60vh; } }
        @media (orientation: landscape) and (max-height: 520px) { .qr-img { height: 72vh; } }
        @supports (height: 1dvh) {
            .qr-img { height: 50dvh; }
            @media (max-width: 639px) { .qr-img { height: 60dvh; } }
            @media (orientation: landscape) and (max-height: 520px) { .qr-img { height: 72dvh; } }
        }
    </style>

    <div class="mx-auto max-w-md px-4 py-8 space-y-4">
        <div id="pay-box" data-status-url="{{ $statusUrl }}" class="rounded-lg bg-white p-6 text-center shadow-sm">
            <p class="text-sm text-gray-500">{{ __('Amount') }}</p>
            <p class="mb-3 text-3xl font-bold text-indigo-600">{{ \App\Support\Money::format((string) $deposit->amount, $deposit->currency) }}</p>

            <div class="mb-4 rounded-md bg-gray-50 p-3 text-left text-sm text-gray-700">
                <div class="flex justify-between gap-3"><span>{{ __('Game') }}</span><strong class="text-right">{{ $summary['game'] }}</strong></div>
                <div class="flex justify-between gap-3"><span>{{ __('Package') }}</span><strong class="text-right">{{ $summary['package'] }}</strong></div>
                @foreach ($summary['fields'] as $label => $value)
                    <div class="flex justify-between gap-3"><span>{{ $label }}</span><strong class="break-all text-right">{{ $value }}</strong></div>
                @endforeach
            </div>

            <div id="view-wait">
                <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-600"></div>
                <p class="mt-2 text-sm text-indigo-600">{{ __('Preparing your payment QR. This takes a few seconds…') }}</p>
                <p id="queue-note" class="mt-2 hidden text-sm text-indigo-600"></p>
            </div>

            <div id="view-paid" class="hidden">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-3xl text-green-600">✓</div>
                <p class="mt-3 text-lg font-semibold text-gray-900">{{ __('Payment received') }}</p>
                <p id="paid-note" class="mt-1 text-sm text-gray-600">{{ __('Starting your top-up…') }}</p>
                <p id="order-ref" class="mt-2 hidden text-xs text-gray-500"></p>
            </div>

            <div id="view-done" class="hidden">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-3xl text-green-600">✓</div>
                <p class="mt-3 text-lg font-semibold text-gray-900">{{ __('Top-up delivered. Enjoy your game!') }}</p>
            </div>

            <div id="view-lost" class="hidden">
                <p class="text-lg font-semibold text-gray-900">{{ __('We could not complete this top-up') }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ __('Please contact us with this number so we can return your money:') }} <strong>{{ $deposit->deposit_number }}</strong></p>
                <a href="{{ route('contact') }}" class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">{{ __('Contact Us') }}</a>
            </div>

            <div id="view-problem" class="hidden">
                <p class="text-lg font-semibold text-gray-900">{{ __('Payment received, but we could not start your top-up') }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ __('Please contact us with this number so we can fix it:') }} <strong>{{ $deposit->deposit_number }}</strong></p>
                <a href="{{ route('contact') }}" class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">{{ __('Contact Us') }}</a>
            </div>

            <div id="view-expired" class="hidden">
                <p class="text-lg font-semibold text-gray-900">{{ __('This payment code expired') }}</p>
                <p class="text-sm text-gray-600">{{ __('No money was taken. Please go back and try again.') }}</p>
                <a href="{{ route('games.index') }}" class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">{{ __('Try again') }}</a>
            </div>
            <div id="view-failed" class="hidden">
                <p class="text-lg font-semibold text-gray-900">{{ __('We could not start the payment') }}</p>
                <p class="text-sm text-gray-600">{{ __('Please try again in a moment.') }}</p>
                <a href="{{ route('games.index') }}" class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">{{ __('Try again') }}</a>
            </div>
            <div id="view-review" class="hidden">
                <p class="text-lg font-semibold text-gray-900">{{ __('We are checking your payment') }}</p>
                <p class="text-sm text-gray-600">
                    {{ __('If you were charged, contact support with deposit number') }}
                    <strong>{{ $deposit->deposit_number }}</strong>.
                </p>
                <a href="{{ route('contact') }}" class="mt-4 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">{{ __('Contact Us') }}</a>
            </div>
        </div>

        <p class="text-center text-xs text-gray-500">{{ __('Keep this page open. Your top-up starts automatically after payment.') }}</p>
    </div>

    <div id="view-qr" class="fixed inset-0 z-50 hidden overflow-y-auto bg-white">
        <div class="qr-wrap">
            <p class="text-base text-gray-600">
                {{ __('Scan to pay') }}
                <strong class="text-indigo-600">{{ \App\Support\Money::format((string) $deposit->amount, $deposit->currency) }}</strong>
            </p>
            <p class="text-sm text-gray-500">{{ __('Expires in') }} <strong id="countdown" class="text-gray-900">--:--</strong></p>
            <img id="qr-img" class="qr-img" alt="{{ __('ABA KHQR payment code') }}" />
            <p class="text-xs text-gray-500">
                {{ __('Raise your screen brightness if the scan is slow.') }}<br>
                {{ __('Keep this page open. Your top-up starts automatically after payment.') }}
            </p>
            <a href="{{ route('games.index') }}" class="text-xs text-indigo-600 hover:underline">← {{ __('Back') }}</a>
        </div>
    </div>

    <script>
        (function () {
            const t = @js([
                'expired' => __('expired'),
                'queue' => __('High demand: :count customer(s) ahead of you. Please keep this page open.'),
                'order' => __('Order'),
                'status' => __('Status'),
            ]);
            const statusUrl = document.getElementById('pay-box').dataset.statusUrl;
            const names = ['wait', 'qr', 'paid', 'done', 'lost', 'problem', 'expired', 'failed', 'review'];
            const views = {};
            names.forEach(function (n) { views[n] = document.getElementById('view-' + n); });
            const img = document.getElementById('qr-img');
            const countdownEl = document.getElementById('countdown');
            let secondsLeft = null, qrLoaded = false, ticker = null, finished = false;

            function show(name) {
                names.forEach(function (n) { views[n].classList.toggle('hidden', n !== name); });
                document.body.style.overflow = name === 'qr' ? 'hidden' : '';
            }

            function tick() {
                if (secondsLeft === null) return;
                secondsLeft = Math.max(0, secondsLeft - 1);
                countdownEl.textContent = String(Math.floor(secondsLeft / 60)).padStart(2, '0') + ':' + String(secondsLeft % 60).padStart(2, '0');
                if (secondsLeft === 0) { img.style.visibility = 'hidden'; countdownEl.textContent = t.expired; }
            }

            function stop() { finished = true; if (ticker) clearInterval(ticker); }

            async function poll() {
                try {
                    const response = await fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    if (response.ok) { handle((await response.json()).data); }
                } catch (e) { /* network hiccup: try again */ }
                if (!finished) setTimeout(poll, 2000);
            }

            function handle(d) {
                if (d.status === 'paid') {
                    if (d.problem) { show('problem'); stop(); return; }
                    if (d.order) {
                        if (d.order.final) {
                            show(d.order.status === 'completed' ? 'done' : 'lost');
                            stop();
                            return;
                        }
                        var ref = document.getElementById('order-ref');
                        ref.textContent = t.order + ' ' + d.order.number + ' · ' + t.status + ': ' + d.order.label;
                        ref.classList.remove('hidden');
                    }
                    show('paid');
                    return;
                }

                switch (d.stage) {
                    case 'queued':
                    case 'starting':
                        show('wait');
                        var note = document.getElementById('queue-note');
                        if (d.stage === 'queued' && d.queue_position > 1) {
                            note.textContent = t.queue.replace(':count', d.queue_position - 1);
                            note.classList.remove('hidden');
                        } else { note.classList.add('hidden'); }
                        break;
                    case 'qr_ready':
                        if (d.qr_url) {
                            if (!qrLoaded) { img.src = d.qr_url; qrLoaded = true; ticker = setInterval(tick, 1000); }
                            secondsLeft = d.seconds_left;
                            show('qr');
                        }
                        break;
                    case 'paid': show('paid'); break;
                    case 'expired': show('expired'); stop(); break;
                    case 'failed': show('failed'); stop(); break;
                    case 'review': show('review'); stop(); break;
                }
            }

            poll();
        })();
    </script>
</x-public-layout>
