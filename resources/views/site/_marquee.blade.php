{{-- Scrolling announcement under the banner. The text is plain text (escaped); plain CSS, no build step. --}}
<div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
    <div class="gg-marquee" role="note">
        <span class="gg-marquee-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>
        </span>
        <div class="gg-marquee-view">
            <div class="gg-marquee-track" style="animation-duration: {{ max(14, (int) round(mb_strlen($text) * 0.22)) }}s">
                <span>{{ $text }}</span>
                <span aria-hidden="true">{{ $text }}</span>
            </div>
        </div>
    </div>
</div>
<style>
    .gg-marquee { display: flex; align-items: center; gap: .6rem; padding: .55rem .9rem; border-radius: .75rem; background: rgba(15, 30, 80, .55); border: 1px solid rgba(96, 165, 250, .35); color: #e0ecff; font-size: .95rem; font-weight: 600; }
    .gg-marquee-icon { flex: none; display: inline-flex; color: #7dd3fc; }
    .gg-marquee-view { flex: 1; min-width: 0; overflow: hidden; }
    .gg-marquee-track { display: flex; width: max-content; animation: gg-marquee-run 20s linear infinite; }
    .gg-marquee-track span { flex: none; min-width: 100%; box-sizing: border-box; padding: 0 2.5rem; white-space: nowrap; }
    .gg-marquee:hover .gg-marquee-track { animation-play-state: paused; }
    @keyframes gg-marquee-run { from { transform: translateX(0); } to { transform: translateX(-100%); } }
    @media (prefers-reduced-motion: reduce) {
        .gg-marquee-track { animation: none; width: auto; }
        .gg-marquee-track span { white-space: normal; padding: 0; }
        .gg-marquee-track span + span { display: none; }
    }
</style>
