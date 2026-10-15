@props(['route', 'label', 'primary' => false])

@php
    $base = 'inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium shadow-sm transition';
    $style = $primary
        ? 'bg-indigo-600 text-white hover:bg-indigo-500'
        : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50';
@endphp

@if (Route::has($route))
    <a href="{{ route($route) }}" class="{{ $base }} {{ $style }}">{{ __($label) }}</a>
@else
    <span class="{{ $base }} {{ $style }} opacity-50 cursor-not-allowed" title="{{ __('Available in a later phase') }}">{{ __($label) }}</span>
@endif