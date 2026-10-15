@props(['status'])

@php
    $map = [
        'completed'  => 'bg-green-100 text-green-800',
        'paid'       => 'bg-green-100 text-green-800',
        'active'     => 'bg-green-100 text-green-800',
        'pending'    => 'bg-yellow-100 text-yellow-800',
        'processing' => 'bg-blue-100 text-blue-800',
        'refunding'  => 'bg-blue-100 text-blue-800',
        'refunded'   => 'bg-purple-100 text-purple-800',
        'failed'     => 'bg-red-100 text-red-800',
        'suspended'  => 'bg-red-100 text-red-800',
        'banned'     => 'bg-red-100 text-red-800',
        'cancelled'  => 'bg-gray-100 text-gray-800',
    ];
    $class = $map[strtolower((string) $status)] ?? 'bg-gray-100 text-gray-800';
@endphp

<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $class }}">{{ __(ucfirst((string) $status)) }}</span>