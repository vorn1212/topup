{{-- Telegram and Facebook buttons. Only the links that the admin has set are shown. --}}
@inject('site', 'App\Services\SiteSettings')
@php($big = $big ?? false)

@if ($site->hasContact())
    <div class="flex flex-wrap items-center justify-center gap-3">
        @if ($site->telegramUrl())
            <a href="{{ $site->telegramUrl() }}" target="_blank" rel="noopener noreferrer nofollow"
               class="inline-flex items-center gap-2 rounded-md bg-sky-500 font-semibold text-white shadow hover:bg-sky-400 {{ $big ? 'px-6 py-3 text-base' : 'px-4 py-2 text-sm' }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
                {{ __('Telegram') }}
            </a>
        @endif

        @if ($site->facebookUrl())
            <a href="{{ $site->facebookUrl() }}" target="_blank" rel="noopener noreferrer nofollow"
               class="inline-flex items-center gap-2 rounded-md bg-blue-600 font-semibold text-white shadow hover:bg-blue-500 {{ $big ? 'px-6 py-3 text-base' : 'px-4 py-2 text-sm' }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.47h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>
                {{ __('Facebook Page') }}
            </a>
        @endif
    </div>
@endif
