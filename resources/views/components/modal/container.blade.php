@props([
    'size' => 'lg',
    'alpineVar' => 'open',
    'zIndex' => '100',
])

@php
    $sizeClasses = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        '3xl' => 'max-w-3xl',
        '6xl' => 'max-w-6xl',
    ];
    $sizeClass = $sizeClasses[$size] ?? 'max-w-lg';
@endphp

{{-- Backdrop --}}
<div
    class="fixed inset-0 z-[{{ $zIndex }}] flex items-center justify-center bg-black/40 dark:bg-black/60"
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
        class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full {{ $sizeClass }} mx-4 max-h-[80vh] flex flex-col pointer-events-auto"
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
