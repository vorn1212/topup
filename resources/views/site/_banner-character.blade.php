{{-- Original 3D-style boy gamer (drawn in SVG). Shown on the home page until a picture is uploaded in Admin > Site settings. --}}
<svg class="gg-character h-full w-full" viewBox="0 0 400 440" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ __('Gamer boy') }}">
    <defs>
        <radialGradient id="ggSkin" cx="40%" cy="35%" r="75%">
            <stop offset="0" stop-color="#ffe2c6"/>
            <stop offset="1" stop-color="#eba97c"/>
        </radialGradient>
        <linearGradient id="ggHair" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#3b4a78"/>
            <stop offset="1" stop-color="#0b1020"/>
        </linearGradient>
        <linearGradient id="ggHoodie" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#4f8cff"/>
            <stop offset="1" stop-color="#1b2f86"/>
        </linearGradient>
        <linearGradient id="ggCup" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#67e8f9"/>
            <stop offset="1" stop-color="#0e7490"/>
        </linearGradient>
        <linearGradient id="ggPad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#334155"/>
            <stop offset="1" stop-color="#0f172a"/>
        </linearGradient>
        <radialGradient id="ggGlow" cx="50%" cy="50%" r="50%">
            <stop offset="0" stop-color="#38bdf8" stop-opacity=".55"/>
            <stop offset="1" stop-color="#38bdf8" stop-opacity="0"/>
        </radialGradient>
    </defs>

    {{-- glow and ground shadow --}}
    <circle cx="200" cy="230" r="190" fill="url(#ggGlow)"/>
    <ellipse cx="200" cy="420" rx="120" ry="14" fill="#000" opacity=".35"/>

    {{-- body --}}
    <path d="M85 410 C85 322 128 288 200 288 C272 288 315 322 315 410 Z" fill="url(#ggHoodie)"/>
    <path d="M150 292 C165 330 235 330 250 292 C235 288 165 288 150 292 Z" fill="#12205f" opacity=".55"/>
    <path d="M200 318 L200 405" stroke="#a5c4ff" stroke-width="3" opacity=".6"/>
    <path d="M168 318 L168 352 M232 318 L232 352" stroke="#e0ecff" stroke-width="4" stroke-linecap="round"/>

    {{-- neck --}}
    <rect x="178" y="262" width="44" height="40" rx="14" fill="#d9966a"/>

    {{-- ears and head --}}
    <ellipse cx="113" cy="196" rx="14" ry="20" fill="#e3a074"/>
    <ellipse cx="287" cy="196" rx="14" ry="20" fill="#e3a074"/>
    <ellipse cx="200" cy="190" rx="88" ry="94" fill="url(#ggSkin)"/>

    {{-- hair --}}
    <path d="M108 180 C100 100 148 66 202 68 C258 66 302 100 292 180 C280 142 250 128 202 130 C152 128 122 142 108 180 Z" fill="url(#ggHair)"/>
    <path d="M148 96 L160 52 L186 88 Z M190 84 L212 42 L232 86 Z M236 90 L262 56 L270 104 Z" fill="url(#ggHair)"/>
    <path d="M150 100 C172 84 200 80 226 86" stroke="#8fa3d9" stroke-width="5" stroke-linecap="round" fill="none" opacity=".55"/>

    {{-- face --}}
    <path d="M148 168 Q168 158 188 166 M212 166 Q232 158 252 168" stroke="#2a1b16" stroke-width="6" stroke-linecap="round" fill="none"/>
    <ellipse cx="168" cy="204" rx="16" ry="20" fill="#fff"/>
    <ellipse cx="232" cy="204" rx="16" ry="20" fill="#fff"/>
    <circle cx="170" cy="207" r="11" fill="#1d4ed8"/>
    <circle cx="230" cy="207" r="11" fill="#1d4ed8"/>
    <circle cx="170" cy="207" r="6" fill="#0a1236"/>
    <circle cx="230" cy="207" r="6" fill="#0a1236"/>
    <circle cx="175" cy="200" r="4" fill="#fff"/>
    <circle cx="235" cy="200" r="4" fill="#fff"/>
    <circle cx="140" cy="232" r="13" fill="#ff8aa0" opacity=".35"/>
    <circle cx="260" cy="232" r="13" fill="#ff8aa0" opacity=".35"/>
    <path d="M178 240 Q200 262 222 240 Z" fill="#7a2f33"/>
    <path d="M182 241 Q200 249 218 241 L216 245 Q200 252 184 245 Z" fill="#fff"/>

    {{-- headset --}}
    <path d="M106 192 C100 96 300 96 294 192" stroke="#0f172a" stroke-width="13" fill="none" stroke-linecap="round"/>
    <path d="M112 186 C110 112 290 112 288 186" stroke="#22d3ee" stroke-width="3" fill="none" opacity=".8"/>
    <rect x="92" y="168" width="28" height="60" rx="13" fill="url(#ggCup)" stroke="#0f172a" stroke-width="4"/>
    <rect x="280" y="168" width="28" height="60" rx="13" fill="url(#ggCup)" stroke="#0f172a" stroke-width="4"/>
    <path d="M100 226 C104 262 146 272 176 262" stroke="#0f172a" stroke-width="6" fill="none" stroke-linecap="round"/>
    <ellipse cx="182" cy="262" rx="11" ry="8" fill="#22d3ee" stroke="#0f172a" stroke-width="3"/>

    {{-- arms, controller and hands --}}
    <path d="M92 396 C70 350 100 322 128 330 L158 372 Z" fill="#2d52c7"/>
    <path d="M308 396 C330 350 300 322 272 330 L242 372 Z" fill="#2d52c7"/>
    <rect x="128" y="346" width="144" height="54" rx="26" fill="url(#ggPad)" stroke="#38bdf8" stroke-width="2.5"/>
    <path d="M165 360 v26 M152 373 h26" stroke="#7dd3fc" stroke-width="6" stroke-linecap="round"/>
    <circle cx="232" cy="364" r="6" fill="#f43f5e"/>
    <circle cx="246" cy="374" r="6" fill="#facc15"/>
    <circle cx="232" cy="384" r="6" fill="#4ade80"/>
    <circle cx="218" cy="374" r="6" fill="#60a5fa"/>
    <circle cx="140" cy="378" r="17" fill="url(#ggSkin)"/>
    <circle cx="260" cy="378" r="17" fill="url(#ggSkin)"/>

    {{-- sparkles --}}
    <path d="M52 120 l6 14 14 6 -14 6 -6 14 -6 -14 -14 -6 14 -6 Z" fill="#67e8f9"/>
    <path d="M346 90 l5 11 11 5 -11 5 -5 11 -5 -11 -11 -5 11 -5 Z" fill="#93c5fd"/>
    <path d="M336 300 l4 9 9 4 -9 4 -4 9 -4 -9 -9 -4 9 -4 Z" fill="#67e8f9"/>
    <circle cx="70" cy="290" r="5" fill="#60a5fa"/>
</svg>
