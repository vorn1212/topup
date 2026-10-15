<!-- Fonts -->
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
@if (app()->getLocale() === 'km')
    {{-- Khmer needs a real Khmer font and more line height, or the stacked letters get cut off. --}}
    <link href="https://fonts.bunny.net/css?family=noto-sans-khmer:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        html[lang="km"] body {
            font-family: 'Noto Sans Khmer', 'Khmer UI', 'Leelawadee UI', 'Khmer OS Battambang', system-ui, sans-serif;
            line-height: 1.7;
        }
        html[lang="km"] h1, html[lang="km"] h2, html[lang="km"] h3, html[lang="km"] h4 { line-height: 1.5; }
    </style>
@endif