<x-filament-panels::page>
    {{-- メインタブ切り替え（本番ファイル / テストファイル） --}}
    <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="flex -mb-px" aria-label="Tabs">
            <button
                wire:click="setFileTypeTab('production')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap',
                    'border-primary-500 text-primary-600 dark:text-primary-400' => $this->fileTypeTab === 'production',
                    'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' => $this->fileTypeTab !== 'production',
                ])
            >
                本番ファイル
            </button>
            <button
                wire:click="setFileTypeTab('test')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap',
                    'border-info-500 text-info-600 dark:text-info-400' => $this->fileTypeTab === 'test',
                    'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' => $this->fileTypeTab !== 'test',
                ])
            >
                テストファイル
            </button>
        </nav>
    </div>

    {{-- テストファイルタブの説明 --}}
    @if($this->fileTypeTab === 'test')
        <div class="p-3 bg-info-50 dark:bg-info-900/20 border border-info-200 dark:border-info-800 rounded-lg">
            <div class="flex items-center gap-2 text-info-700 dark:text-info-300 text-sm">
                <x-heroicon-o-information-circle class="w-5 h-5 flex-shrink-0" />
                <span>テストファイルは発注確定前のプレビュー用です。JX送信には使用できません。</span>
            </div>
        </div>
    @endif

    <div wire:key="data-files-table-{{ $this->fileTypeTab }}-{{ $this->activePresetView }}">
        <div class="mb-6 -mt-6">
            <x-advanced-tables::favorites-bar />
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
