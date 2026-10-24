@props(['value' => "''", 'min' => "''", 'max' => null, 'label' => '入荷予定日', 'model' => null, 'reset' => '0'])
@php
    if ($model !== null) $value = '$wire.entangle('.json_encode($model).').live';
    $max ??= json_encode(\App\Support\OrderArrivalDate::maximum());
@endphp
<div x-data="HqOrderInputs.dateInput({{ $value }}, {{ $min }}, {{ $max }})"
    @if ($model === null) x-effect="syncExternal({{ $value }}, {{ $reset }})" @endif
    {{ $attributes->class(['relative inline-block']) }}>
    <div class="flex items-center gap-1">
        <input type="text" inputmode="numeric" autocomplete="off" aria-label="{{ $label }}" placeholder="日／月日／年月日"
            x-model="display" x-on:input="edit()" x-on:focus="$event.target.select()" x-on:blur="commit()" x-on:keydown.enter.prevent.stop="commit()"
            x-bind:aria-invalid="!!error" class="w-32 rounded-md border-slate-300 px-2 py-1 text-xs font-mono dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
        <button type="button" x-on:click="openPicker()" aria-label="カレンダーを開く" class="text-slate-500 dark:text-gray-300">▦</button>
        <input type="date" x-ref="picker" x-bind:value="value" x-bind:min="min" x-bind:max="max" x-on:change="pick($event)" tabindex="-1" aria-hidden="true" class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0">
    </div>
    <p x-cloak x-show="error" x-text="error" role="alert" class="max-w-64 whitespace-normal text-xs text-danger-600 dark:text-danger-400"></p>
</div>
