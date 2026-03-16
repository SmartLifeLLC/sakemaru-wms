@props([
    'size' => 'lg',
    'alpineVar' => 'open',
    'zIndex' => '100',
])

@php
    $sizeStyles = [
        'sm' => '24rem',
        'md' => '28rem',
        'lg' => '32rem',
        'xl' => '36rem',
        '2xl' => '42rem',
        '3xl' => '48rem',
        '6xl' => '72rem',
        '7xl' => '80rem',
    ];
    $maxWidth = $sizeStyles[$size] ?? '32rem';
@endphp

{{-- Backdrop --}}
<div
    class="fixed inset-0 flex items-center justify-center bg-black/40 dark:bg-black/60"
    style="z-index: {{ $zIndex }};"
    x-show="{{ $alpineVar }}"
    x-cloak
    @click.self="{{ $alpineVar }} = false"
    @keydown.escape.window="{{ $alpineVar }} = false"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
>
    {{-- Modal Box --}}
    <div
        class="bg-white dark:bg-gray-800 rounded-lg shadow-xl mx-4 flex flex-col pointer-events-auto"
        style="max-width: {{ $maxWidth }}; max-height: 80vh; width: 100%;"
        @click.stop
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
    >
        {{ $slot }}
    </div>
</div>
