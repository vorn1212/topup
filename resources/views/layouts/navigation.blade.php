@php
    // One list for the desktop menu and the phone menu, so they can never get out of step.
    $links = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard'],
        ['label' => 'Games', 'route' => 'games.index', 'active' => 'games.*'],
        ['label' => 'Wallet', 'route' => 'wallet.index', 'active' => 'wallet.*'],
        ['label' => 'Orders', 'route' => 'orders.index', 'active' => 'orders.*'],
        ['label' => 'Contact', 'route' => 'contact', 'active' => 'contact'],
        ['label' => 'Notifications', 'route' => 'notifications.index', 'active' => 'notifications.*'],
    ];

    // The menu must never break a page: if the count cannot be read, simply show no badge.
    try {
        $unread = auth()->user()->unreadNotifications()->count();
    } catch (\Throwable $e) {
        $unread = 0;
    }

    $isStaff = auth()->user()->isStaff();
@endphp

<nav x-data="{ open: false }" class="bg-white site-nav border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}">
                        <x-brand-logo img-class="h-9 w-auto" text-class="text-lg font-bold" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    @foreach ($links as $link)
                        <x-nav-link class="nav-ink-link" :href="route($link['route'])" :active="request()->routeIs($link['active'])">
                            {{ __($link['label']) }}
                            @if ($link['route'] === 'notifications.index' && $unread > 0)
                                <span class="ms-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                            @endif
                        </x-nav-link>
                    @endforeach

                    @if ($isStaff)
                        <x-nav-link class="nav-ink-link" :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">
                            {{ __('Admin') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-3">
                @unless (request()->routeIs('admin.*'))
                    <x-language-switcher />
                @endunless

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="nav-ink-link inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-transparent hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="nav-ink-link inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden bg-white sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @foreach ($links as $link)
                <x-responsive-nav-link :href="route($link['route'])" :active="request()->routeIs($link['active'])">
                    {{ __($link['label']) }}
                    @if ($link['route'] === 'notifications.index' && $unread > 0)
                        <span class="ms-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                    @endif
                </x-responsive-nav-link>
            @endforeach

            @if ($isStaff)
                <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">
                    {{ __('Admin') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            @unless (request()->routeIs('admin.*'))
                <div class="px-4 pb-3">
                    <x-language-switcher />
                </div>
            @endunless

            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
