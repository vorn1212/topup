@php
    $fields = [
        'primary' => ['Button and link colour', 'Buttons, links and the active menu item.'],
        'page' => ['Page background', 'The background behind all customer pages.'],
        'nav' => ['Menu bar', 'The top bar with the logo and menu.'],
        'header' => ['Page title bar', 'The bar under the menu that shows the page title (for example "Dashboard").'],
        'panel' => ['Boxes and panels', 'The white boxes: dashboard panels, tables, drop-down menus and buttons.'],
        'footer' => ['Footer', 'The bar at the bottom of the public pages.'],
        'card' => ['Game card', 'The background of each game card.'],
        'accent' => ['Game card picture area', 'Behind the game picture (or its first letter).'],
    ];

    $state = [
        'name' => old('site_name', $settings->siteName()),
        'c' => collect(\App\Services\SiteSettings::COLOR_KEYS)
            ->mapWithKeys(fn ($key) => [$key => old('color_'.$key, $settings->color($key))])
            ->all(),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Site settings</h2>
    </x-slot>

    <x-admin-nav />

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <x-flash />

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                    <div class="font-semibold">Please fix these problems:</div>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data"
                  x-data="{ ...@js($state), ink(h) { const r = parseInt(h.slice(1, 3), 16), g = parseInt(h.slice(3, 5), 16), b = parseInt(h.slice(5, 7), 16); return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#111827' : '#ffffff'; } }"
                  class="space-y-6">
                @csrf

                {{-- Name and pictures --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-5">
                    <h3 class="font-semibold text-gray-800">Name and pictures</h3>

                    <div>
                        <label for="site_name" class="block text-sm font-medium text-gray-700">Website name</label>
                        <input id="site_name" name="site_name" type="text" maxlength="60" required x-model="name"
                               class="mt-1 block w-full max-w-md rounded-md border-gray-300 shadow-sm">
                        <p class="mt-1 text-xs text-gray-500">Shown in the menu bar, the browser tab, the footer and e-mails. Leave nothing blank. Default: the APP_NAME in .env.</p>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label for="logo" class="block text-sm font-medium text-gray-700">Logo</label>
                            <div class="mt-2 flex h-16 items-center rounded-md border border-dashed border-gray-300 bg-gray-50 px-3">
                                @if ($settings->logoUrl())
                                    <img src="{{ $settings->logoUrl() }}" alt="Current logo" class="h-12 w-auto object-contain">
                                @else
                                    <span class="text-sm text-gray-400">No logo: the website name is shown instead.</span>
                                @endif
                            </div>
                            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"
                                   class="mt-2 block w-full text-sm text-gray-700">
                            <p class="mt-1 text-xs text-gray-500">PNG, JPG or WebP, up to 1 MB and 2000 x 2000 px. A transparent PNG looks best.</p>
                            @if ($settings->logoUrl())
                                <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300"> Remove the current logo
                                </label>
                            @endif
                        </div>

                        <div>
                            <label for="favicon" class="block text-sm font-medium text-gray-700">Browser tab icon (favicon)</label>
                            <div class="mt-2 flex h-16 items-center rounded-md border border-dashed border-gray-300 bg-gray-50 px-3">
                                @if ($settings->faviconUrl())
                                    <img src="{{ $settings->faviconUrl() }}" alt="Current favicon" class="h-8 w-8 object-contain">
                                @else
                                    <span class="text-sm text-gray-400">No icon set.</span>
                                @endif
                            </div>
                            <input id="favicon" name="favicon" type="file" accept="image/png,image/x-icon,.ico"
                                   class="mt-2 block w-full text-sm text-gray-700">
                            <p class="mt-1 text-xs text-gray-500">PNG or ICO, square, up to 256 KB. SVG is not allowed for safety.</p>
                            @if ($settings->faviconUrl())
                                <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_favicon" value="1" class="rounded border-gray-300"> Remove the current icon
                                </label>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Home page banner --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                    <h3 class="font-semibold text-gray-800">Home page banner character</h3>

                    <div class="flex h-44 items-center justify-center rounded-md border border-dashed border-gray-300 bg-slate-900 p-2">
                        @if ($settings->bannerUrl())
                            <img src="{{ $settings->bannerUrl() }}" alt="Current banner picture" class="h-40 w-auto object-contain">
                        @else
                            <div class="h-40 w-40">@include('site._banner-character')</div>
                        @endif
                    </div>
                    <input id="banner" name="banner" type="file" accept="image/png,image/jpeg,image/webp"
                           class="block w-full text-sm text-gray-700">
                    <p class="text-xs text-gray-500">PNG, JPG or WebP, up to 2 MB and 2400 x 2400 px. A transparent PNG of a character looks best. Without an upload, the built-in boy gamer is shown.</p>
                    @if ($settings->bannerUrl())
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="remove_banner" value="1" class="rounded border-gray-300"> Remove my picture and show the built-in boy gamer
                        </label>
                    @endif
                </div>

                {{-- Home page banner slider --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                    <h3 class="font-semibold text-gray-800">Home page banner slider</h3>
                    <p class="text-xs text-gray-500">Up to {{ \App\Services\SiteSettings::SLIDE_COUNT }} wide pictures that switch automatically at the top of the home page. Best size: 1600 x 600 px (PNG, JPG or WebP, up to 3 MB). The link is optional: a full https:// address, or a path on this site such as /games/mobile-legends-global. With no pictures, the slider is hidden.</p>

                    @for ($slot = 1; $slot <= \App\Services\SiteSettings::SLIDE_COUNT; $slot++)
                        <div class="rounded-md border border-gray-200 p-3 space-y-2">
                            <p class="text-sm font-medium text-gray-700">Slide {{ $slot }}</p>

                            @if ($settings->slideUrl($slot))
                                <img src="{{ $settings->slideUrl($slot) }}" alt="Slide {{ $slot }}" class="h-24 w-auto max-w-full rounded border border-gray-200 object-cover">
                            @endif

                            <input name="slide{{ $slot }}" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm text-gray-700">
                            @error('slide'.$slot)
                                <p class="text-xs text-red-600">{{ $message }}</p>
                            @enderror

                            <input name="slide{{ $slot }}_link" type="text" maxlength="300"
                                   value="{{ old('slide'.$slot.'_link', $settings->value('slide'.$slot.'_link')) }}"
                                   placeholder="Link when clicked (optional)"
                                   class="block w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @error('slide'.$slot.'_link')
                                <p class="text-xs text-red-600">{{ $message }}</p>
                            @enderror

                            @if ($settings->slideUrl($slot))
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="remove_slide{{ $slot }}" value="1" class="rounded border-gray-300"> Remove this picture
                                </label>
                            @endif
                        </div>
                    @endfor
                </div>
                {{-- Contact links --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-4">
                    <h3 class="font-semibold text-gray-800">Contact Us links</h3>

                    <div>
                        <label for="telegram_url" class="block text-sm font-medium text-gray-700">Telegram link</label>
                        <input id="telegram_url" name="telegram_url" type="url" maxlength="200"
                               value="{{ old('telegram_url', $settings->value('telegram_url')) }}"
                               placeholder="{{ \App\Services\SiteSettings::EXAMPLE_TELEGRAM }}"
                               class="mt-1 block w-full max-w-xl rounded-md border-gray-300 shadow-sm">
                    </div>

                    <div>
                        <label for="facebook_url" class="block text-sm font-medium text-gray-700">Facebook page link</label>
                        <input id="facebook_url" name="facebook_url" type="url" maxlength="250"
                               value="{{ old('facebook_url', $settings->value('facebook_url')) }}"
                               placeholder="{{ \App\Services\SiteSettings::EXAMPLE_FACEBOOK }}"
                               class="mt-1 block w-full max-w-xl rounded-md border-gray-300 shadow-sm">
                    </div>

                    <p class="text-xs text-gray-500">
                        The grey text is only an example. A button shows on the website (Contact page, footer) only after you save a real link.
                        Leave a box empty to hide that button. Telegram links must start with https://t.me/ and Facebook links with https://www.facebook.com/.
                    </p>
                </div>

                {{-- Colours --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-5">
                    <h3 class="font-semibold text-gray-800">Colours</h3>

                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach ($fields as $key => [$label, $hint])
                            <div class="flex items-start gap-3">
                                <input type="color" id="color_{{ $key }}" name="color_{{ $key }}" x-model="c.{{ $key }}"
                                       class="h-10 w-14 shrink-0 cursor-pointer rounded border border-gray-300 bg-white p-1">
                                <div>
                                    <label for="color_{{ $key }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                                    <div class="text-xs text-gray-500">{{ $hint }}</div>
                                    <div class="mt-0.5 font-mono text-xs text-gray-400" x-text="c.{{ $key }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-500">Text colour (black or white) is chosen automatically so it stays easy to read on the colour you pick.</p>
                </div>

                {{-- Live preview (nothing is saved until you press Save) --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-5 space-y-3">
                    <h3 class="font-semibold text-gray-800">Preview</h3>

                    <div class="overflow-hidden rounded-lg border border-gray-200" :style="'background-color:' + c.page">
                        <div class="flex items-center justify-between px-4 py-3" :style="'background-color:' + c.nav + ';color:' + ink(c.nav)">
                            <span class="font-bold" x-text="name || 'Website name'"></span>
                            <span class="text-sm">Games &nbsp; Wallet &nbsp; Orders</span>
                        </div>

                        <div class="px-4 py-3 text-sm font-semibold" :style="'background-color:' + c.header + ';color:' + ink(c.header)">Dashboard</div>

                        <div class="flex flex-wrap items-start gap-4 p-4">
                            <div class="w-44 rounded-xl p-3 text-sm shadow-sm ring-1 ring-gray-200" :style="'background-color:' + c.panel + ';color:' + ink(c.panel)">
                                <div class="font-semibold">Wallet balance</div>
                                <div class="font-bold" :style="'color:' + c.primary">$0.00</div>
                            </div>

                            <div class="w-44 overflow-hidden rounded-xl shadow-sm ring-1 ring-gray-200" :style="'background-color:' + c.card">
                                <div class="flex h-24 items-center justify-center text-3xl font-bold" :style="'background-color:' + c.accent + ';color:' + c.primary">G</div>
                                <div class="p-3" :style="'color:' + ink(c.card)">
                                    <div class="font-semibold">Sample game</div>
                                    <div class="text-sm" style="opacity:.7">From $0.99</div>
                                </div>
                            </div>

                            <button type="button" class="rounded-md px-4 py-2 text-sm font-medium"
                                    :style="'background-color:' + c.primary + ';color:' + ink(c.primary)">Top up now</button>
                        </div>

                        <div class="px-4 py-3 text-center text-xs" :style="'background-color:' + c.footer + ';color:' + ink(c.footer)">
                            &copy; <span x-text="name || 'Website name'"></span>
                        </div>
                    </div>
                </div>

                <div>
                    <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save settings</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.settings.reset') }}"
                  onsubmit="return confirm('Reset the name, logo, icon and all colours to the defaults?');">
                @csrf
                <button type="submit" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Reset to defaults</button>
            </form>

            <p class="text-xs text-gray-500">
                Only the super admin can change these settings. Every change is written to the audit log.
                Images are saved in <span class="font-mono">storage/app/public/branding</span>; if a picture does not show, run <span class="font-mono">php artisan storage:link</span> once.
            </p>
        </div>
    </div>
</x-app-layout>
