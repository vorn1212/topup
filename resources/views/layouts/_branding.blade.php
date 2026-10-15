@php
    // The look chosen in Admin > Site settings. Every colour was checked as #RRGGBB when saved AND when read.
    $brand = app(\App\Services\SiteSettings::class);
    $c = $brand->colors();
    $favicon = $brand->faviconUrl();

    $ink = fn (string $key) => $brand->readableOn($c[$key]);
    $panelAlt = $brand->mix($c['panel'], $ink('panel'), 0.06);
@endphp
@if ($favicon)
    <link rel="icon" href="{{ $favicon }}">
@endif
<style>
    :root{--c-primary:{{ $c['primary'] }};--c-primary-ink:{{ $ink('primary') }};--c-page:{{ $c['page'] }};--c-page-ink:{{ $ink('page') }};--c-nav:{{ $c['nav'] }};--c-nav-ink:{{ $ink('nav') }};--c-header:{{ $c['header'] }};--c-header-ink:{{ $ink('header') }};--c-panel:{{ $c['panel'] }};--c-panel-ink:{{ $ink('panel') }};--c-panel-alt:{{ $panelAlt }};--c-footer:{{ $c['footer'] }};--c-footer-ink:{{ $ink('footer') }};--c-card:{{ $c['card'] }};--c-card-ink:{{ $ink('card') }};--c-accent:{{ $c['accent'] }}}

    /* Brand colour: buttons, links, active menu */
    .bg-indigo-600{background-color:var(--c-primary)!important;color:var(--c-primary-ink)!important}
    .hover\:bg-indigo-500:hover,.hover\:bg-indigo-700:hover{background-color:var(--c-primary)!important;color:var(--c-primary-ink)!important;filter:brightness(.92)}
    .text-indigo-600,.text-indigo-700{color:var(--c-primary)!important}
    .hover\:text-indigo-600:hover,.hover\:text-indigo-500:hover,.group:hover .group-hover\:text-indigo-600{color:var(--c-primary)!important}
    .ring-indigo-600{--tw-ring-color:var(--c-primary)!important}
    .border-indigo-400,.border-indigo-500,.border-indigo-600{border-color:var(--c-primary)!important}
    .focus\:border-indigo-500:focus{border-color:var(--c-primary)!important}
    .focus\:ring-indigo-500:focus{--tw-ring-color:var(--c-primary)!important}

    /* Text that sits directly on the page background */
    body{color:var(--c-page-ink)}
    .text-gray-900,.text-gray-800,.text-gray-700{color:var(--c-page-ink)!important}
    .text-gray-600,.text-gray-500,.text-gray-400{color:var(--c-page-ink)!important;opacity:.72}

    /* The white boxes, and the text inside them */
    .bg-white{background-color:var(--c-panel)!important;color:var(--c-panel-ink)}
    .bg-gray-50,.hover\:bg-gray-50:hover{background-color:var(--c-panel-alt)!important}
    .bg-white .text-gray-900,.bg-white .text-gray-800,.bg-white .text-gray-700,
    .bg-white.text-gray-900,.bg-white.text-gray-800,.bg-white.text-gray-700,
    .bg-gray-50 .text-gray-900,.bg-gray-50 .text-gray-800,.bg-gray-50 .text-gray-700{color:var(--c-panel-ink)!important}
    .bg-white .text-gray-600,.bg-white .text-gray-500,.bg-white .text-gray-400,
    .bg-white.text-gray-600,.bg-white.text-gray-500,.bg-white.text-gray-400,
    .bg-gray-50 .text-gray-600,.bg-gray-50 .text-gray-500,.bg-gray-50 .text-gray-400,
    .bg-gray-50.text-gray-600,.bg-gray-50.text-gray-500,.bg-gray-50.text-gray-400{color:var(--c-panel-ink)!important;opacity:.72}

    /* Named areas (these come last so they win over the general rules above) */
    .site-bg{background-color:var(--c-page)!important}
    .site-brand-text{color:var(--c-page-ink)}
    .site-nav{background-color:var(--c-nav)!important}
    .site-nav .nav-ink-link{color:var(--c-nav-ink)!important;opacity:1}
    .site-nav .site-brand-text{color:var(--c-nav-ink)!important}
    .site-header{background-color:var(--c-header)!important}
    .site-header .text-gray-800,.site-header .text-gray-900{color:var(--c-header-ink)!important}
    .site-footer.bg-white{background-color:var(--c-footer)!important;color:var(--c-footer-ink)!important;opacity:1}
    .site-card{background-color:var(--c-card)!important}
    .site-card-media{background-color:var(--c-accent)!important}
    .site-card .site-card-ink{color:var(--c-card-ink)!important;opacity:1}
    .site-card .site-card-muted{color:var(--c-card-ink)!important;opacity:.7}
    .site-card .site-card-initial{color:var(--c-primary)!important;opacity:.6}
</style>