<x-filament-panels::page>
    {{-- 発注確定処理の進捗表示 --}}
    @if($this->activeJobId)
        <div
            x-data="{
                progress: 0,
                status: 'pending',
                message: '処理を開始しています...',
                polling: true,
                init() {
                    this.pollProgress()
                },
                async pollProgress() {
                    while (this.polling) {
                        try {
                            const data = await $wire.getProgressData()
                            if (data) {
                                this.progress = data.progress || 0
                                this.status = data.status || 'pending'
                                this.message = data.message || ''

                                if (data.status === 'completed' || data.status === 'failed') {
                                    this.polling = false
                                    setTimeout(() => {
                                        $wire.$refresh()
                                    }, 1000)
                                }
                            } else {
                                this.polling = false
                                $wire.$refresh()
                            }
                        } catch (e) {
                            console.error('Progress polling error:', e)
                        }
                        await new Promise(resolve => setTimeout(resolve, 2000))
                    }
                }
            }"
            class="mb-4"
        >
            <div class="rounded-lg bg-white dark:bg-gray-800 shadow p-4">
                <div class="flex items-center gap-3 mb-3">
                    <div
                        x-show="status === 'processing' || status === 'pending'"
                        class="animate-spin rounded-full h-5 w-5 border-b-2 border-primary-500"
                    ></div>
                    <template x-if="status === 'completed'">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-success-500" />
                    </template>
                    <template x-if="status === 'failed'">
                        <x-heroicon-o-x-circle class="h-5 w-5 text-danger-500" />
                    </template>
                    <span class="font-medium text-gray-700 dark:text-gray-300">発注確定処理</span>
                    <span
                        x-text="status === 'pending' ? '待機中' : status === 'processing' ? '処理中' : status === 'completed' ? '完了' : '失敗'"
                        :class="{
                            'bg-gray-100 text-gray-700': status === 'pending',
                            'bg-blue-100 text-blue-700': status === 'processing',
                            'bg-green-100 text-green-700': status === 'completed',
                            'bg-red-100 text-red-700': status === 'failed'
                        }"
                        class="px-2 py-0.5 rounded text-sm"
                    ></span>
                </div>

                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 mb-2">
                    <div
                        class="h-3 rounded-full transition-all duration-500"
                        :class="{
                            'bg-primary-500': status === 'processing' || status === 'pending',
                            'bg-success-500': status === 'completed',
                            'bg-danger-500': status === 'failed'
                        }"
                        :style="'width: ' + progress + '%'"
                    ></div>
                </div>

                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                    <span x-text="message"></span>
                    <span x-text="progress + '%'"></span>
                </div>
            </div>
        </div>
    @endif

    {{-- 処理中はプログレスバーのみ表示（テーブルを非表示） --}}
    @if(!$this->activeJobId)
        <div wire:key="confirmation-table-{{ $this->confirmationTab }}-{{ $this->activePresetView }}">
            <div class="-mt-1 mb-1">
                <x-advanced-tables::favorites-bar />
            </div>

            {{ $this->table }}
        </div>
    @else
        <div class="p-8 text-center text-gray-500 dark:text-gray-400">
            <p class="text-lg">処理が完了するまでお待ちください...</p>
            <p class="text-sm mt-2">このページは処理完了後に自動的に更新されます。</p>
        </div>
    @endif
</x-filament-panels::page>
