@props(['value' => "''"])
<div x-data="{ expanded: false }" class="w-60 whitespace-normal text-left text-xs leading-5 text-slate-700 dark:text-slate-200">
    <div x-text="{{ $value }} || ''" x-bind:style="expanded ? 'white-space: pre-wrap' : 'display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; white-space: pre-wrap'"></div>
    <button type="button" x-show="String({{ $value }} || '').length > 36 || String({{ $value }} || '').split('\n').length > 2" x-on:click.stop="expanded = !expanded" x-text="expanded ? '折りたたむ' : '全文を表示'" class="text-primary-600 underline dark:text-primary-400"></button>
</div>
