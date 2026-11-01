<x-filament-panels::page>
    <div x-data="{ printUrl: null }" @proxy-print-link.window="printUrl = $event.detail.url">
        <div x-cloak x-show="printUrl" class="mb-3 text-sm">
            印刷画面を別タブで開けませんでした。
            <a :href="printUrl" target="_blank" rel="noopener" class="underline">印刷画面を開く</a>
        </div>
        <p class="text-sm text-gray-500 mb-3">選択中の倉庫の未完了と当日出荷分の完了を表示します。過去の完了は「完了の表示」フィルタで確認できます。</p>
        {{ $this->content }}
    </div>
</x-filament-panels::page>
