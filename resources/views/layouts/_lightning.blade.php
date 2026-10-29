{{-- Lightning in the background: a soft flash and a bolt about every 10 seconds (CSS only, no script). --}}
@php
    // left % of the bolt, seconds before its first flash. Three bolts, each repeating every 30 s, give one flash every 10 s.
    $bolts = [[14, 10], [58, 20], [84, 30]];
@endphp
<div class="gg-lightning" aria-hidden="true">
    @foreach ($bolts as [$left, $delay])
        <div class="gg-flash" style="animation-delay:{{ $delay }}s;background:radial-gradient(70% 55% at {{ $left + 4 }}% 0%, rgba(196, 220, 255, .55), rgba(96, 165, 250, .16) 45%, transparent 75%)">
            <svg class="gg-bolt" viewBox="0 0 60 200" preserveAspectRatio="none" style="left:{{ $left }}%;animation-delay:{{ $delay }}s">
                <polygon points="38,0 12,88 28,88 8,200 52,72 34,72 54,0" fill="#eaf3ff"/>
            </svg>
        </div>
    @endforeach
</div>
<style>
    .gg-lightning { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
    .gg-flash { position: absolute; inset: 0; opacity: 0; animation: gg-flash 30s linear infinite; }
    .gg-bolt { position: absolute; top: 0; width: 70px; height: 62vh; opacity: 0; filter: drop-shadow(0 0 10px #93c5fd) drop-shadow(0 0 26px #60a5fa); animation: gg-flash 30s linear infinite; }
    /* One flash with a quick flicker, then dark again until the next bolt. */
    @keyframes gg-flash {
        0% { opacity: 0; }
        0.6% { opacity: 1; }
        1.4% { opacity: .15; }
        2.2% { opacity: .85; }
        4.5% { opacity: 0; }
        100% { opacity: 0; }
    }
    @media (prefers-reduced-motion: reduce) { .gg-lightning { display: none; } }
    body.gaming-calm .gg-lightning { display: none; }
</style>
