@php
    $priceMap = $products->mapWithKeys(fn ($p) => [
        'p'.$p->id => \App\Support\Money::display((string) $p->selling_price, $p->currency),
    ])->all();

    // The exact price the customer is looking at; the server compares it with the real price.
    $rawMap = $products->mapWithKeys(fn ($p) => [
        'p'.$p->id => \App\Support\Money::normalize((string) $p->selling_price),
    ])->all();

    $canOrder = $orderingReady && (! $user || $user->isActive());
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';

    // Every sentence the page scripts can show, translated on the server.
    $t = [
        'notFound' => __('We could not find this player account.'),
        'payConfirm' => __('Pay :price by QR code?'),
        'checking' => __('Checking player…'),
        'tooMany' => __('Too many checks. Please wait a few seconds…'),
        'couldNotVerify' => __('Could not verify the player right now. You can still continue.'),
        'adminDetails' => __('Admin details'),
        'checkOff' => __('Player check is off.'),
        'invalid' => __('✗ Player not found. Please check your ID.'),
        'gameId' => __('Game ID'),
        'userName' => __('User Name'),
        'status' => __('Status'),
        'found' => __('✓ Player found'),
        'region' => __('Region'),
    ];
@endphp

<x-public-layout :title="__('Buy :game top-up online', ['game' => $game->name])"
                 :description="__('Choose your :game package, enter your player details and pay by QR code.', ['game' => $game->name])"
                 :image="$game->seoImage()">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <nav class="mb-4 text-xs text-gray-500">
            <a href="{{ route('games.index') }}" class="hover:underline">{{ __('Games') }}</a> /
            <a href="{{ route('games.show', $game->slug) }}" class="hover:underline">{{ $game->name }}</a> / {{ __('Top up') }}
        </nav>

        <h1 class="mb-6 text-2xl font-bold text-gray-900">{{ __('Top up :game', ['game' => $game->name]) }}</h1>

        <div class="mb-4 space-y-3">
            <x-flash />
            @if (session('insufficient'))
                <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800">
                    {{ __('Please') }} <a href="{{ route('wallet.deposit.create') }}" class="font-semibold underline">{{ __('deposit money') }}</a> {{ __('to your wallet, then place the order again.') }}
                </div>
            @endif
        </div>

        <form id="topup-form" method="POST" action="{{ $orderingReady ? route('checkout.store') : '#' }}"
              class="space-y-6"
              x-data="{ selected: @js($selectedId), prices: @js($priceMap), raw: @js($rawMap), t: @js($t), submitting: false }"
              x-on:submit="if (submitting || ! confirm((window.playerCheck && window.playerCheck.state === 'invalid' ? t.notFound + ' ' : '') + t.payConfirm.replace(':price', selected ? prices['p' + selected] : ''))) { $event.preventDefault() } else { submitting = true }">
            @csrf
            <input type="hidden" name="game" value="{{ $game->slug }}" />
            <input type="hidden" name="request_token" value="{{ $requestToken }}" />
            <input type="hidden" name="expected_price" x-bind:value="selected ? raw['p' + selected] : ''" />

            {{-- Step 1: player information --}}
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="mb-3 font-semibold text-gray-900">1. {{ __('Player information') }}</h2>

                @forelse ($fields as $field)
                    @php $name = $field->provider_field_name; @endphp
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700" for="field_{{ $name }}">
                            {{ __($field->label) }}
                            @if ($field->required)<span class="text-red-500">*</span>@endif
                        </label>

                        @if ($field->type === 'select')
                            <select id="field_{{ $name }}" name="fields[{{ $name }}]" @required($field->required) class="{{ $input }}">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($field->options ?? [] as $option)
                                    <option value="{{ $option }}" @selected(old('fields.'.$name) === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="field_{{ $name }}" name="fields[{{ $name }}]" type="text" autocomplete="off"
                                   inputmode="{{ $field->type === 'number' ? 'numeric' : 'text' }}"
                                   value="{{ old('fields.'.$name) }}" @required($field->required) class="{{ $input }}" />
                        @endif

                        <x-input-error :messages="$errors->get('fields.'.$name)" class="mt-1" />
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No player information is needed for this game.') }}</p>
                @endforelse

                {{-- Automatic player check: the result card appears right under the text boxes --}}
                @if ($validationReady)
                    <div id="player-check" data-enabled="{{ $user ? '1' : '0' }}">
                        @guest
                            <p class="text-xs text-gray-500">{{ __('Log in to verify your player account automatically.') }}</p>
                        @endguest
                        <div id="player-result" class="hidden" role="status" aria-live="polite"></div>
                        <button type="button" id="player-retry" class="mt-1 hidden text-xs text-indigo-600 hover:underline">{{ __('Try again') }}</button>
                    </div>
                @endif
            </div>

            {{-- Summary --}}
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">{{ __('Price') }}</span>
                    <span class="text-lg font-bold text-indigo-600" x-text="selected ? prices['p' + selected] : '—'"></span>
                </div>


                <p class="mt-3 text-xs text-gray-500">{{ __('The final price is confirmed again when you place the order.') }}</p>


                @if ($user && ! $user->isActive())
                    <div class="mt-4 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
                        {{ __('Your account is :status. You cannot place orders. Please contact support.', ['status' => __($user->status->label())]) }}
                    </div>
                @endif

                @if ($user && $user->isActive() && ! $orderingReady)
                    <div class="mt-4 rounded-md bg-blue-50 p-3 text-sm text-blue-800">{{ __('Ordering opens soon.') }}</div>
                @endif

                <button type="submit" x-bind:disabled="{{ $canOrder ? 'submitting' : 'true' }}"
                        class="mt-4 w-full rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('Pay with QR') }}
                </button>
            </div>

            {{-- Step 2: package --}}
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="mb-3 font-semibold text-gray-900">2. {{ __('Choose a package') }}</h2>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @forelse ($products as $product)
                        <label class="flex items-center justify-between rounded-lg border p-3 {{ $product->is_available ? 'cursor-pointer' : 'cursor-not-allowed opacity-50' }}"
                               :class="selected == {{ $product->id }} ? 'border-indigo-600 bg-indigo-50' : 'border-gray-200'">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="product_id" value="{{ $product->id }}" x-model="selected" required
                                       @checked($selectedId === $product->id) @disabled(! $product->is_available) class="text-indigo-600">
                                <x-package-icon :url="$product->iconUrl($game)" class="h-8 w-8 shrink-0" /><span class="text-sm text-gray-900">{{ $product->name }}</span>
                            </span>
                            <span class="text-sm font-semibold text-indigo-600">
                                {{ $product->is_available ? \App\Support\Money::display((string) $product->selling_price, $product->currency) : __('Unavailable') }}
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('Packages are coming soon.') }}</p>
                    @endforelse
                </div>
                <x-input-error :messages="$errors->get('product_id')" class="mt-2" />
            </div>
        </form>
    </div>

    @if ($validationReady)
        <script>
            (function () {
                var holder = document.getElementById('player-check');
                if (!holder || holder.dataset.enabled !== '1') return; // guests: no automatic check

                var form = document.getElementById('topup-form');
                var result = document.getElementById('player-result');
                var retry = document.getElementById('player-retry');
                var token = document.querySelector('meta[name="csrf-token"]').content;
                var url = @js($validateUrl);
                var t = @js($t);

                var cache = {};
                var timer = null;
                var controller = null;
                var sequence = 0;
                var lastKey = null;

                // Read by the pay button: it warns when the account was not found.
                window.playerCheck = { state: 'idle' };

                var styles = {
                    bad: 'mt-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200',
                    wait: 'mt-2 text-sm text-gray-500',
                    warn: 'mt-2 text-sm text-yellow-700'
                };

                var userIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
                    + '<path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM16 11l2 2 4-4"/></svg>';

                // A plain text line (waiting, not found, warnings).
                function show(kind, text) {
                    result.textContent = text; // plain text only
                    result.className = styles[kind];
                }

                // One "Label : value" row. The value is set as text, so a player name can never inject HTML.
                function row(label, value, valueClass) {
                    var line = document.createElement('div');
                    line.className = 'flex flex-wrap gap-x-2 text-sm';

                    var name = document.createElement('span');
                    name.className = 'w-20 flex-none text-gray-500';
                    name.textContent = label + ' :';

                    var text = document.createElement('span');
                    text.className = valueClass;
                    text.textContent = value;

                    line.appendChild(name);
                    line.appendChild(text);

                    return line;
                }

                // The green result card: Game ID (with the server in brackets) and the user name.
                function showCard(out) {
                    result.textContent = '';
                    result.className = 'mt-3 flex items-center gap-4 rounded-xl bg-green-50 p-4 ring-1 ring-green-200';

                    var icon = document.createElement('div');
                    icon.className = 'flex h-12 w-12 flex-none items-center justify-center rounded-full bg-green-100 text-green-600';
                    icon.innerHTML = userIcon; // our own constant markup, never user data

                    var lines = document.createElement('div');
                    lines.className = 'min-w-0 space-y-1';
                    lines.appendChild(row(t.gameId, out.gameId, 'break-all font-semibold text-gray-900'));

                    if (out.name) {
                        lines.appendChild(row(t.userName, out.name, 'break-all font-bold text-green-700'));
                    } else {
                        lines.appendChild(row(t.status, t.found, 'font-semibold text-green-700'));
                    }

                    if (out.region) {
                        lines.appendChild(row(t.region, out.region, 'text-gray-900'));
                    }

                    result.appendChild(icon);
                    result.appendChild(lines);
                }

                function hide() {
                    result.textContent = '';
                    result.className = 'hidden';
                    retry.classList.add('hidden');
                    window.playerCheck.state = 'idle';
                }

                function inputs() {
                    return Array.prototype.slice.call(form.querySelectorAll('[name^="fields["]'));
                }

                // The values to check, or null while a required box is still empty.
                function collect() {
                    var values = {};
                    var list = inputs();

                    for (var i = 0; i < list.length; i++) {
                        var value = list[i].value.trim();
                        if (list[i].required && value === '') return null;
                        if (value !== '') values[list[i].name] = value;
                    }

                    return Object.keys(values).length ? values : null;
                }

                // "292315248 (9531)": the first field, then the others in brackets.
                function gameId(values) {
                    var keys = Object.keys(values);
                    var rest = keys.slice(1).map(function (key) { return values[key]; });

                    return values[keys[0]] + (rest.length ? ' (' + rest.join(', ') + ')' : '');
                }

                function render(out) {
                    if (out.kind === 'card') {
                        showCard(out);
                    } else {
                        show(out.kind, out.text);
                    }

                    window.playerCheck.state = out.state;
                    retry.classList.add('hidden');
                }

                // "detail" is only sent by the server to staff (admins), never to customers.
                function failed(detail) {
                    lastKey = null;
                    show('warn', t.couldNotVerify + (detail ? ' [' + t.adminDetails + ': ' + detail + ']' : ''));
                    window.playerCheck.state = 'error';
                    retry.classList.remove('hidden');
                }

                function handle(response, key, values) {
                    if (response.status === 422) { lastKey = null; hide(); return; } // incomplete ID: stay quiet

                    if (response.status === 429) {
                        lastKey = null;
                        show('wait', t.tooMany);
                        window.playerCheck.state = 'error';
                        retry.classList.remove('hidden');
                        return;
                    }

                    if (!response.ok || !response.json.data) {
                        failed(response.json && response.json.errors ? response.json.errors.detail : null);
                        return;
                    }

                    var data = response.json.data;

                    if (data.supported === false) {
                        if (data.detail) {
                            show('warn', t.checkOff + ' [' + t.adminDetails + ': ' + data.detail + ']');
                            window.playerCheck.state = 'idle';
                        } else {
                            hide();
                        }
                        return;
                    }

                    var out = data.valid
                        ? { kind: 'card', state: 'valid', gameId: gameId(values), name: data.player_name || null, region: data.region || null }
                        : { kind: 'bad', state: 'invalid', text: t.invalid };

                    cache[key] = out;
                    render(out);
                }

                function run() {
                    var values = collect();

                    if (!values) { lastKey = null; hide(); return; }

                    var key = JSON.stringify(values);
                    if (key === lastKey) return;
                    lastKey = key;

                    if (cache[key]) { render(cache[key]); return; }

                    if (controller) controller.abort();
                    controller = new AbortController();
                    var mine = ++sequence;

                    show('wait', t.checking);
                    window.playerCheck.state = 'checking';
                    retry.classList.add('hidden');

                    var body = new FormData();
                    Object.keys(values).forEach(function (name) { body.append(name, values[name]); });

                    fetch(url, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                        body: body,
                        credentials: 'same-origin',
                        signal: controller.signal
                    })
                        .then(function (r) {
                            return r.json().catch(function () { return {}; }).then(function (json) {
                                return { status: r.status, ok: r.ok, json: json };
                            });
                        })
                        .then(function (response) {
                            if (mine !== sequence) return; // an older answer: ignore it
                            handle(response, key, values);
                        })
                        .catch(function (error) {
                            if (error && error.name === 'AbortError') return;
                            if (mine !== sequence) return;
                            failed(null);
                        });
                }

                inputs().forEach(function (el) {
                    el.addEventListener('input', function () {
                        // The old answer belongs to the old text: remove it immediately.
                        lastKey = null;
                        if (controller) controller.abort();
                        sequence++;
                        hide();
                        clearTimeout(timer);
                        timer = setTimeout(run, 800);
                    });

                    el.addEventListener('change', function () {
                        clearTimeout(timer);
                        run();
                    });
                });

                retry.addEventListener('click', function () { lastKey = null; run(); });

                run(); // checks the values that are already there (for example after a failed order)
            })();
        </script>
    @endif
</x-public-layout>