@props([
    'imgClass' => 'h-9 w-auto',
    'textClass' => 'text-lg font-bold',
])

@php
    $brand = app(\App\Services\SiteSettings::class);
    $logo = $brand->logoUrl();
@endphp

@if ($logo)
    <img src="{{ $logo }}" alt="{{ $brand->siteName() }}" {{ $attributes->merge(['class' => $imgClass.' block object-contain']) }}>
@else
    <span {{ $attributes->merge(['class' => $textClass.' site-brand-text']) }}>{{ $brand->siteName() }}</span>
@endif
