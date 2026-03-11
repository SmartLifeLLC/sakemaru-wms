@props([
    'icon',
    'title',
    'alpineVar' => 'open',
])

<div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-900 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-gray-200">
        <i class="fa fa-{{ $icon }}"></i>
        {{ $title }}
    </h3>
    <button @click="{{ $alpineVar }} = false" class="text-slate-400 dark:text-gray-500 hover:text-slate-600 dark:hover:text-gray-300">
        <i class="fa fa-times"></i>
    </button>
</div>
