@props([
    'justify' => 'end',
])

<div class="flex items-center justify-{{ $justify }} px-4 py-3 border-t border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-900 rounded-b-lg">
    {{ $slot }}
</div>
