@php
    // Halloween season: 15 October to 3 November. Outside these dates nothing is drawn.
    $month = (int) now()->format('n');
    $day = (int) now()->format('j');
    $halloween = ($month === 10 && $day >= 15) || ($month === 11 && $day <= 3);

    // left %, size px, seconds for one flight, start offset (seconds), how far it sways
    $pumpkins = [
        [6, 56, 34, -4, 14], [17, 38, 27, -19, 10], [29, 66, 40, -11, 16], [41, 44, 30, -25, 12],
        [53, 58, 37, -7, 15], [64, 36, 26, -15, 9], [75, 70, 42, -29, 17], [86, 48, 31, -2, 12],
        [94, 40, 28, -21, 10],
    ];
@endphp
@if ($halloween)
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
        <defs>
            <symbol id="gg-pumpkin" viewBox="0 0 100 100">
                <path d="M50 22c-2-9 3-15 11-16" fill="none" stroke="#4d7c0f" stroke-width="7" stroke-linecap="round"/>
                <ellipse cx="50" cy="60" rx="42" ry="33" fill="#ea580c"/>
                <ellipse cx="27" cy="60" rx="22" ry="33" fill="#f97316"/>
                <ellipse cx="73" cy="60" rx="22" ry="33" fill="#f97316"/>
                <ellipse cx="50" cy="60" rx="16" ry="34" fill="#fb923c"/>
                <polygon points="30,50 42,50 36,40" fill="#fef08a"/>
                <polygon points="58,50 70,50 64,40" fill="#fef08a"/>
                <polygon points="30,68 36,76 42,68 50,78 58,68 64,76 70,68 66,84 34,84" fill="#fef08a"/>
            </symbol>
        </defs>
    </svg>
    <div class="gg-pumpkins" aria-hidden="true">
        @foreach ($pumpkins as $i => [$left, $size, $seconds, $delay, $sway])
            <span class="gg-pk" style="left:{{ $left }}%;width:{{ $size }}px;height:{{ $size }}px;animation-duration:{{ $seconds }}s;animation-delay:{{ $delay }}s">
                <svg viewBox="0 0 100 100" style="animation-duration:{{ 5 + ($i % 4) }}s;--sway:{{ $sway }}deg"><use href="#gg-pumpkin"/></svg>
            </span>
        @endforeach
    </div>
    <style>
        .gg-pumpkins { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
        .gg-pk { position: absolute; bottom: -90px; display: block; opacity: .55; will-change: transform; animation: gg-pk-rise linear infinite; filter: drop-shadow(0 0 10px rgba(249, 115, 22, .55)); }
        .gg-pk svg { display: block; width: 100%; height: 100%; animation: gg-pk-sway ease-in-out infinite alternate; }
        @keyframes gg-pk-rise { from { transform: translateY(0); } to { transform: translateY(-125vh); } }
        @keyframes gg-pk-sway { from { transform: translateX(-14px) rotate(calc(var(--sway) * -1)); } to { transform: translateX(14px) rotate(var(--sway)); } }
        @media (max-width: 639px) { .gg-pk:nth-child(n+6) { display: none; } .gg-pk { opacity: .45; } }
        @media (prefers-reduced-motion: reduce) { .gg-pumpkins { display: none; } }
        body.gaming-calm .gg-pumpkins { display: none; }
    </style>
@endif
