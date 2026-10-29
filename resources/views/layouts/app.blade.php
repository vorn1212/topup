<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="description"
      content="@yield('meta_description', 'Keo Topup offers Mobile Legends diamonds, Free Fire diamonds, PUBG Mobile UC and other game top-up services in Cambodia.')">
        <meta name="robots" content="index, follow">
        <meta name="keywords"
      content="@yield('meta_keywords', 'Keo Topup,keo topup, game top up Cambodia, MLBB diamonds Cambodia, Free Fire diamonds, PUBG Mobile UC, online game recharge Cambodia')">

        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="https://www.keotopup.com/favicon.png">

        @include('layouts._fonts')
        @include('layouts._branding')
        @include('layouts._gaming')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="gaming-bg {{ request()->routeIs('admin.*') ? 'gaming-calm' : '' }} font-sans antialiased">
        <div class="gg-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
        @include('layouts._halloween')
        @include('layouts._lightning')

        <div class="min-h-screen bg-gray-100 site-bg">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow site-header">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
