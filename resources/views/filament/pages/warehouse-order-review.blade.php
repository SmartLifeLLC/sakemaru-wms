<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">出力条件</x-slot>
        <form wire:submit="export" class="space-y-6">
            {{ $this->form }}
            <div class="flex justify-end">
                <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray" wire:loading.attr="disabled" wire:target="export">
                    <span wire:loading.remove wire:target="export">Excel出力</span>
                    <span wire:loading wire:target="export">出力中…</span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">出力内容</x-slot>
        <div class="space-y-3 text-sm text-gray-600 dark:text-gray-300">
            <p>商品情報、在庫、当日営業出荷数、当日店舗出荷数、販売実績（1週・2週・3週・前月）、入荷予定数、商品発注管理の備考を出力します。</p>
            <p>並び順：発注先CD → 仕入先CD → 備考（空白を最後にして昇順） → 単品CD</p>
            <p>在庫は出力時点の現在庫です。基準日には当日出荷と販売実績の集計基準日を指定してください。入荷予定数は、基準日以降に予定のある日付のうち先頭7日分を表示します。</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
