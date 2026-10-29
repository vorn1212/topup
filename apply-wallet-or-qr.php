<?php
// One-time patch: signed-in customers can pay from their wallet again (when the balance is enough),
// or by QR. Visitors without an account still pay by QR.
// Run from the project folder:  php apply-wallet-or-qr.php   (then delete this file)

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
if (! str_contains($t, '$showWallet')) {
    $t = $once($t, '$canOrder = $orderingReady && (! $user || $user->isActive());', <<<'PHP'
$canOrder = $orderingReady && (! $user || $user->isActive());
    $showWallet = $orderingReady && $user && $user->isActive();

    // Which packages the wallet balance can pay for right now.
    $walletMap = $products->mapWithKeys(fn ($p) => [
        'p'.$p->id => $showWallet && \App\Support\Money::cmp((string) $balance, \App\Support\Money::normalize((string) $p->selling_price)) >= 0,
    ])->all();
PHP, 'the $canOrder line');

    $t = $once($t, "        'payConfirm' => __('Pay :price by QR code?'),\n", "        'payConfirm' => __('Pay :price by QR code?'),\n        'payWallet' => __('Pay :price from your wallet?'),\n", 'the payConfirm text');
    $t = $once($t, 'raw: @js($rawMap), t: @js($t), submitting: false }', 'raw: @js($rawMap), wallet: @js($walletMap), t: @js($t), submitting: false }', 'the form data');
    $t = $once($t, 't.payConfirm.replace(', '($event.submitter && $event.submitter.dataset.wallet ? t.payWallet : t.payConfirm).replace(', 'the confirm text call');

    $note = "                <p class=\"mt-3 text-xs text-gray-500\">{{ __('The final price is confirmed again when you place the order.') }}</p>\n";
    $balanceBlock = <<<'BLADE'
                @if ($user)
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-gray-600">{{ __('Your wallet balance') }}</span>
                        <span class="font-medium text-gray-900">{{ \App\Support\Money::format($balance) }}</span>
                    </div>
                    <p class="mt-1 text-right text-xs">
                        <a href="{{ route('wallet.deposit.create') }}" class="text-indigo-600 hover:underline">{{ __('Deposit money') }}</a>
                    </p>
                @endif


BLADE;
    $t = $once($t, $note, $balanceBlock.$note, 'the price note');

    $button = "                <button type=\"submit\" x-bind:disabled=\"{{ \$canOrder ? 'submitting' : 'true' }}\"\n                        class=\"mt-4 w-full rounded-md bg-indigo-600";
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
    $t = $once($t, $button, $walletButton."                <button type=\"submit\" x-bind:disabled=\"{{ \$canOrder ? 'submitting' : 'true' }}\"\n                        class=\"{{ \$showWallet ? 'mt-2' : 'mt-4' }} w-full rounded-md bg-indigo-600", 'the pay button');
    $plans['resources/views/site/games/topup.blade.php'] = str_replace("\n", $eol, $t);
}

// 2. the two tests that described the QR-only page
[$eol, $t] = $read('tests/Feature/SiteTest.php');
if (! str_contains($t, 'test_top_up_page_shows_wallet_balance_and_both_payment_options')) {
    $t = $once($t, 'test_top_up_page_offers_qr_payment_and_no_wallet', 'test_top_up_page_shows_wallet_balance_and_both_payment_options', 'the wallet test name');
    $t = $once($t, "            ->assertSee('Pay with QR')\n            ->assertDontSee('\$12.50')\n            ->assertDontSee('to place an order');", "            ->assertSee('\$12.50')\n            ->assertSee('Deposit money')\n            ->assertSee('Pay with wallet')\n            ->assertSee('Pay with QR')\n            ->assertDontSee('to place an order');", 'the wallet test assertions');
    $plans['tests/Feature/SiteTest.php'] = str_replace("\n", $eol, $t);
}

[$eol, $t] = $read('tests/Feature/OrderPagesTest.php');
$re = '/(function test_the_top_up_page_posts_to_the_order_route_with_a_token_and_the_expected_price\(\): void\n    \{\n.*?\n    \}\n)/s';
if (preg_match($re, $t, $m) && ! str_contains($m[1], 'formaction')) {
    $new = str_replace("->assertSee('Pay with QR');", "->assertSee('Pay with QR')\n            ->assertSee('formaction=\"'.route('orders.store').'\"', false)\n            ->assertSee('Pay with wallet');", $m[1]);
    $new !== $m[1] or $fail('the order-page test has an unexpected shape.');
    $plans['tests/Feature/OrderPagesTest.php'] = str_replace("\n", $eol, str_replace($m[1], $new, $t));
}

foreach ($plans as $rel => $text) {
    file_put_contents("$root/$rel", $text);
    echo "Updated $rel\n";
}
echo $plans ? "Done.\n" : "Already applied.\n";
