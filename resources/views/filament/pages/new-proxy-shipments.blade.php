<x-filament-panels::page>
    <div x-data="{ printUrl: null }" @proxy-print-link.window="printUrl = $event.detail.url">
        <div x-cloak x-show="printUrl" class="mb-3 text-sm">
            印刷画面を別タブで開けませんでした。
            <a :href="printUrl" target="_blank" rel="noopener" class="underline">印刷画面を開く</a>
        </div>
        <p class="text-sm text-gray-500 mb-3">完了後も依頼を表示します。取消は依頼の無効化だけを行い、元伝票を変更しません。</p>
        {{ $this->content }}
    </div>
</x-filament-panels::page>
