@props([
    'alpineVar' => 'open',
    'title',
    'message',
    'icon' => 'exclamation-triangle',
    'iconColor' => 'red',
    'confirmLabel' => '実行',
    'confirmColor' => 'red',
    'zIndex' => '120',
])

@php
    $iconBgClasses = [
        'red' => 'bg-red-50 dark:bg-red-900/30',
        'orange' => 'bg-orange-50 dark:bg-orange-900/30',
        'blue' => 'bg-blue-50 dark:bg-blue-900/30',
    ];
    $iconTextClasses = [
        'red' => 'text-red-500',
        'orange' => 'text-orange-500',
        'blue' => 'text-blue-500',
    ];
    $btnClasses = [
        'red' => 'bg-red-600 hover:bg-red-700',
        'orange' => 'bg-orange-600 hover:bg-orange-700',
        'blue' => 'bg-blue-600 hover:bg-blue-700',
    ];
@endphp

<x-modal.container size="sm" :alpine-var="$alpineVar" :z-index="$zIndex">
    {{-- Content --}}
    <div class="p-6 text-center">
        <div class="mx-auto w-12 h-12 rounded-full {{ $iconBgClasses[$iconColor] ?? $iconBgClasses['red'] }} flex items-center justify-center mb-4">
            <i class="fa fa-{{ $icon }} {{ $iconTextClasses[$iconColor] ?? $iconTextClasses['red'] }} text-xl"></i>
        </div>
        <h3 class="text-sm font-bold text-slate-800 dark:text-gray-200 mb-2">{{ $title }}</h3>
        <p class="text-xs text-slate-500 dark:text-gray-400">{{ $message }}</p>
    </div>

    {{-- Footer --}}
    <x-modal.footer justify="center">
        <div class="flex items-center gap-2">
            <button @click="{{ $alpineVar }} = false"
                    class="px-4 py-1.5 text-xs font-medium text-slate-600 dark:text-gray-400 bg-slate-100 dark:bg-gray-700 rounded hover:bg-slate-200 dark:hover:bg-gray-600">
                キャンセル
            </button>
            {{ $slot }}
        </div>
    </x-modal.footer>
</x-modal.container>
