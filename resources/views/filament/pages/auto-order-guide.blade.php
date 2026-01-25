<div class="livewire-root"><x-filament-panels::page>
    <div x-data="{ activeTab: 'overview' }" class="h-[calc(100vh-120px)] flex flex-col">
        {{-- タブナビゲーション --}}
        <div class="border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            <nav class="-mb-px flex flex-wrap gap-x-8" aria-label="Tabs">
                <button
                    @click="activeTab = 'overview'"
                    type="button"
                    :class="activeTab === 'overview' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-information-circle class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    概要
                </button>
                <button
                    @click="activeTab = 'daily'"
                    type="button"
                    :class="activeTab === 'daily' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-clipboard-document-check class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    日常業務
                </button>
                <button
                    @click="activeTab = 'transmission'"
                    type="button"
                    :class="activeTab === 'transmission' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-paper-airplane class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    発注送信
                </button>
                <button
                    @click="activeTab = 'settings'"
                    type="button"
                    :class="activeTab === 'settings' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-cog-6-tooth class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    設定
                </button>
            </nav>
        </div>

        {{-- 概要タブ --}}
        <div x-show="activeTab === 'overview'" x-cloak class="flex-1 overflow-y-auto pt-4">
            {{-- システム概要 --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-information-circle class="w-5 h-5 text-primary-500" />
                            発注システムとは
                        </div>
                    </x-slot>
                    <div class="text-sm text-gray-700 dark:text-gray-300 space-y-3">
                        <p>在庫が発注点を下回った商品を自動で検出し、発注候補を作成するシステムです。</p>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-semibold mb-3">主な機能</div>
                            <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>毎朝、在庫状況をチェックして発注候補を自動作成</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>納品可能な曜日・休日を考慮した到着日を計算</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>確認・承認後に問屋へデータを送信</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-arrow-path class="w-5 h-5 text-primary-500" />
                            全体の流れ
                        </div>
                    </x-slot>
                    <div class="space-y-3">
                        <div class="flex items-center gap-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-blue-500 text-white flex items-center justify-center font-bold">1</span>
                            <div>
                                <div class="font-semibold text-sm">自動計算（毎朝5:00）</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">発注候補が自動で作成されます</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center font-bold">2</span>
                            <div>
                                <div class="font-semibold text-sm">確認・承認</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">発注候補一覧で内容を確認し承認</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-orange-500 text-white flex items-center justify-center font-bold">3</span>
                            <div>
                                <div class="font-semibold text-sm">発注確定</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">承認した候補を確定して入荷予定を作成</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-purple-500 text-white flex items-center justify-center font-bold">4</span>
                            <div>
                                <div class="font-semibold text-sm">発注送信</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">問屋へ発注データを送信</div>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            {{-- 画面一覧 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-computer-desktop class="w-5 h-5 text-primary-500" />
                        関連画面一覧
                    </div>
                </x-slot>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-clipboard-document-list class="w-6 h-6 text-blue-500" />
                            <span class="font-semibold">発注候補一覧</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">発注候補の確認・承認・確定を行う画面</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-order-incoming-schedules.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-truck class="w-6 h-6 text-green-500" />
                            <span class="font-semibold">入荷予定一覧</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">確定済み発注の入荷予定を確認</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-order-jx-settings.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-paper-airplane class="w-6 h-6 text-purple-500" />
                            <span class="font-semibold">JX送信設定</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">問屋への送信設定・履歴を確認</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-monthly-safety-stocks.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-chart-bar class="w-6 h-6 text-orange-500" />
                            <span class="font-semibold">月別発注点</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">月ごとの発注点を設定</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-warehouse-calendars.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-calendar class="w-6 h-6 text-red-500" />
                            <span class="font-semibold">倉庫カレンダー</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">倉庫の休日設定を管理</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-contractor-warehouse-delivery-days.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-calendar-days class="w-6 h-6 text-teal-500" />
                            <span class="font-semibold">納品可能曜日</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">発注先×倉庫ごとの納品曜日を設定</p>
                    </a>
                </div>
            </x-filament::section>
        </div>

        {{-- 日常業務タブ --}}
        <div x-show="activeTab === 'daily'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="space-y-6">
                {{-- 発注候補の確認 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-blue-500 text-white text-xs flex items-center justify-center font-bold">1</span>
                            発注候補の確認
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-map-pin class="w-5 h-5 text-blue-500" />
                                <span class="font-semibold">画面</span>
                            </div>
                            <p class="text-sm">
                                <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline font-semibold">
                                    発注 → 発注候補一覧
                                </a>
                            </p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2">確認する内容</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 発注数量が適切か</li>
                                    <li>・ 到着予定日が問題ないか</li>
                                    <li>・ 発注不要な商品がないか</li>
                                </ul>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2">フィルター機能</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 発注先で絞り込み</li>
                                    <li>・ 倉庫で絞り込み</li>
                                    <li>・ ステータスで絞り込み</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 承認・除外 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-green-500 text-white text-xs flex items-center justify-center font-bold">2</span>
                            承認・除外
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                                <div class="flex items-center gap-2 mb-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500" />
                                    <span class="font-semibold text-green-700 dark:text-green-300">承認</span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400">発注する候補を選択して承認ボタンをクリック</p>
                            </div>
                            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4 border border-red-200 dark:border-red-800">
                                <div class="flex items-center gap-2 mb-2">
                                    <x-heroicon-o-x-circle class="w-5 h-5 text-red-500" />
                                    <span class="font-semibold text-red-700 dark:text-red-300">除外</span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400">発注しない候補を選択して除外ボタンをクリック</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2 mb-2">
                                    <x-heroicon-o-pencil class="w-5 h-5 text-gray-500" />
                                    <span class="font-semibold">数量変更</span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400">編集ボタンで発注数量を変更可能</p>
                            </div>
                        </div>
                        <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-3 border border-warning-200 dark:border-warning-700">
                            <div class="flex items-start gap-2">
                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" />
                                <div class="text-sm text-warning-700 dark:text-warning-300">
                                    <strong>ヒント：</strong>一括操作を使うと、複数の候補をまとめて承認・除外できます。チェックボックスで選択後、上部のアクションボタンをクリックしてください。
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 発注確定 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-orange-500 text-white text-xs flex items-center justify-center font-bold">3</span>
                            発注確定
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <x-heroicon-o-cursor-arrow-rays class="w-5 h-5 text-orange-500" />
                                <span class="font-semibold">操作方法</span>
                            </div>
                            <ol class="text-sm text-gray-700 dark:text-gray-300 space-y-2">
                                <li class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-800 dark:text-orange-200 text-xs flex items-center justify-center flex-shrink-0">1</span>
                                    <span>画面上部の<strong>「発注確定」</strong>ボタンをクリック</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-800 dark:text-orange-200 text-xs flex items-center justify-center flex-shrink-0">2</span>
                                    <span>確認ダイアログで内容を確認</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-800 dark:text-orange-200 text-xs flex items-center justify-center flex-shrink-0">3</span>
                                    <span>「確定」をクリックして処理実行</span>
                                </li>
                            </ol>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-semibold text-sm mb-2">確定後の状態</div>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <li>・ 発注候補のステータスが「実行済」に変更</li>
                                <li>・ 入荷予定データが自動作成</li>
                                <li>・ 発注送信の準備が完了</li>
                            </ul>
                        </div>
                    </div>
                </x-filament::section>

                {{-- ステータス説明 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-tag class="w-5 h-5 text-primary-500" />
                            ステータスの意味
                        </div>
                    </x-slot>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="text-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <span class="inline-block w-3 h-3 rounded-full bg-gray-400 mb-2"></span>
                            <div class="font-semibold text-sm">未承認</div>
                            <div class="text-xs text-gray-500">確認待ち</div>
                        </div>
                        <div class="text-center p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <span class="inline-block w-3 h-3 rounded-full bg-green-500 mb-2"></span>
                            <div class="font-semibold text-sm">承認済</div>
                            <div class="text-xs text-gray-500">確定待ち</div>
                        </div>
                        <div class="text-center p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                            <span class="inline-block w-3 h-3 rounded-full bg-red-500 mb-2"></span>
                            <div class="font-semibold text-sm">除外</div>
                            <div class="text-xs text-gray-500">発注しない</div>
                        </div>
                        <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <span class="inline-block w-3 h-3 rounded-full bg-blue-500 mb-2"></span>
                            <div class="font-semibold text-sm">実行済</div>
                            <div class="text-xs text-gray-500">確定完了</div>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- 発注送信タブ --}}
        <div x-show="activeTab === 'transmission'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="space-y-6">
                {{-- 送信の流れ --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="w-5 h-5 text-primary-500" />
                            発注送信の流れ
                        </div>
                    </x-slot>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-8 h-8 rounded-full bg-blue-500 text-white flex items-center justify-center font-bold">1</span>
                                <span class="font-semibold">送信データ作成</span>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                                <p>「発注送信データ作成」ボタンをクリック</p>
                                <p class="text-xs bg-white dark:bg-gray-900 p-2 rounded">確定済みの発注から送信用ファイルを生成します</p>
                            </div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center font-bold">2</span>
                                <span class="font-semibold">内容確認</span>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                                <p>生成されたファイルの内容を確認</p>
                                <p class="text-xs bg-white dark:bg-gray-900 p-2 rounded">発注先ごとにファイルが作成されます</p>
                            </div>
                        </div>
                        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 border border-purple-200 dark:border-purple-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-8 h-8 rounded-full bg-purple-500 text-white flex items-center justify-center font-bold">3</span>
                                <span class="font-semibold">送信実行</span>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                                <p>「発注データ送信」ボタンをクリック</p>
                                <p class="text-xs bg-white dark:bg-gray-900 p-2 rounded">問屋のシステムへデータを送信します</p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 操作画面 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-cursor-arrow-rays class="w-5 h-5 text-primary-500" />
                            操作画面
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <x-heroicon-o-map-pin class="w-5 h-5 text-blue-500" />
                                <span class="font-semibold">発注候補一覧画面から操作</span>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    発注 → 発注候補一覧
                                </a>
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded-full text-sm">
                                    <x-heroicon-o-document-text class="w-4 h-4" />
                                    発注送信データ作成
                                </span>
                                <span class="inline-flex items-center gap-1 px-3 py-1 bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 rounded-full text-sm">
                                    <x-heroicon-o-paper-airplane class="w-4 h-4" />
                                    発注データ送信
                                </span>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 送信先 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-building-office class="w-5 h-5 text-primary-500" />
                            送信先（問屋）
                        </div>
                    </x-slot>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-semibold text-sm mb-2">JX-FINET経由で送信</div>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <li>・ カナカン</li>
                                <li>・ 三菱食品</li>
                                <li>・ 北陸コカ・コーラ</li>
                                <li>・ 国分中部</li>
                            </ul>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-semibold text-sm mb-2">送信設定の確認</div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                送信設定は以下の画面で確認できます：
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-order-jx-settings.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                発注 → JX送信設定
                            </a>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 送信履歴 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-clock class="w-5 h-5 text-primary-500" />
                            送信履歴の確認
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                            過去の送信履歴は以下の画面で確認できます：
                        </p>
                        <a href="{{ route('filament.admin.resources.wms-order-jx-settings.index') }}" class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:underline">
                            <x-heroicon-o-paper-airplane class="w-5 h-5" />
                            発注 → JX送信設定 → 送信履歴タブ
                        </a>
                        <div class="mt-3 text-xs text-gray-500">
                            送信日時、送信結果、送信ファイルの内容などを確認できます。
                        </div>
                    </div>
                </x-filament::section>

                {{-- 注意事項 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500" />
                            注意事項
                        </div>
                    </x-slot>
                    <div class="space-y-3">
                        <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-3 border border-warning-200 dark:border-warning-700">
                            <div class="text-sm text-warning-700 dark:text-warning-300">
                                <strong>送信前の確認：</strong>送信データ作成後、送信前に必ず内容を確認してください。送信後の取り消しはできません。
                            </div>
                        </div>
                        <div class="bg-info-50 dark:bg-info-900/20 rounded-lg p-3 border border-info-200 dark:border-info-700">
                            <div class="text-sm text-info-700 dark:text-info-300">
                                <strong>送信タイミング：</strong>問屋の受付時間内に送信してください。受付時間外の送信は翌営業日の処理になる場合があります。
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- 設定タブ --}}
        <div x-show="activeTab === 'settings'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="space-y-6">
                {{-- 月別発注点 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-chart-bar class="w-5 h-5 text-orange-500" />
                            月別発注点の設定
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                                商品ごとに月別の発注点（在庫がこの数量を下回ったら発注）を設定できます。
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-monthly-safety-stocks.index') }}" class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:underline">
                                <x-heroicon-o-chart-bar class="w-5 h-5" />
                                発注 → 月別発注点
                            </a>
                        </div>
                        <div class="bg-info-50 dark:bg-info-900/20 rounded-lg p-3 border border-info-200 dark:border-info-700">
                            <div class="text-sm text-info-700 dark:text-info-300">
                                <strong>自動同期：</strong>毎月末日に、翌月の発注点が自動的に反映されます。
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 納品可能曜日 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-calendar-days class="w-5 h-5 text-teal-500" />
                            納品可能曜日の設定
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                                発注先と倉庫の組み合わせごとに、納品可能な曜日を設定できます。設定された曜日以外には納品されないよう、到着予定日が自動調整されます。
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-contractor-warehouse-delivery-days.index') }}" class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:underline">
                                <x-heroicon-o-calendar-days class="w-5 h-5" />
                                設定 → 納品可能曜日
                            </a>
                        </div>
                        <div class="flex gap-2 justify-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            @foreach(['日', '月', '火', '水', '木', '金', '土'] as $index => $day)
                            <span class="w-10 h-10 rounded-full {{ in_array($index, [2, 5]) ? 'bg-green-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500' }} flex items-center justify-center text-sm font-medium">{{ $day }}</span>
                            @endforeach
                        </div>
                        <p class="text-xs text-center text-gray-500">例：火曜・金曜のみ納品可能</p>
                    </div>
                </x-filament::section>

                {{-- 倉庫休日 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-calendar class="w-5 h-5 text-red-500" />
                            倉庫休日の設定
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                                倉庫の休日（定休日、祝日など）を設定します。休日には納品されないよう、到着予定日が自動調整されます。
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-warehouse-calendars.index') }}" class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:underline">
                                <x-heroicon-o-calendar class="w-5 h-5" />
                                設定 → 倉庫カレンダー
                            </a>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-3">
                                <div class="font-semibold text-sm text-red-700 dark:text-red-300 mb-1">休日の種類</div>
                                <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 定休日（毎週日曜など）</li>
                                    <li>・ 祝日</li>
                                    <li>・ 臨時休業</li>
                                </ul>
                            </div>
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                                <div class="font-semibold text-sm text-green-700 dark:text-green-300 mb-1">自動調整</div>
                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                    休日に当たる場合は、次の営業日に自動で調整されます。
                                </p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- JX送信設定 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-cog-6-tooth class="w-5 h-5 text-purple-500" />
                            JX送信設定
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <p class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                            問屋への送信接続設定を管理します。接続テストや送信履歴の確認もこちらから行えます。
                        </p>
                        <a href="{{ route('filament.admin.resources.wms-order-jx-settings.index') }}" class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:underline">
                            <x-heroicon-o-paper-airplane class="w-5 h-5" />
                            発注 → JX送信設定
                        </a>
                        <div class="mt-3 text-xs text-gray-500">
                            ※ 設定変更は管理者にお問い合わせください。
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>
    </div>
</x-filament-panels::page></div>
