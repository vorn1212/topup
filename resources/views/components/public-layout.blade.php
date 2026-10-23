@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'canonical' => null,
    'noindex' => false,
    'ogType' => 'website',
])

@inject('siteSettings', 'App\Services\SiteSettings')

@php
    $siteName = config('app.name');
    $pageTitle = filled($title) ? $title.' | '.$siteName : $siteName.' | '.__('Game Top-Up');
    $fallback = __('Instant game top-up. Pay with your wallet and receive diamonds, UC and in-game credits in minutes.');
    $pageDescription = \Illuminate\Support\Str::limit(
        trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($description ?: $fallback)))),
        160,
        ''
    );
    $pageCanonical = $canonical ?: url()->current();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $pageCanonical }}">
    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @endif

    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $pageCanonical }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">

    @include('layouts._fonts')
    @include('layouts._branding')
    @include('layouts._gaming')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="gaming-bg flex min-h-screen flex-col bg-gray-100 site-bg font-sans text-gray-900 antialiased">
    <div class="gg-orbs" aria-hidden="true"><span></span><span></span><span></span></div>

    <header class="border-b bg-white site-nav">
        <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center"><x-brand-logo img-class="h-9 w-auto" text-class="text-lg font-bold" /></a>

            <nav class="flex items-center gap-3 text-sm sm:gap-4">
                <x-language-switcher />
                <a href="{{ route('games.index') }}" class="nav-ink-link text-gray-700 hover:text-indigo-600">{{ __('Games') }}</a>
                <a href="{{ route('contact') }}" class="nav-ink-link hidden text-gray-700 hover:text-indigo-600 sm:inline">{{ __('Contact') }}</a>
                @auth
                    <a href="{{ route('wallet.index') }}" class="nav-ink-link text-gray-700 hover:text-indigo-600">{{ __('Wallet') }}</a>
                    <a href="{{ route('dashboard') }}"
                       class="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500">{{ __('Dashboard') }}</a>
                @else
                    <a href="{{ route('login') }}" class="nav-ink-link text-gray-700 hover:text-indigo-600">{{ __('Log in') }}</a>
                    <a href="{{ route('register') }}"
                       class="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-500">{{ __('Register') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="flex-1">
        @unless (request()->routeIs('home'))
            <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="back-link"
                   onclick="if (history.length > 1 && document.referrer.indexOf(location.host) !== -1) { history.back(); return false; }">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                    {{ __('Back') }}
                </a>
            </div>
            <style>
                .back-link { display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .9rem .4rem .6rem; border-radius: 9999px; font-size: .9rem; font-weight: 600; color: #fff !important; background: rgba(15, 23, 42, .65); border: 1px solid rgba(255, 255, 255, .25); text-decoration: none; }
                .back-link:hover { background: rgba(37, 99, 235, .85); }
            </style>
        @endunless
        {{ $slot }}
    </main>

    <footer class="site-footer border-t bg-white py-6 text-center text-xs text-gray-500">
        @if ($siteSettings->hasContact())
            <div class="mb-4">
                <div class="mb-2 text-sm font-semibold text-gray-700">{{ __('Contact Us') }}</div>
                @include('site._contact-buttons')
            </div>
        @else
            <a href="{{ route('contact') }}" class="mb-3 inline-block text-sm text-indigo-600 hover:underline">{{ __('Contact Us') }}</a>
        @endif
        <div class="mb-2"><a href="{{ route('faq') }}" class="underline">{{ __('FAQ') }}</a> &middot; <a href="{{ route('privacy') }}" class="underline">{{ __('Privacy Policy') }}</a></div>
        <div>&copy; {{ date('Y') }} {{ $siteName }}. {{ __('All rights reserved.') }}</div>
    </footer>
</body>
</html>
