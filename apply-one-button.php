<?php
// One-time patch: ONE pay button on the top-up page.
//   signed in  -> "Pay with wallet" (money is taken from the wallet; if it is not enough: "Insufficient funds. Please add funds.")
//   visitor    -> "Pay with QR"
// Run from the project folder:  php apply-one-button.php   (then delete this file)

$root = __DIR__;
$fail = function (string $m) { fwrite(STDERR, "STOPPED: $m Nothing was changed.\n"); exit(1); };
$read = function (string $rel) use ($root, $fail) {
    $f = "$root/$rel";
    is_file($f) or $fail("$rel not found. Run this from the project folder.");
    $raw = file_get_contents($f);

    return [str_contains($raw, "\r\n") ? "\r\n" : "\n", str_replace("\r\n", "\n", $raw)];
};
$once = function (string $text, string $old, string $new, string $what) use ($fail) {
    substr_count($text, $old) === 1 or $fail("$what was not found exactly once.");

    return str_replace($old, $new, $text);
};
$plans = [];

// 1. the top-up page
[$eol, $t] = $read('resources/views/site/games/topup.blade.php');
if (str_contains($t, 'data-wallet="1"')) {
    $walletButton = <<<'BLADE'
                @if ($showWallet)
                    <button type="submit" formaction="{{ route('orders.store') }}" data-wallet="1"
                            x-bind:disabled="submitting || ! (selected && wallet['p' + selected])"
                            class="mt-4 w-full rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ __('Pay with wallet') }}
                    </button>
                    <p x-show="selected && ! wallet['p' + selected]" x-cloak class="mt-1 text-xs text-gray-500">{{ __('Your wallet balance is not enough for this package.') }}</p>
                @endif

BLADE;
    $t = $once($t, $walletButton, '', 'the second (wallet) button');

    $walletMap = <<<'PHP'

    // Which packages the wallet balance can pay for right now.
    $walletMap = $products->mapWithKeys(fn ($p) => [
        'p'.$p->id => $showWallet && \App\Support\Money::cmp((string) $balance, \App\Support\Money::normalize((string) $p->selling_price)) >= 0,
    ])->all();
PHP;
    $t = $once($t, $walletMap, '', 'the wallet map');
    $t = $once($t, 'wallet: @js($walletMap), ', '', 'the wallet data');
    $t = $once($t, "'payConfirm' => __('Pay :price by QR code?'),\n        'payWallet' => __('Pay :price from your wallet?'),\n", "'payConfirm' => \$showWallet ? __('Pay :price from your wallet?') : __('Pay :price by QR code?'),\n", 'the confirm texts');
    $t = $once($t, '($event.submitter && $event.submitter.dataset.wallet ? t.payWallet : t.payConfirm).replace(', 't.payConfirm.replace(', 'the confirm call');
    $t = $once($t, "class=\"{{ \$showWallet ? 'mt-2' : 'mt-4' }} w-full rounded-md bg-indigo-600", 'class="mt-4 w-full rounded-md bg-indigo-600', 'the button class');
    $t = $once($t, "{{ __('Pay with QR') }}", "{{ \$showWallet ? __('Pay with wallet') : __('Pay with QR') }}", 'the button label');
    $t = $once($t, "{{ \$orderingReady ? route('checkout.store') : '#' }}", "{{ \$orderingReady ? (\$showWallet ? route('orders.store') : route('checkout.store')) : '#' }}", 'the form action');
    $plans['resources/views/site/games/topup.blade.php'] = str_replace("\n", $eol, $t);
}

// 2. the message when the wallet is too small
$old = 'Your wallet balance is not enough for this package.';
$new = 'Insufficient funds. Please add funds.';
[$eol, $t] = $read('app/Http/Controllers/User/OrderController.php');
if (str_contains($t, $old)) {
    $plans['app/Http/Controllers/User/OrderController.php'] = str_replace("\n", $eol, str_replace($old, $new, $t));
}

// 3. tests that mention the old message or the two-button page
$testDir = "$root/tests";
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testDir, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $rel = 'tests/'.str_replace('\\', '/', substr($file->getPathname(), strlen($testDir) + 1));
    [$e, $x] = $read($rel);
    if (str_contains($x, $old)) {
        $plans[$rel] = str_replace("\n", $e, str_replace($old, $new, $plans[$rel] ?? $x));
    }
}

[$eol, $t] = $read('tests/Feature/SiteTest.php');
$a = "            ->assertSee('Pay with wallet')\n            ->assertSee('Pay with QR')\n";
if (str_contains($t, $a)) {
    $plans['tests/Feature/SiteTest.php'] = str_replace("\n", $eol, str_replace($a, "            ->assertSee('Pay with wallet')\n            ->assertDontSee('Pay with QR')\n", $t));
}

[$eol, $t] = $read('tests/Feature/OrderPagesTest.php');
$re = '/(function test_the_top_up_page_posts_to_the_order_route_with_a_token_and_the_expected_price\(\): void\n    \{\n.*?\n    \}\n)/s';
if (preg_match($re, $t, $m) && str_contains($m[1], 'formaction')) {
    $b = $m[1];
    $b = str_replace("route('checkout.store')", "route('orders.store')", $b);
    $b = str_replace("            ->assertSee('Pay with QR')\n            ->assertSee('formaction=\"'.route('orders.store').'\"', false)\n            ->assertSee('Pay with wallet');", "            ->assertSee('Pay with wallet')\n            ->assertDontSee('Pay with QR');", $b);
    $plans['tests/Feature/OrderPagesTest.php'] = str_replace("\n", $eol, str_replace($m[1], $b, $t));
}

[$eol, $t] = $read('tests/Feature/LanguageTest.php');
$qr = "\u{1794}\u{1784}\u{17cb}\u{1794}\u{17d2}\u{179a}\u{17b6}\u{1780}\u{17cb}\u{178f}\u{17b6}\u{1798} QR";
$wallet = "\u{1794}\u{1784}\u{17cb}\u{1794}\u{17d2}\u{179a}\u{17b6}\u{1780}\u{17cb}\u{1796}\u{17b8}\u{1780}\u{17b6}\u{1794}\u{17bc}\u{1794}";
if (str_contains($t, $qr)) {
    $plans['tests/Feature/LanguageTest.php'] = str_replace("\n", $eol, str_replace($qr, $wallet, $t));
}

// 4. Khmer words (only adds missing ones)
[, $json] = $read('lang/km.json');
$km = json_decode($json, true) ?: $fail('lang/km.json is not valid JSON.');
$key = 'Insufficient funds. Please add funds.';
if (! array_key_exists($key, $km)) {
    $km[$key] = "\u{179f}\u{1798}\u{178f}\u{17bb}\u{179b}\u{17d2}\u{1799}\u{1798}\u{17b7}\u{1793}\u{1782}\u{17d2}\u{179a}\u{1794}\u{17cb}\u{1782}\u{17d2}\u{179a}\u{17b6}\u{1793}\u{17cb}\u{17d4} \u{179f}\u{17bc}\u{1798}\u{178a}\u{17b6}\u{1780}\u{17cb}\u{1794}\u{17d2}\u{179a}\u{17b6}\u{1780}\u{17cb}\u{1794}\u{1793}\u{17d2}\u{1790}\u{17c2}\u{1798}\u{17d4}";
    $plans['lang/km.json'] = json_encode($km, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

foreach ($plans as $rel => $text) {
    file_put_contents("$root/$rel", $text);
    echo "Updated $rel\n";
}
echo $plans ? "Done.\n" : "Already applied.\n";
