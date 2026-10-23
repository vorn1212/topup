{{--
    Home page banner slider. Pure CSS (no JavaScript): the pictures fade into each other automatically,
    it pauses while the mouse is over it, and it stays still for visitors who asked for less motion.
    Receives $slides = SiteSettings::slides(). Shows nothing when no picture has been uploaded.
--}}
@php
    $count = count($slides);
    $seconds = 5;                                  // how long each picture stays
    $total = $count * $seconds;                    // length of one full round
    $share = round(100 / max(1, $count), 3);       // part of the round that belongs to one picture (%)
    $fade = 2;                                     // cross-fade length (% of the round)
    $fadeEnd = round(min(99.9, $share + $fade), 3);
@endphp

@if ($count > 0)
    <style>
        /* The box and its height are plain CSS on purpose: they must work without rebuilding the Tailwind stylesheet. */
        .gg-slider { position: relative; width: 100%; aspect-ratio: 8 / 3; min-height: 120px; overflow: hidden; background: #0f172a; border-radius: .75rem; box-shadow: 0 10px 25px rgba(0, 0, 0, .35); outline: 1px solid rgba(56, 189, 248, .25); }
        @media (min-width: 640px) { .gg-slider { border-radius: 1rem; } }
        .gg-slider .gg-slide { position: absolute; inset: 0; display: block; opacity: 0; pointer-events: none; }
        .gg-slider .gg-slide.gg-static { opacity: 1; pointer-events: auto; }
        .gg-slider .gg-slide img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .gg-slider .gg-dots { position: absolute; left: 0; right: 0; bottom: .5rem; display: flex; justify-content: center; gap: .4rem; pointer-events: none; }
        .gg-slider .gg-dot { width: .5rem; height: .5rem; border-radius: 9999px; background: #fff; opacity: .35; box-shadow: 0 0 4px rgba(0, 0, 0, .6); }
        @if ($count > 1)
        .gg-slider .gg-slide { animation: ggslide {{ $total }}s linear infinite both; }
        .gg-slider .gg-dot { animation: ggdot {{ $total }}s linear infinite both; }
        .gg-slider:hover .gg-slide, .gg-slider:hover .gg-dot { animation-play-state: paused; }
        @keyframes ggslide {
            0% { opacity: 0; pointer-events: none; }
            {{ $fade }}% { opacity: 1; pointer-events: auto; }
            {{ $share }}% { opacity: 1; pointer-events: auto; }
            {{ $fadeEnd }}% { opacity: 0; pointer-events: none; }
            100% { opacity: 0; pointer-events: none; }
        }
        @keyframes ggdot {
            0% { opacity: .35; transform: scale(1); }
            0.01% { opacity: 1; transform: scale(1.3); }
            {{ $share }}% { opacity: 1; transform: scale(1.3); }
            {{ round($share + 0.01, 3) }}% { opacity: .35; transform: scale(1); }
            100% { opacity: .35; transform: scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .gg-slider .gg-slide, .gg-slider .gg-dot { animation: none !important; }
            .gg-slider .gg-slide:first-child { opacity: 1; pointer-events: auto; }
            .gg-slider .gg-dot:first-child { opacity: 1; }
        }
        @endif
    </style>

    <section class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8" style="max-width: 80rem; margin-left: auto; margin-right: auto; padding-top: 1rem;" aria-roledescription="carousel" aria-label="{{ config('app.name') }}">
        <div class="gg-slider">
            @foreach ($slides as $i => $slide)
                @php
                    $slideClass = 'gg-slide'.($count === 1 ? ' gg-static' : '');
                    $delay = 'animation-delay: '.($i * $seconds).'s';
                @endphp
                @if ($slide['link'])
                    <a href="{{ $slide['link'] }}" class="{{ $slideClass }}" style="{{ $delay }}">
                        <img src="{{ $slide['url'] }}" alt="{{ config('app.name') }}" decoding="async" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif>
                    </a>
                @else
                    <div class="{{ $slideClass }}" style="{{ $delay }}">
                        <img src="{{ $slide['url'] }}" alt="{{ config('app.name') }}" decoding="async" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif>
                    </div>
                @endif
            @endforeach

            @if ($count > 1)
                <div class="gg-dots" aria-hidden="true">
                    @foreach ($slides as $i => $slide)
                        <span class="gg-dot" style="animation-delay: {{ $i * $seconds }}s"></span>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif