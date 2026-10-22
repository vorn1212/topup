<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @include('layouts._fonts')
        @include('layouts._branding')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            /* Login / register / password pages: readable on the dark theme whatever colours are saved in Site settings. */
            .auth-card input:not([type=checkbox]):not([type=radio]) { background: #fff !important; color: #000 !important; caret-color: #000; }
            .auth-card input::placeholder { color: #64748b !important; }
            .auth-card input + button, .auth-card input ~ button { background: #fff !important; color: #000 !important; }
            .auth-card input + button *, .auth-card input ~ button *, .auth-card button[type=button] svg, .auth-card button[type=button] svg * { color: #000 !important; }
            .auth-card .auth-link { color: #7dd3fc !important; text-decoration: underline; }
            .auth-card .auth-link:hover { color: #fff !important; }
            .auth-card .auth-btn { background: linear-gradient(135deg, #0ea5e9, #2563eb) !important; color: #fff !important; border: 0 !important; }
            .auth-card .auth-btn:hover { filter: brightness(1.12); }
            .auth-card .auth-btn-outline { display: flex; align-items: center; justify-content: center; width: 100%; margin-top: .75rem; padding: .55rem 1rem; border: 1px solid #7dd3fc; border-radius: .375rem; color: #e0f2fe !important; font-weight: 600; font-size: .875rem; text-decoration: none; }
            .auth-card .auth-btn-outline:hover { background: rgba(125, 211, 252, .14); }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 site-bg">
            <div class="mb-3">
                <x-language-switcher />
            </div>

            <div>
                <a href="/">
                    <x-brand-logo img-class="h-20 w-auto" text-class="text-3xl font-bold" />
                </a>
            </div>

            <div class="auth-card w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
