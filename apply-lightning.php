<?php
// One-time patch: lightning flashes in the background, one about every 10 seconds.
// Run from the project folder:  php apply-lightning.php   (then delete this file)

$root = __DIR__;
$files = [
    'resources/views/components/public-layout.blade.php',
    'resources/views/layouts/app.blade.php',
    'resources/views/layouts/guest.blade.php',
];
$done = 0;

foreach ($files as $rel) {
    $f = "$root/$rel";
    if (! is_file($f)) { echo "$rel: not found, skipped.\n"; continue; }

    $text = file_get_contents($f);
    if (str_contains($text, 'layouts._lightning')) { echo "$rel: already patched.\n"; continue; }

    // After the Halloween layer if it is there, else after the glowing background balls.
    $count = 0;
    foreach (['/^([ \t]*)(@include\(\'layouts\._halloween\'\))(\r?\n)/m', '/^([ \t]*)(<div class="gg-orbs"[^\n]*<\/div>)(\r?\n)/m'] as $re) {
        $text = preg_replace_callback($re, fn ($m) => $m[1].$m[2].$m[3].$m[1]."@include('layouts._lightning')".$m[3], $text, 1, $count);
        if ($count === 1) { break; }
    }

    if ($count === 1) {
        file_put_contents($f, $text);
        echo "Updated $rel\n";
        $done++;
    } else {
        echo "$rel: no background layer found, skipped.\n";
    }
}

echo $done > 0 ? "Done.\n" : "Nothing changed.\n";
