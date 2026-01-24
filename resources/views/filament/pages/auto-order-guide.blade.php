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
                    @click="activeTab = 'flow'"
                    type="button"
                    :class="activeTab === 'flow' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-arrow-path class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    計算フロー
                </button>
                <button
                    @click="activeTab = 'arrival'"
                    type="button"
                    :class="activeTab === 'arrival' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-calendar class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    到着日計算
                </button>
                <button
                    @click="activeTab = 'tables'"
                    type="button"
                    :class="activeTab === 'tables' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-circle-stack class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    テーブル
                </button>
                <button
                    @click="activeTab = 'commands'"
                    type="button"
                    :class="activeTab === 'commands' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-command-line class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    コマンド
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
                            システム概要
                        </div>
                    </x-slot>
                    <div class="text-sm text-gray-700 dark:text-gray-300 space-y-3">
                        <p>在庫が発注点を下回った商品に対し、自動的に発注候補・移動候補を作成するシステムです。</p>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <div class="font-semibold mb-2">主な機能</div>
                            <ul class="space-y-1 text-gray-600 dark:text-gray-400">
                                <li>• 在庫スナップショットの自動生成</li>
                                <li>• 発注点ベースの不足数計算</li>
                                <li>• リードタイム・納品曜日・休日を考慮した到着日計算</li>
                                <li>• 外部発注候補（EXTERNAL）と内部移動候補（INTERNAL）の分離</li>
                            </ul>
                        </div>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-arrows-right-left class="w-5 h-5 text-primary-500" />
                            発注タイプ
                        </div>
                    </x-slot>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 border border-blue-200 dark:border-blue-800">
                            <div class="font-bold text-blue-700 dark:text-blue-300 mb-2 text-sm">INTERNAL</div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p>ハブ倉庫 → サテライト倉庫</p>
                                <p class="text-blue-600 dark:text-blue-400">wms_stock_transfer_candidates</p>
                            </div>
                        </div>
                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 border border-green-200 dark:border-green-800">
                            <div class="font-bold text-green-700 dark:text-green-300 mb-2 text-sm">EXTERNAL</div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p>外部発注先への発注</p>
                                <p class="text-green-600 dark:text-green-400">wms_order_candidates</p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            {{-- 計算フロー概要 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-play class="w-5 h-5 text-success-500" />
                        計算フロー概要
                    </div>
                </x-slot>
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                    {{-- Step 0 --}}
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border-l-4 border-gray-400">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 rounded-full bg-gray-500 text-white text-xs flex items-center justify-center font-bold">0</span>
                            <span class="font-semibold text-sm">月別発注点同期</span>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-400">
                            <p class="mb-1">毎月末日 04:30 実行</p>
                            <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded text-xs">wms_monthly_safety_stocks</code>
                            <p class="mt-1">→ 翌月分を item_contractors に同期</p>
                        </div>
                    </div>

                    {{-- Step 1 --}}
                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border-l-4 border-blue-500">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 rounded-full bg-blue-500 text-white text-xs flex items-center justify-center font-bold">1</span>
                            <span class="font-semibold text-sm">スナップショット</span>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-400">
                            <p class="mb-1">有効在庫・入荷予定を集計</p>
                            <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded text-xs">wms_item_stock_snapshots</code>
                        </div>
                    </div>

                    {{-- Step 2 --}}
                    <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border-l-4 border-green-500">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 rounded-full bg-green-500 text-white text-xs flex items-center justify-center font-bold">2</span>
                            <span class="font-semibold text-sm">発注候補計算</span>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-400">
                            <p class="mb-1">不足数・到着予定日を計算</p>
                            <p>INTERNAL → 移動候補</p>
                            <p>EXTERNAL → 発注候補</p>
                        </div>
                    </div>

                    {{-- Step 3 --}}
                    <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 border-l-4 border-purple-500">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 rounded-full bg-purple-500 text-white text-xs flex items-center justify-center font-bold">3</span>
                            <span class="font-semibold text-sm">結果出力</span>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-400">
                            <p>wms_order_candidates</p>
                            <p>wms_stock_transfer_candidates</p>
                            <p>wms_order_calculation_logs</p>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- 必要データ --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs rounded">基幹</span>
                            マスタデータ
                        </div>
                    </x-slot>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">テーブル</th>
                                    <th class="px-2 py-1.5 text-left">用途</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">item_contractors</td>
                                    <td class="px-2 py-1.5">発注点、仕入単位、自動発注フラグ</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">contractors</td>
                                    <td class="px-2 py-1.5">発注先マスタ（lead_time_id）</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">lead_times</td>
                                    <td class="px-2 py-1.5">リードタイム設定（曜日別）</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">real_stocks</td>
                                    <td class="px-2 py-1.5">実在庫（ロット単位）</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 text-xs rounded">WMS</span>
                            設定データ
                        </div>
                    </x-slot>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">テーブル</th>
                                    <th class="px-2 py-1.5 text-left">用途</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_contractor_warehouse_delivery_days</td>
                                    <td class="px-2 py-1.5">納品可能曜日（発注先×倉庫）</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_warehouse_calendars</td>
                                    <td class="px-2 py-1.5">倉庫休日カレンダー</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_monthly_safety_stocks</td>
                                    <td class="px-2 py-1.5">月別発注点</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_order_incoming_schedules</td>
                                    <td class="px-2 py-1.5">入荷予定</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- 計算フロータブ --}}
        <div x-show="activeTab === 'flow'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                {{-- 不足数計算 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-calculator class="w-5 h-5 text-primary-500" />
                            不足数計算
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-mono text-sm bg-white dark:bg-gray-900 p-3 rounded border mb-4">
                                <p class="font-bold mb-2">不足数 =</p>
                                <p class="ml-4 text-primary-600 dark:text-primary-400">発注点</p>
                                <p class="ml-4">- 有効在庫</p>
                                <p class="ml-4">- 入荷予定</p>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p><strong>発注点:</strong> item_contractors.safety_stock（月別発注点で更新）</p>
                                <p><strong>有効在庫:</strong> wms_item_stock_snapshots.total_effective_piece</p>
                                <p><strong>入荷予定:</strong> wms_item_stock_snapshots.total_incoming_piece</p>
                            </div>
                        </div>
                        <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-3 border border-warning-200 dark:border-warning-700">
                            <p class="text-sm text-warning-700 dark:text-warning-300">
                                <strong>発注条件:</strong> 不足数 > 0 の場合のみ候補を作成
                            </p>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 発注数量計算 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-cube class="w-5 h-5 text-primary-500" />
                            発注数量計算
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                            <div class="font-mono text-sm bg-white dark:bg-gray-900 p-3 rounded border mb-4">
                                <p class="font-bold mb-2">発注数量 =</p>
                                <p class="ml-4">ceil(不足数 / 仕入単位) × 仕入単位</p>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                <p><strong>仕入単位:</strong> item_contractors.purchase_unit</p>
                                <p class="mt-2">例: 不足数=25, 仕入単位=12 → ceil(25/12)×12 = 36</p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 詳細実行フロー --}}
                <x-filament::section class="xl:col-span-2">
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-queue-list class="w-5 h-5 text-primary-500" />
                            詳細実行フロー
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 overflow-x-auto">
                        <pre class="text-xs whitespace-pre font-mono">
wms:auto-order-calculate
    │
    ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│ Phase 0: StockSnapshotService::generateAll()                                                         │
├─────────────────────────────────────────────────────────────────────────────────────────────────────┤
│  1. wms_v_stock_available から有効在庫を集計                                                          │
│  2. wms_order_incoming_schedules から入荷予定を集計                                                   │
│  3. wms_item_stock_snapshots に保存                                                                  │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│ Phase 1: OrderCandidateCalculationService::calculate()                                               │
├─────────────────────────────────────────────────────────────────────────────────────────────────────┤
│  1. データプリロード                                                                                   │
│     ├─ 在庫スナップショット                    [warehouse_id][item_id] => {effective, incoming}       │
│     ├─ 発注先リードタイム（contractors→lead_times） [contractor_id] => lead_time_days                │
│     ├─ 納品可能曜日                            [contractor_id][warehouse_id] => {mon,tue,...,sun}    │
│     └─ 倉庫休日                                [warehouse_id][date] => true                         │
│                                                                                                      │
│  2. INTERNAL発注先 → wms_stock_transfer_candidates                                                   │
│  3. EXTERNAL発注先 → wms_order_candidates                                                            │
│  4. 計算ログ → wms_order_calculation_logs                                                            │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘</pre>
                    </div>
                </x-filament::section>

                {{-- 候補ステータス --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-tag class="w-5 h-5 text-primary-500" />
                            候補ステータス
                        </div>
                    </x-slot>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="font-semibold text-sm mb-2">CandidateStatus</div>
                            <div class="space-y-1 text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    <code>PENDING</code> - 未承認
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                    <code>APPROVED</code> - 承認済
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                                    <code>EXCLUDED</code> - 除外
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                    <code>EXECUTED</code> - 実行済
                                </div>
                            </div>
                        </div>
                        <div>
                            <div class="font-semibold text-sm mb-2">LotStatus</div>
                            <div class="space-y-1 text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    <code>RAW</code> - 未適用
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-yellow-500"></span>
                                    <code>ADJUSTED</code> - 調整済
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                                    <code>BLOCKED</code> - ブロック
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-orange-500"></span>
                                    <code>NEED_APPROVAL</code> - 要承認
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 計算ログ --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-document-text class="w-5 h-5 text-primary-500" />
                            計算ログ
                        </div>
                    </x-slot>
                    <div class="text-sm text-gray-700 dark:text-gray-300 space-y-2">
                        <p><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded text-xs">wms_order_calculation_logs</code> に計算過程を記録</p>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-xs">
                            <p class="font-semibold mb-1">calculation_details (JSON)</p>
                            <ul class="space-y-0.5 text-gray-600 dark:text-gray-400">
                                <li>• 有効在庫、入荷予定、発注点</li>
                                <li>• リードタイム日数</li>
                                <li>• 到着日調整日数と理由</li>
                                <li>• 仕入単位調整の説明</li>
                            </ul>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- 到着日計算タブ --}}
        <div x-show="activeTab === 'arrival'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                {{-- 到着日計算フロー --}}
                <x-filament::section class="xl:col-span-2">
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-calendar class="w-5 h-5 text-primary-500" />
                            到着予定日計算フロー
                        </div>
                    </x-slot>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-full bg-blue-500 text-white text-xs flex items-center justify-center font-bold">1</span>
                                <span class="font-semibold text-sm">リードタイム</span>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p>発注日 + リードタイム日数</p>
                                <div class="mt-2 bg-white dark:bg-gray-900 rounded p-2">
                                    <p class="font-mono">contractors.lead_time_id</p>
                                    <p class="font-mono">→ lead_times.lead_time_xxx</p>
                                </div>
                                <p class="mt-2 text-gray-500">デフォルト: 1日</p>
                            </div>
                        </div>

                        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-full bg-green-500 text-white text-xs flex items-center justify-center font-bold">2</span>
                                <span class="font-semibold text-sm">納品可能曜日</span>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p>発注先×倉庫の納品可能曜日をチェック</p>
                                <div class="mt-2 bg-white dark:bg-gray-900 rounded p-2">
                                    <p class="font-mono text-xs">wms_contractor_warehouse_delivery_days</p>
                                </div>
                                <p class="mt-2 text-gray-500">不可曜日 → 次の可能日へ</p>
                            </div>
                        </div>

                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg p-4 border border-orange-200 dark:border-orange-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-full bg-orange-500 text-white text-xs flex items-center justify-center font-bold">3</span>
                                <span class="font-semibold text-sm">倉庫休日</span>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p>倉庫の休日をチェック</p>
                                <div class="mt-2 bg-white dark:bg-gray-900 rounded p-2">
                                    <p class="font-mono">wms_warehouse_calendars</p>
                                    <p class="font-mono">is_holiday = true</p>
                                </div>
                                <p class="mt-2 text-gray-500">休日 → 次の営業日へ</p>
                            </div>
                        </div>

                        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 border border-purple-200 dark:border-purple-800">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-full bg-purple-500 text-white text-xs flex items-center justify-center font-bold">4</span>
                                <span class="font-semibold text-sm">到着日確定</span>
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p>最終的な到着予定日</p>
                                <div class="mt-2 bg-white dark:bg-gray-900 rounded p-2">
                                    <p class="font-mono">expected_arrival_date</p>
                                    <p class="font-mono">original_arrival_date</p>
                                </div>
                                <p class="mt-2 text-gray-500">調整日数・理由をログに記録</p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 計算例 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-light-bulb class="w-5 h-5 text-warning-500" />
                            計算例
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-xs">
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <p class="font-semibold mb-1">前提条件</p>
                                <ul class="space-y-0.5 text-gray-600 dark:text-gray-400">
                                    <li>発注日: 2026-01-24（金）</li>
                                    <li>リードタイム: 1日</li>
                                    <li>納品可能曜日: 火・金のみ</li>
                                    <li>倉庫休日: なし</li>
                                </ul>
                            </div>
                            <div>
                                <p class="font-semibold mb-1">計算</p>
                                <ul class="space-y-0.5 text-gray-600 dark:text-gray-400">
                                    <li>基準日: 1/24 + 1日 = 1/25（土）</li>
                                    <li>土曜: 納品不可 → 1/26（日）</li>
                                    <li>日曜: 納品不可 → 1/27（月）</li>
                                    <li>月曜: 納品不可 → 1/28（火）</li>
                                    <li class="font-bold text-primary-600 dark:text-primary-400">確定: 2026-01-28（火）</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- リードタイム設定 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-clock class="w-5 h-5 text-primary-500" />
                            リードタイム設定
                        </div>
                    </x-slot>
                    <div class="text-sm space-y-3">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <p class="font-semibold text-xs mb-2">参照経路</p>
                            <div class="font-mono text-xs space-y-1 text-gray-600 dark:text-gray-400">
                                <p>item_contractors.contractor_id</p>
                                <p class="ml-4">→ contractors.lead_time_id</p>
                                <p class="ml-8">→ lead_times.lead_time_xxx</p>
                            </div>
                        </div>
                        <div class="text-xs text-gray-500">
                            ※ 未設定時のデフォルト: 1日
                        </div>
                    </div>
                </x-filament::section>

                {{-- 納品可能曜日 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-calendar-days class="w-5 h-5 text-primary-500" />
                            納品可能曜日
                        </div>
                    </x-slot>
                    <div class="text-sm space-y-3">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <p class="font-semibold text-xs mb-2">テーブル</p>
                            <code class="text-xs bg-gray-200 dark:bg-gray-700 px-1 rounded">wms_contractor_warehouse_delivery_days</code>
                            <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                <p>発注先×倉庫ごとに曜日別の納品可否を設定</p>
                            </div>
                        </div>
                        <div class="flex gap-1 justify-center">
                            @foreach(['日', '月', '火', '水', '木', '金', '土'] as $day)
                            <span class="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-xs">{{ $day }}</span>
                            @endforeach
                        </div>
                    </div>
                </x-filament::section>

                {{-- 倉庫休日 --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-x-circle class="w-5 h-5 text-danger-500" />
                            倉庫休日
                        </div>
                    </x-slot>
                    <div class="text-sm space-y-3">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <p class="font-semibold text-xs mb-2">テーブル</p>
                            <code class="text-xs bg-gray-200 dark:bg-gray-700 px-1 rounded">wms_warehouse_calendars</code>
                            <div class="mt-2 text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p><strong>target_date:</strong> 対象日付</p>
                                <p><strong>is_holiday:</strong> 休日フラグ</p>
                                <p><strong>holiday_reason:</strong> 休日理由（定休日、祝日など）</p>
                            </div>
                        </div>
                        <div class="text-xs text-gray-500">
                            ※ 今後30日分のデータをプリロードして使用
                        </div>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- テーブル情報タブ --}}
        <div x-show="activeTab === 'tables'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                {{-- マスタ系テーブル --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs rounded">マスタ</span>
                            参照テーブル
                        </div>
                    </x-slot>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">テーブル</th>
                                    <th class="px-2 py-1.5 text-left">用途</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">item_contractors</td>
                                    <td class="px-2 py-1.5">商品×倉庫×発注先の設定</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">contractors</td>
                                    <td class="px-2 py-1.5">発注先マスタ（lead_time_id）</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">lead_times</td>
                                    <td class="px-2 py-1.5">リードタイム（曜日別）</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_contractor_warehouse_delivery_days</td>
                                    <td class="px-2 py-1.5">納品可能曜日（発注先×倉庫）</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_warehouse_calendars</td>
                                    <td class="px-2 py-1.5">倉庫休日カレンダー</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_monthly_safety_stocks</td>
                                    <td class="px-2 py-1.5">月別発注点</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                {{-- トランザクション系テーブル --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 text-xs rounded">出力</span>
                            結果テーブル
                        </div>
                    </x-slot>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">テーブル</th>
                                    <th class="px-2 py-1.5 text-left">用途</th>
                                    <th class="px-2 py-1.5 text-left">リンク</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_item_stock_snapshots</td>
                                    <td class="px-2 py-1.5">在庫スナップショット</td>
                                    <td class="px-2 py-1.5">-</td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_order_candidates</td>
                                    <td class="px-2 py-1.5">外部発注候補</td>
                                    <td class="px-2 py-1.5"><a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-primary-500 hover:underline">一覧</a></td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_stock_transfer_candidates</td>
                                    <td class="px-2 py-1.5">内部移動候補</td>
                                    <td class="px-2 py-1.5"><a href="{{ route('filament.admin.resources.wms-stock-transfer-candidates.index') }}" class="text-primary-500 hover:underline">一覧</a></td>
                                </tr>
                                <tr>
                                    <td class="px-2 py-1.5 font-mono">wms_order_calculation_logs</td>
                                    <td class="px-2 py-1.5">計算ログ</td>
                                    <td class="px-2 py-1.5">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                {{-- データフロー図 --}}
                <x-filament::section class="xl:col-span-2">
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-arrow-path class="w-5 h-5 text-primary-500" />
                            データフロー
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 overflow-x-auto">
                        <pre class="text-xs whitespace-pre font-mono">
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                          マスタデータ                                                 │
├─────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ item_contractors                 発注点、仕入単位、自動発注フラグ                                      │
│   └─ safety_stock              ← wms_monthly_safety_stocks から同期                                │
│                                                                                                      │
│ contractors                      発注先マスタ                                                         │
│   └─ lead_time_id              → lead_times（リードタイム）                                          │
│                                                                                                      │
│ wms_contractor_warehouse_delivery_days    納品可能曜日（発注先×倉庫）                                 │
│ wms_warehouse_calendars                   倉庫カレンダー（休日設定）                                   │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                              │
                                              ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                          在庫データ                                                   │
├─────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ real_stocks                      実在庫（ロット単位）                                                  │
│   └─ wms_v_stock_available     有効在庫ビュー                                                        │
│                                                                                                      │
│ wms_order_incoming_schedules     入荷予定                                                             │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                              │
                                              ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                      スナップショット                                                  │
├─────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ wms_item_stock_snapshots                                                                             │
│   ├─ total_effective_piece     有効在庫                                                              │
│   └─ total_incoming_piece      入荷予定数                                                            │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                              │
                                              ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                        発注候補                                                       │
├─────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ wms_stock_transfer_candidates    内部移動候補（INTERNAL発注先）                                        │
│ wms_order_candidates             外部発注候補（EXTERNAL発注先）                                        │
│ wms_order_calculation_logs       計算ログ                                                             │
└─────────────────────────────────────────────────────────────────────────────────────────────────────┘</pre>
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- コマンドタブ --}}
        <div x-show="activeTab === 'commands'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                {{-- メイン計算コマンド --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-play class="w-5 h-5 text-success-500" />
                            メイン計算コマンド
                        </div>
                    </x-slot>
                    <div class="space-y-4">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="px-2 py-0.5 bg-success-100 dark:bg-success-900 text-success-700 dark:text-success-300 text-xs rounded font-bold">主要</span>
                                <code class="font-mono text-sm font-bold">wms:auto-order-calculate</code>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">在庫スナップショット生成 + 発注候補計算</p>
                            <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-xs">
                                <p class="text-green-600 dark:text-green-400">php artisan wms:auto-order-calculate</p>
                                <p class="text-gray-500 mt-1"># --skip-snapshot でスナップショット生成をスキップ</p>
                            </div>
                        </div>

                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs rounded font-bold">補助</span>
                                <code class="font-mono text-sm font-bold">wms:snapshot-stocks</code>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">在庫スナップショットのみ生成</p>
                            <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-xs">
                                <p class="text-green-600 dark:text-green-400">php artisan wms:snapshot-stocks</p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 月別発注点コマンド --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-arrow-path class="w-5 h-5 text-info-500" />
                            月別発注点同期
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="px-2 py-0.5 bg-info-100 dark:bg-info-900 text-info-700 dark:text-info-300 text-xs rounded font-bold">同期</span>
                            <code class="font-mono text-sm font-bold">wms:sync-monthly-safety-stocks</code>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">翌月の発注点を item_contractors.safety_stock に同期（毎月末日実行）</p>
                        <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-xs space-y-1">
                            <p class="text-green-600 dark:text-green-400">php artisan wms:sync-monthly-safety-stocks</p>
                            <p class="text-gray-500"># --month=4 で特定月を指定（省略時は翌月）</p>
                            <p class="text-gray-500"># --dry-run で確認のみ</p>
                        </div>
                    </div>
                </x-filament::section>

                {{-- カレンダーコマンド --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-calendar-days class="w-5 h-5 text-warning-500" />
                            カレンダー・休日
                        </div>
                    </x-slot>
                    <div class="space-y-3">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <code class="font-mono text-sm font-bold">wms:generate-calendars</code>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">倉庫別営業日カレンダーを生成</p>
                            <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-xs mt-2">
                                <p class="text-green-600 dark:text-green-400">php artisan wms:generate-calendars</p>
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                            <code class="font-mono text-sm font-bold">wms:import-holidays</code>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">日本の祝日データをインポート</p>
                            <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-xs mt-2">
                                <p class="text-green-600 dark:text-green-400">php artisan wms:import-holidays 2026</p>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                {{-- 発注送信コマンド --}}
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="w-5 h-5 text-danger-500" />
                            発注送信
                        </div>
                    </x-slot>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="px-2 py-0.5 bg-danger-100 dark:bg-danger-900 text-danger-700 dark:text-danger-300 text-xs rounded font-bold">送信</span>
                            <code class="font-mono text-sm font-bold">wms:transmit-orders</code>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">承認済み発注候補をJX-FINET/FTPで送信</p>
                        <div class="bg-white dark:bg-gray-900 rounded p-2 font-mono text-xs space-y-1">
                            <p class="text-green-600 dark:text-green-400">php artisan wms:transmit-orders</p>
                            <p class="text-gray-500"># --batch-code=CODE で特定バッチを指定</p>
                            <p class="text-gray-500"># --dry-run で確認のみ</p>
                        </div>
                    </div>
                </x-filament::section>

                {{-- スケジュール --}}
                <x-filament::section class="xl:col-span-2">
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-clock class="w-5 h-5 text-gray-500" />
                            スケジュール設定
                        </div>
                    </x-slot>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th class="px-3 py-2 text-left">コマンド</th>
                                    <th class="px-3 py-2 text-left">スケジュール</th>
                                    <th class="px-3 py-2 text-left">説明</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                <tr>
                                    <td class="px-3 py-2 font-mono">wms:sync-monthly-safety-stocks</td>
                                    <td class="px-3 py-2">毎月末日 04:30</td>
                                    <td class="px-3 py-2">翌月の発注点を同期</td>
                                </tr>
                                <tr>
                                    <td class="px-3 py-2 font-mono">wms:auto-order-calculate</td>
                                    <td class="px-3 py-2">毎日 05:00</td>
                                    <td class="px-3 py-2">発注候補計算</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 text-xs text-gray-500">
                        スケジュールは <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">routes/console.php</code> で管理
                    </div>
                </x-filament::section>
            </div>
        </div>
    </div>
</x-filament-panels::page></div>
