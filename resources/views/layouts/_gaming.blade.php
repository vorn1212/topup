{{-- Dark-blue animated gaming background for the public pages. Pure CSS: nothing to build, nothing to download. --}}
<style>
    body.gaming-bg { color: #dbe7ff; background-color: #040a1c; }

    /* Layer 1: deep blue gradient with two soft light pools. */
    body.gaming-bg::before {
        content: ""; position: fixed; inset: 0; z-index: -3;
        background:
            radial-gradient(1100px 650px at 12% -8%, rgba(37, 99, 235, .55), transparent 60%),
            radial-gradient(900px 600px at 105% 8%, rgba(14, 165, 233, .30), transparent 55%),
            linear-gradient(180deg, #040a1c 0%, #071340 55%, #030818 100%);
    }

    /* Layer 2: drifting stars and a faint neon grid. */
    body.gaming-bg::after {
        content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none;
        background-image:
            radial-gradient(1.5px 1.5px at 30px 40px, rgba(190, 215, 255, .95), transparent 60%),
            radial-gradient(1px 1px at 110px 120px, rgba(147, 197, 253, .8), transparent 60%),
            linear-gradient(rgba(96, 165, 250, .07) 1px, transparent 1px),
            linear-gradient(90deg, rgba(96, 165, 250, .07) 1px, transparent 1px);
        background-size: 180px 180px, 260px 260px, 48px 48px, 48px 48px;
        -webkit-mask-image: linear-gradient(180deg, #000 0%, rgba(0, 0, 0, .55) 70%, rgba(0, 0, 0, .2) 100%);
        mask-image: linear-gradient(180deg, #000 0%, rgba(0, 0, 0, .55) 70%, rgba(0, 0, 0, .2) 100%);
        animation: gg-move 26s linear infinite;
    }
    @keyframes gg-move {
        to { background-position: 0 180px, 0 260px, 0 48px, 48px 0; }
    }

    /* Layer 3: slow floating glow balls. */
    .gg-orbs { position: fixed; inset: 0; z-index: -2; overflow: hidden; pointer-events: none; }
    .gg-orbs span { position: absolute; border-radius: 9999px; filter: blur(70px); opacity: .38; animation: gg-float 18s ease-in-out infinite alternate; }
    .gg-orbs span:nth-child(1) { width: 380px; height: 380px; left: -90px; top: 18%; background: #2563eb; }
    .gg-orbs span:nth-child(2) { width: 320px; height: 320px; right: -80px; top: 48%; background: #06b6d4; animation-duration: 23s; animation-delay: -6s; }
    .gg-orbs span:nth-child(3) { width: 260px; height: 260px; left: 42%; top: -60px; background: #6366f1; animation-duration: 28s; animation-delay: -12s; }
    @keyframes gg-float {
        from { transform: translate3d(0, 0, 0) scale(1); }
        to   { transform: translate3d(60px, -50px, 0) scale(1.15); }
    }

    /* The banner character gently floats. */
    .gg-float { animation: gg-bob 5s ease-in-out infinite; }
    @keyframes gg-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }

    /* Signed-in pages: the grey page wrapper must let the animation show through. */
    body.gaming-bg .site-bg { background-color: transparent !important; }

    /* Admin area: same look, but calm (still stars, no moving grid, dimmer glows) so tables stay easy to read. */
    body.gaming-calm::after { animation: none; opacity: .55; }
    body.gaming-calm .gg-orbs span { opacity: .18; animation-duration: 60s; }

    /* Glass panels used by the banner and the contact page. */
    .gg-glass { background: rgba(15, 30, 80, .55); border: 1px solid rgba(96, 165, 250, .35); box-shadow: 0 0 40px rgba(37, 99, 235, .25); backdrop-filter: blur(6px); }

    /* Text that sits straight on the dark background. The branding colours force page text with !important,
       so the page text colour itself is changed here (it follows the dark background, not the page colour setting). */
    body.gaming-bg { --c-page-ink: #dbe7ff; }

    /* Form boxes keep a white background, so their text must stay dark (they would otherwise inherit the light page text). */
    body.gaming-bg :is(input, select, textarea):not([type="checkbox"], [type="radio"], [type="color"], [type="range"], [type="file"], [type="submit"], [type="button"]) { color: #111827; }
    body.gaming-bg :is(select, select option, datalist option) { color: #111827; }
    body.gaming-bg ::placeholder { color: #6b7280; opacity: 1; }

    /* Light notice boxes (selected package, green player card, yellow or red notices): dark text stays dark inside them. */
    body.gaming-bg :is(.bg-indigo-50, .bg-green-50, .bg-yellow-50, .bg-red-50, .bg-blue-50) :is(.text-gray-900, .text-gray-800, .text-gray-700, .text-gray-600, .text-gray-500),
    body.gaming-bg :is(.bg-indigo-50, .bg-green-50, .bg-yellow-50, .bg-red-50, .bg-blue-50):is(.text-gray-900, .text-gray-800, .text-gray-700, .text-gray-600, .text-gray-500) { color: #1f2937 !important; opacity: 1 !important; }

    /* Links and prices inside those light boxes use a strong indigo, whatever button colour was chosen. */
    body.gaming-bg :is(.bg-indigo-50, .bg-green-50, .bg-yellow-50, .bg-red-50, .bg-blue-50) :is(.text-indigo-600, .text-indigo-700) { color: #4338ca !important; }

    @media (prefers-reduced-motion: reduce) {
        body.gaming-bg::after, .gg-orbs span, .gg-float { animation: none !important; }
    }
</style>
