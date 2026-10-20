@props(['url' => null])

{{-- A package picture: the uploaded icon, or the built-in diamond when nothing was uploaded. --}}
@if ($url)
    <img src="{{ $url }}" alt="" loading="lazy" {{ $attributes->merge(['class' => 'object-contain']) }}>
@else
    <svg data-package-icon="default" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes }}>
        <polygon points="32,6 56,24 32,58 8,24" fill="#38bdf8"/>
        <polygon points="8,24 32,24 32,58" fill="#0ea5e9"/>
        <polygon points="56,24 32,24 32,58" fill="#0284c7"/>
        <polygon points="20,10 44,10 56,24 8,24" fill="#7dd3fc"/>
        <polygon points="32,6 44,10 32,24 20,10" fill="#bae6fd"/>
        <path d="M15 27 L29 27 L29 45 Z" fill="#fff" opacity=".25"/>
    </svg>
@endif
