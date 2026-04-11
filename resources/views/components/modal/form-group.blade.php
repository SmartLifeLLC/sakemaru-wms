@props([
    'label',
])

<div>
    <label class="block text-xs font-medium text-slate-600 dark:text-gray-400 mb-1">{{ $label }}</label>
    {{ $slot }}
</div>
