@props(['id', 'name' => 'password', 'autocomplete' => 'current-password'])

{{-- A password box with an eye button that shows or hides the characters. Needs no extra package: Alpine ships with Breeze. --}}
<div x-data="{ show: false }" class="relative mt-1">
    <input id="{{ $id }}" name="{{ $name }}" type="password" x-bind:type="show ? 'text' : 'password'"
           autocomplete="{{ $autocomplete }}"
           {{ $attributes->merge(['class' => 'block w-full pe-11 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>

    <button type="button" x-on:click="show = ! show"
            x-bind:aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))"
            x-bind:title="show ? @js(__('Hide password')) : @js(__('Show password'))"
            x-bind:aria-pressed="show.toString()"
            class="absolute inset-y-0 end-0 flex items-center rounded-e-md px-3 text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
        {{-- eye: the password is hidden, click to show --}}
        <svg x-show="! show" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.58 3.01 9.96 7.18a1 1 0 010 .64C20.58 16.49 16.64 19.5 12 19.5s-8.58-3.01-9.96-7.18z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        {{-- eye with a line: the password is visible, click to hide --}}
        <svg x-show="show" style="display: none" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.22A10.48 10.48 0 002.04 11.68a1 1 0 000 .64C3.42 16.49 7.36 19.5 12 19.5c1.99 0 3.84-.55 5.43-1.5M6.23 6.23A10.45 10.45 0 0112 4.5c4.64 0 8.58 3.01 9.96 7.18a1 1 0 010 .64 10.5 10.5 0 01-4.29 5.1M6.23 6.23L3 3m3.23 3.23l3.65 3.65m7.89 7.89L21 21m-3.23-3.23l-3.65-3.65m0 0a3 3 0 10-4.24-4.24m4.24 4.24L9.88 9.88"/>
        </svg>
    </button>
</div>