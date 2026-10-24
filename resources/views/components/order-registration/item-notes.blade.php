@props(['item' => "''", 'contractor' => "''"])
<div
    x-data="{
        expanded: false,
        overflowing: false,
        observer: null,
        measure() {
            if (!this.expanded) this.overflowing = this.$refs.preview.scrollWidth > this.$refs.preview.clientWidth;
        },
        init() {
            this.$nextTick(() => {
                if (!this.$el.isConnected) return;
                this.observer = new ResizeObserver(() => this.measure());
                this.observer.observe(this.$refs.preview);
            });
        },
        destroy() { this.observer?.disconnect(); },
    }"
    x-effect="{{ $item }}; {{ $contractor }}; if (!expanded) $nextTick(() => measure())"
    data-hq-item-notes
    class="flex w-0 min-w-full items-start gap-2 text-left text-xs leading-4 text-slate-600 dark:text-slate-300"
>
    <div x-ref="preview" class="min-w-0 flex-1" x-bind:class="expanded ? 'whitespace-normal' : 'truncate'">
        <span x-show="String({{ $item }} || '').trim()" x-bind:class="expanded ? 'block' : 'mr-3'"><span class="font-semibold" title="商品発注先マスタの備考">商品備考：</span><span x-text="{{ $item }} || ''" x-bind:class="{ 'whitespace-pre-wrap break-words': expanded }"></span></span>
        <span x-show="String({{ $contractor }} || '').trim()" x-bind:class="{ 'block': expanded }"><span class="font-semibold" title="発注先マスタの発注ロット・発注条件">発注先備考：</span><span x-text="{{ $contractor }} || ''" x-bind:class="{ 'whitespace-pre-wrap break-words': expanded }"></span></span>
    </div>
    <button type="button" x-cloak x-show="expanded || overflowing" x-on:click.stop="expanded = !expanded" x-text="expanded ? '折りたたむ' : '全文を表示'" class="shrink-0 text-primary-600 underline dark:text-primary-400"></button>
</div>
