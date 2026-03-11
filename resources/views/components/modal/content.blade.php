@props([
    'padding' => '4',
])

<div class="p-{{ $padding }} overflow-y-auto flex-1">
    {{ $slot }}
</div>
