<x-filament-panels::page>
    {{-- メインタブ切り替え（発注確定待ち / 移動確定待ち） --}}
    <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="flex -mb-px" aria-label="Tabs">
            <button
                wire:click="setConfirmationTab('order')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap',
                    'border-primary-500 text-primary-600 dark:text-primary-400' => $this->confirmationTab === 'order',
                    'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' => $this->confirmationTab !== 'order',
                ])
            >
                発注確定待ち
                <span @class([
                    'ml-2 px-2 py-0.5 text-xs rounded-full',
                    'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300' => $this->confirmationTab === 'order',
                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $this->confirmationTab !== 'order',
                ])>{{ $this->getOrderApprovedCount() }}</span>
            </button>
            <button
                wire:click="setConfirmationTab('transfer')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap',
                    'border-primary-500 text-primary-600 dark:text-primary-400' => $this->confirmationTab === 'transfer',
                    'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' => $this->confirmationTab !== 'transfer',
                ])
            >
                移動確定待ち
                <span @class([
                    'ml-2 px-2 py-0.5 text-xs rounded-full',
                    'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300' => $this->confirmationTab === 'transfer',
                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $this->confirmationTab !== 'transfer',
                ])>{{ $this->getTransferApprovedCount() }}</span>
            </button>
        </nav>
    </div>

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

    {{-- テストデータ生成の進捗表示 --}}
    @if($this->activeTestJobId)
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
                            const data = await $wire.getTestProgressData()
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
                            console.error('Test progress polling error:', e)
                        }
                        await new Promise(resolve => setTimeout(resolve, 2000))
                    }
                }
            }"
            class="mb-4"
        >
            <div class="rounded-lg bg-white dark:bg-gray-800 shadow p-4 border-l-4 border-info-500">
                <div class="flex items-center gap-3 mb-3">
                    <div
                        x-show="status === 'processing' || status === 'pending'"
                        class="animate-spin rounded-full h-5 w-5 border-b-2 border-info-500"
                    ></div>
                    <template x-if="status === 'completed'">
                        <x-heroicon-o-check-circle class="h-5 w-5 text-success-500" />
                    </template>
                    <template x-if="status === 'failed'">
                        <x-heroicon-o-x-circle class="h-5 w-5 text-danger-500" />
                    </template>
                    <span class="font-medium text-gray-700 dark:text-gray-300">テストデータ生成</span>
                    <span
                        x-text="status === 'pending' ? '待機中' : status === 'processing' ? '処理中' : status === 'completed' ? '完了' : '失敗'"
                        :class="{
                            'bg-gray-100 text-gray-700': status === 'pending',
                            'bg-cyan-100 text-cyan-700': status === 'processing',
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
                            'bg-info-500': status === 'processing' || status === 'pending',
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
    @if(!$this->activeJobId && !$this->activeTestJobId)
        <div wire:key="confirmation-table-{{ $this->confirmationTab }}-{{ $this->activePresetView }}">
            <div class="mb-6 -mt-6">
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
