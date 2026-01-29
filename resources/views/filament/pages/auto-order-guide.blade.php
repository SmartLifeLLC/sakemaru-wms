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
                        <p>在庫が発注点を下回った商品を自動で検出し、<strong>倉庫間移動候補</strong>と<strong>発注候補</strong>を作成するシステムです。</p>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-semibold mb-3">主な機能</div>
                            <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>在庫状況をチェックして移動候補・発注候補を自動作成</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>Hub倉庫からSatellite倉庫への倉庫間移動を自動計算</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>納品可能な曜日・休日を考慮した到着日を計算</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 flex-shrink-0 mt-0.5" />
                                    <span>確認・承認後に移動伝票生成と問屋へのデータ送信</span>
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
                                <div class="font-semibold text-sm">候補計算（手動実施）</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">朝の出荷完了後に実施</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-cyan-50 dark:bg-cyan-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-cyan-500 text-white flex items-center justify-center font-bold">2</span>
                            <div>
                                <div class="font-semibold text-sm">移動候補の確認・承認</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">移動候補一覧で内容を確認し承認</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center font-bold">3</span>
                            <div>
                                <div class="font-semibold text-sm">発注候補の確認・承認</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">発注候補一覧で内容を確認し承認</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-orange-500 text-white flex items-center justify-center font-bold">4</span>
                            <div>
                                <div class="font-semibold text-sm">発注・移動確定</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">承認した候補を一括確定</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                            <span class="w-8 h-8 rounded-full bg-purple-500 text-white flex items-center justify-center font-bold">5</span>
                            <div>
                                <div class="font-semibold text-sm">発注送信</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">問屋へ発注データを送信</div>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            {{-- 移動と発注の関係 --}}
            <x-filament::section class="mb-6">
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-arrows-right-left class="w-5 h-5 text-primary-500" />
                        移動候補と発注候補の関係
                    </div>
                </x-slot>
                <div class="space-y-4">
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="text-center p-3 bg-cyan-50 dark:bg-cyan-900/20 rounded-lg">
                                <x-heroicon-o-building-office-2 class="w-8 h-8 text-cyan-500 mx-auto mb-2" />
                                <div class="font-semibold text-sm">Satellite倉庫</div>
                                <div class="text-xs text-gray-500">在庫不足を検出</div>
                            </div>
                            <div class="flex items-center justify-center">
                                <x-heroicon-o-arrow-right class="w-8 h-8 text-gray-400" />
                            </div>
                            <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                <x-heroicon-o-building-office class="w-8 h-8 text-blue-500 mx-auto mb-2" />
                                <div class="font-semibold text-sm">Hub倉庫</div>
                                <div class="text-xs text-gray-500">在庫を供給</div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-cyan-50 dark:bg-cyan-900/20 rounded-lg p-4 border border-cyan-200 dark:border-cyan-800">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-truck class="w-5 h-5 text-cyan-500" />
                                <span class="font-semibold text-cyan-700 dark:text-cyan-300">移動候補（INTERNAL）</span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-400">Hub倉庫からSatellite倉庫への倉庫間移動。Hub倉庫の在庫を使用。</p>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-shopping-cart class="w-5 h-5 text-green-500" />
                                <span class="font-semibold text-green-700 dark:text-green-300">発注候補（EXTERNAL）</span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-400">問屋からの外部発注。Hub倉庫の不足分 + Satellite需要分を発注。</p>
                        </div>
                    </div>
                    <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-3 border border-warning-200 dark:border-warning-700">
                        <div class="flex items-start gap-2">
                            <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" />
                            <div class="text-sm text-warning-700 dark:text-warning-300">
                                <strong>重要：</strong>移動候補の数量を変更すると、関連する発注候補の数量も自動的に再計算されます。移動候補を先に確認・承認してから、発注候補を承認してください。
                            </div>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- 画面一覧 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-computer-desktop class="w-5 h-5 text-primary-500" />
                        関連画面一覧
                    </div>
                </x-slot>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <a href="{{ route('filament.admin.resources.wms-stock-transfer-candidates.index') }}" class="block p-4 bg-cyan-50 dark:bg-cyan-900/20 rounded-lg hover:bg-cyan-100 dark:hover:bg-cyan-900/30 transition-colors border border-cyan-200 dark:border-cyan-800">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-truck class="w-6 h-6 text-cyan-500" />
                            <span class="font-semibold">移動候補一覧</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">倉庫間移動候補の確認・承認を行う画面</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-clipboard-document-list class="w-6 h-6 text-blue-500" />
                            <span class="font-semibold">発注候補一覧</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">発注候補の確認・承認を行う画面</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-order-confirmation-waiting.index') }}" class="block p-4 bg-orange-50 dark:bg-orange-900/20 rounded-lg hover:bg-orange-100 dark:hover:bg-orange-900/30 transition-colors border border-orange-200 dark:border-orange-800">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-check-circle class="w-6 h-6 text-orange-500" />
                            <span class="font-semibold">発注・移動確定待ち</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">承認済み候補を一括確定する画面</p>
                    </a>
                    <a href="{{ route('filament.admin.resources.wms-order-incoming-schedules.index') }}" class="block p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <div class="flex items-center gap-3 mb-2">
                            <x-heroicon-o-inbox-arrow-down class="w-6 h-6 text-green-500" />
                            <span class="font-semibold">入荷予定一覧</span>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400">確定済みの入荷予定を確認</p>
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
                </div>
            </x-filament::section>
        </div>

        {{-- 日常業務タブ --}}
        <div x-show="activeTab === 'daily'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="space-y-6">
                {{-- 候補計算 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-blue-500 text-white text-xs flex items-center justify-center font-bold">1</span>
                            候補計算の実行
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-map-pin class="w-5 h-5 text-blue-500" />
                                <span class="font-semibold">画面</span>
                            </div>
                            <p class="text-sm">
                                <a href="{{ route('filament.admin.resources.wms-auto-order-job-controls.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline font-semibold">
                                    発注 → 自動発注ジョブ管理
                                </a>
                            </p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-semibold text-sm mb-2">計算で生成されるデータ</div>
                            <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <li>・ <strong class="text-cyan-600">移動候補（INTERNAL）</strong>：Hub倉庫からSatellite倉庫への移動</li>
                                <li>・ <strong class="text-green-600">発注候補（EXTERNAL）</strong>：問屋への発注（移動出庫分を考慮）</li>
                            </ul>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 移動候補の確認 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-cyan-500 text-white text-xs flex items-center justify-center font-bold">2</span>
                            移動候補の確認・承認
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-cyan-50 dark:bg-cyan-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-map-pin class="w-5 h-5 text-cyan-500" />
                                <span class="font-semibold">画面</span>
                            </div>
                            <p class="text-sm">
                                <a href="{{ route('filament.admin.resources.wms-stock-transfer-candidates.index') }}" class="text-cyan-600 dark:text-cyan-400 hover:underline font-semibold">
                                    発注 → 移動候補一覧
                                </a>
                            </p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2">確認する内容</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 移動数量が適切か</li>
                                    <li>・ 移動出荷日が問題ないか</li>
                                    <li>・ 配送コースが正しいか</li>
                                </ul>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2">操作</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ <span class="text-green-600 font-semibold">承認</span>：移動を実行</li>
                                    <li>・ <span class="text-red-600 font-semibold">除外</span>：移動しない</li>
                                    <li>・ <span class="text-gray-600 font-semibold">変更</span>：数量を修正</li>
                                </ul>
                            </div>
                            <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-4 border border-warning-200 dark:border-warning-800">
                                <div class="font-semibold text-sm mb-2 text-warning-700 dark:text-warning-300">数量変更時の注意</div>
                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                    移動数量を変更すると、関連する発注候補の数量も自動再計算されます。
                                </p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 発注候補の確認 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-green-500 text-white text-xs flex items-center justify-center font-bold">3</span>
                            発注候補の確認・承認
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-map-pin class="w-5 h-5 text-green-500" />
                                <span class="font-semibold">画面</span>
                            </div>
                            <p class="text-sm">
                                <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-green-600 dark:text-green-400 hover:underline font-semibold">
                                    発注 → 発注候補一覧
                                </a>
                            </p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2">確認する内容</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 発注数量が適切か</li>
                                    <li>・ 到着予定日が問題ないか</li>
                                    <li>・ 発注不要な商品がないか</li>
                                </ul>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2">操作</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ <span class="text-green-600 font-semibold">承認</span>：発注を実行</li>
                                    <li>・ <span class="text-red-600 font-semibold">除外</span>：発注しない</li>
                                    <li>・ <span class="text-gray-600 font-semibold">変更</span>：数量を修正</li>
                                </ul>
                            </div>
                            <div class="bg-danger-50 dark:bg-danger-900/20 rounded-lg p-4 border border-danger-200 dark:border-danger-800">
                                <div class="font-semibold text-sm mb-2 text-danger-700 dark:text-danger-300">承認の制約</div>
                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                    移動候補に未承認データがある場合、発注候補の承認はブロックされます。先に移動候補を処理してください。
                                </p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 発注・移動確定 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-orange-500 text-white text-xs flex items-center justify-center font-bold">4</span>
                            発注・移動確定
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <x-heroicon-o-map-pin class="w-5 h-5 text-orange-500" />
                                <span class="font-semibold">画面</span>
                            </div>
                            <p class="text-sm">
                                <a href="{{ route('filament.admin.resources.wms-order-confirmation-waiting.index') }}" class="text-orange-600 dark:text-orange-400 hover:underline font-semibold">
                                    発注 → 発注・移動確定待ち
                                </a>
                            </p>
                        </div>
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <x-heroicon-o-cursor-arrow-rays class="w-5 h-5 text-orange-500" />
                                <span class="font-semibold">操作方法</span>
                            </div>
                            <ol class="text-sm text-gray-700 dark:text-gray-300 space-y-2">
                                <li class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-800 dark:text-orange-200 text-xs flex items-center justify-center flex-shrink-0">1</span>
                                    <span>画面上部の<strong>「発注・移動確定」</strong>ボタンをクリック</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-800 dark:text-orange-200 text-xs flex items-center justify-center flex-shrink-0">2</span>
                                    <span>確認ダイアログで内容を確認（移動件数・発注件数）</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-orange-200 dark:bg-orange-800 text-orange-800 dark:text-orange-200 text-xs flex items-center justify-center flex-shrink-0">3</span>
                                    <span>「確定」をクリックして処理実行</span>
                                </li>
                            </ol>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-cyan-50 dark:bg-cyan-900/20 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2 text-cyan-700 dark:text-cyan-300">移動候補の確定後</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 移動伝票（stock_transfer）が作成</li>
                                    <li>・ Satellite倉庫の入荷予定が作成</li>
                                    <li>・ ステータスが「実行済」に変更</li>
                                </ul>
                            </div>
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                                <div class="font-semibold text-sm mb-2 text-green-700 dark:text-green-300">発注候補の確定後</div>
                                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <li>・ 入荷予定データが作成</li>
                                    <li>・ 発注送信用ファイルが生成</li>
                                    <li>・ ステータスが「実行済」に変更</li>
                                </ul>
                            </div>
                        </div>
                        <div class="bg-danger-50 dark:bg-danger-900/20 rounded-lg p-3 border border-danger-200 dark:border-danger-700">
                            <div class="flex items-start gap-2">
                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-danger-500 flex-shrink-0 mt-0.5" />
                                <div class="text-sm text-danger-700 dark:text-danger-300">
                                    <strong>確定の制約：</strong>移動候補または発注候補に未承認データがある場合、確定処理はブロックされます。全ての候補を承認または除外してから確定してください。
                                </div>
                            </div>
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
                            <div class="text-xs text-gray-500">実行しない</div>
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
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- 左カラム: 送信の流れ --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="w-5 h-5 text-primary-500" />
                            発注送信の流れ
                        </div>
                    </x-slot>
                    <div class="space-y-3">
                        <div class="flex items-start gap-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <span class="w-7 h-7 rounded-full bg-blue-500 text-white text-sm flex items-center justify-center font-bold flex-shrink-0">1</span>
                            <div>
                                <div class="font-semibold text-sm">送信データ作成</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">「発注送信データ作成」ボタンで送信用ファイルを生成</div>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                            <span class="w-7 h-7 rounded-full bg-green-500 text-white text-sm flex items-center justify-center font-bold flex-shrink-0">2</span>
                            <div>
                                <div class="font-semibold text-sm">内容確認</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">生成されたファイルの内容を確認</div>
                            </div>
                        </div>
                        <div class="flex items-start gap-3 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                            <span class="w-7 h-7 rounded-full bg-purple-500 text-white text-sm flex items-center justify-center font-bold flex-shrink-0">3</span>
                            <div>
                                <div class="font-semibold text-sm">送信実行</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">「発注データ送信」ボタンで問屋へ送信</div>
                            </div>
                        </div>
                    </div>
                    {{-- 操作画面 --}}
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-2 mb-2">
                            <x-heroicon-o-map-pin class="w-4 h-4 text-blue-500" />
                            <span class="font-semibold text-sm">操作画面</span>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                            <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                発注 → 発注候補一覧
                            </a>
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded text-xs">
                                <x-heroicon-o-document-text class="w-3 h-3" />
                                発注送信データ作成
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-1 bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 rounded text-xs">
                                <x-heroicon-o-paper-airplane class="w-3 h-3" />
                                発注データ送信
                            </span>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 右カラム: 送信先・履歴・注意事項 --}}
                <div class="space-y-6">
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-building-office class="w-5 h-5 text-primary-500" />
                                送信先・履歴
                            </div>
                        </x-slot>
                        <div class="space-y-4">
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                <div class="font-semibold text-sm mb-2">JX-FINET経由で送信</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">カナカン / 三菱食品 / 北陸コカ・コーラ / 国分中部</div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                <div class="font-semibold text-sm mb-2">送信設定・履歴の確認</div>
                                <a href="{{ route('filament.admin.resources.wms-order-jx-settings.index') }}" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                    <x-heroicon-o-cog-6-tooth class="w-4 h-4" />
                                    発注 → JX送信設定
                                </a>
                            </div>
                        </div>
                    </x-filament::section>

                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-500" />
                                注意事項
                            </div>
                        </x-slot>
                        <div class="space-y-2">
                            <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-2 border border-warning-200 dark:border-warning-700">
                                <div class="text-xs text-warning-700 dark:text-warning-300">
                                    <strong>送信前確認：</strong>送信後の取り消しはできません
                                </div>
                            </div>
                            <div class="bg-info-50 dark:bg-info-900/20 rounded-lg p-2 border border-info-200 dark:border-info-700">
                                <div class="text-xs text-info-700 dark:text-info-300">
                                    <strong>送信タイミング：</strong>問屋の受付時間内に送信
                                </div>
                            </div>
                        </div>
                    </x-filament::section>
                </div>
            </div>
        </div>

        {{-- 設定タブ --}}
        <div x-show="activeTab === 'settings'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- 左カラム --}}
                <div class="space-y-6">
                    {{-- 月別発注点 --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-chart-bar class="w-5 h-5 text-orange-500" />
                                月別発注点
                            </div>
                        </x-slot>
                        <div class="space-y-3">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                商品ごとに月別の発注点を設定。毎月末日に自動反映。
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-monthly-safety-stocks.index') }}" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                                発注 → 月別発注点
                            </a>
                        </div>
                    </x-filament::section>

                    {{-- 倉庫休日 --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-calendar class="w-5 h-5 text-red-500" />
                                倉庫カレンダー
                            </div>
                        </x-slot>
                        <div class="space-y-3">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                倉庫の休日設定。休日は次の営業日に自動調整。
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-warehouse-calendars.index') }}" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                                設定 → 倉庫カレンダー
                            </a>
                            <div class="text-xs text-gray-500">定休日・祝日・臨時休業</div>
                        </div>
                    </x-filament::section>
                </div>

                {{-- 右カラム --}}
                <div class="space-y-6">
                    {{-- 納品可能曜日 --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-calendar-days class="w-5 h-5 text-teal-500" />
                                納品可能曜日
                                <span class="text-xs bg-gray-200 dark:bg-gray-700 px-2 py-0.5 rounded">準備中</span>
                            </div>
                        </x-slot>
                        <div class="space-y-3">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                発注先×倉庫ごとの納品曜日を設定。対象外の曜日は自動調整。
                            </p>
                            <div class="flex gap-1 justify-center">
                                @foreach(['日', '月', '火', '水', '木', '金', '土'] as $index => $day)
                                <span class="w-8 h-8 rounded-full {{ in_array($index, [2, 5]) ? 'bg-green-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-500' }} flex items-center justify-center text-xs font-medium">{{ $day }}</span>
                                @endforeach
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
                        <div class="space-y-3">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                問屋への送信接続設定・履歴確認。
                            </p>
                            <a href="{{ route('filament.admin.resources.wms-order-jx-settings.index') }}" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline text-sm">
                                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                                発注 → JX送信設定
                            </a>
                            <div class="text-xs text-gray-500">※ 設定変更は管理者へ</div>
                        </div>
                    </x-filament::section>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page></div>
