@php $current = app()->getLocale(); @endphp

<form method="POST" action="{{ route('locale.update') }}" class="inline-flex items-center gap-2" aria-label="{{ __('Language') }}">
    @csrf
    @foreach (\App\Http\Middleware\SetLocale::LOCALES as $code => $name)
        <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" title="{{ $name }}"
                @if ($current === $code) aria-current="true" @endif
                class="inline-flex items-center rounded p-0.5 transition {{ $current === $code ? 'ring-2 ring-indigo-600' : 'opacity-60 ring-1 ring-gray-200 hover:opacity-100' }}">
            <span class="sr-only">{{ $name }}</span>

            @if ($code === 'km')
                {{-- Cambodia --}}
                <svg viewBox="0 0 60 40" class="h-5 w-auto rounded-sm" aria-hidden="true" focusable="false">
                    <rect width="60" height="40" fill="#032EA1"/>
                    <rect y="10" width="60" height="20" fill="#E00025"/>
                    <g fill="#fff">
                        <polygon points="27,26 27,20 28.5,15 30,10 31.5,15 33,20 33,26"/>
                        <polygon points="21,26 21,21 23,17 25,21 25,26"/>
                        <polygon points="35,26 35,21 37,17 39,21 39,26"/>
                        <rect x="18" y="26" width="24" height="2.5"/>
                    </g>
                </svg>
            @else
                {{-- United Kingdom --}}
                <svg viewBox="0 0 60 40" class="h-5 w-auto rounded-sm" aria-hidden="true" focusable="false">
                    <rect width="60" height="40" fill="#012169"/>
                    <path d="M0 0L60 40M60 0L0 40" stroke="#fff" stroke-width="8"/>
                    <path d="M0 0L60 40M60 0L0 40" stroke="#C8102E" stroke-width="3"/>
                    <path d="M30 0V40M0 20H60" stroke="#fff" stroke-width="12"/>
                    <path d="M30 0V40M0 20H60" stroke="#C8102E" stroke-width="7"/>
                </svg>
            @endif
        </button>
    @endforeach
</form>