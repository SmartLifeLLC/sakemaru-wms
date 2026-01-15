<x-filament-panels::page>
    <div x-data="{ activeTab: 'overview' }" class="space-y-6">
        {{-- タブナビゲーション --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex flex-wrap gap-x-8" aria-label="Tabs">
                <button
                    @click="activeTab = 'overview'"
                    type="button"
                    :class="activeTab === 'overview' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-information-circle class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    概要・準備
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
                    @click="activeTab = 'supply'"
                    type="button"
                    :class="activeTab === 'supply' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-arrows-right-left class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    供給タイプ
                </button>
                <button
                    @click="activeTab = 'calculation'"
                    type="button"
                    :class="activeTab === 'calculation' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-calculator class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    計算ロジック
                </button>
                <button
                    @click="activeTab = 'tables'"
                    type="button"
                    :class="activeTab === 'tables' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200"
                >
                    <x-heroicon-o-circle-stack class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    テーブル情報
                </button>
            </nav>
        </div>

        {{-- 概要・準備タブ --}}
        <div x-show="activeTab === 'overview'" x-cloak class="space-y-6">
            {{-- システム概要 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-information-circle class="w-6 h-6 text-primary-500" />
                        システム概要
                    </div>
                </x-slot>
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    <p>
                        本システムは<strong>Multi-Echelon（多段階）供給ネットワーク</strong>に対応した自動発注システムです。
                        サテライト倉庫とハブ倉庫間の在庫移動、および外部発注先への発注を自動計算します。
                    </p>
                </div>
            </x-filament::section>

            {{-- 発注計算テストの流れ --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-clipboard-document-check class="w-6 h-6 text-success-500" />
                        発注計算テストの流れ
                    </div>
                </x-slot>
                <div class="space-y-4">
                    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-700 dark:text-gray-300">
                        <li>
                            <strong>マスタデータ確認</strong>（基幹システム）
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1 text-gray-600 dark:text-gray-400">
                                <li>倉庫マスタ（Hub + Satellite構成）</li>
                                <li>商品マスタ</li>
                                <li>発注先マスタ + 仕入れ契約</li>
                                <li>リードタイム（曜日別）</li>
                            </ul>
                        </li>
                        <li>
                            <strong>WMS設定</strong>
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1 text-gray-600 dark:text-gray-400">
                                <li>倉庫自動発注設定（有効化）</li>
                                <li>倉庫休日設定（定休日・祝日）</li>
                                <li>カレンダー生成</li>
                                <li>供給設定（商品×倉庫）</li>
                            </ul>
                        </li>
                        <li>
                            <strong>在庫データ準備</strong>
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1 text-gray-600 dark:text-gray-400">
                                <li>real_stocks に在庫を登録</li>
                                <li>安全在庫を下回る状態を作成</li>
                            </ul>
                        </li>
                        <li>
                            <strong>計算実行</strong>
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1 text-gray-600 dark:text-gray-400">
                                <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">php artisan wms:auto-order-calculate</code></li>
                            </ul>
                        </li>
                        <li>
                            <strong>結果確認</strong>
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1 text-gray-600 dark:text-gray-400">
                                <li>発注候補一覧</li>
                                <li>移動候補一覧</li>
                                <li>計算ログ</li>
                            </ul>
                        </li>
                    </ol>
                </div>
            </x-filament::section>

            {{-- 必要なデータ一覧 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-table-cells class="w-6 h-6 text-warning-500" />
                        必要なデータ一覧
                    </div>
                </x-slot>
                <div class="space-y-6">
                    {{-- 基幹システムデータ --}}
                    <div>
                        <h4 class="font-bold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
                            <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 text-xs rounded">基幹システム</span>
                            マスタデータ
                        </h4>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-100 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-3 py-2 text-left">テーブル</th>
                                        <th class="px-3 py-2 text-left">必須</th>
                                        <th class="px-3 py-2 text-left">説明</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">warehouses</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">倉庫マスタ（Hub/Satellite構成）</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">items</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">商品マスタ</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">contractors</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">発注先マスタ</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">item_contractors</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">商品×発注先の仕入れ契約</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">lead_times</td>
                                        <td class="px-3 py-2"><span class="text-warning-500">推奨</span></td>
                                        <td class="px-3 py-2">曜日別リードタイム（外部発注時）</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">real_stocks</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">在庫データ</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- WMS設定データ --}}
                    <div>
                        <h4 class="font-bold text-gray-800 dark:text-gray-200 mb-3 flex items-center gap-2">
                            <span class="px-2 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 text-xs rounded">WMS</span>
                            設定データ
                        </h4>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-100 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-3 py-2 text-left">テーブル</th>
                                        <th class="px-3 py-2 text-left">必須</th>
                                        <th class="px-3 py-2 text-left">説明</th>
                                        <th class="px-3 py-2 text-left">管理画面</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">wms_warehouse_auto_order_settings</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">倉庫の自動発注ON/OFF</td>
                                        <td class="px-3 py-2"><span class="text-gray-500">（リソース未作成）</span></td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">wms_warehouse_holiday_settings</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">定休日・祝日休業設定</td>
                                        <td class="px-3 py-2"><span class="text-gray-500">（リソース未作成）</span></td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">wms_warehouse_calendars</td>
                                        <td class="px-3 py-2"><span class="text-danger-500">必須</span></td>
                                        <td class="px-3 py-2">営業日カレンダー（生成必要）</td>
                                        <td class="px-3 py-2"><a href="{{ route('filament.admin.resources.wms-warehouse-calendars.index') }}" class="text-primary-500 hover:underline">倉庫カレンダー</a></td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">wms_national_holidays</td>
                                        <td class="px-3 py-2"><span class="text-warning-500">推奨</span></td>
                                        <td class="px-3 py-2">祝日マスタ</td>
                                        <td class="px-3 py-2"><span class="text-gray-500">（リソース未作成）</span></td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs">wms_contractor_holidays</td>
                                        <td class="px-3 py-2"><span class="text-gray-500">任意</span></td>
                                        <td class="px-3 py-2">発注先の臨時休業</td>
                                        <td class="px-3 py-2"><a href="{{ route('filament.admin.resources.wms-contractor-holidays.index') }}" class="text-primary-500 hover:underline">発注先休日</a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- コマンド一覧 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-command-line class="w-6 h-6 text-info-500" />
                        関連コマンド
                    </div>
                </x-slot>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left">コマンド</th>
                                <th class="px-3 py-2 text-left">説明</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">php artisan wms:auto-order-calculate</td>
                                <td class="px-3 py-2">発注計算を実行（スナップショット + 多段階計算）</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">php artisan wms:snapshot-stocks</td>
                                <td class="px-3 py-2">在庫スナップショットのみ生成</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">php artisan wms:generate-calendars</td>
                                <td class="px-3 py-2">営業日カレンダー生成（--warehouse=ID --months=12）</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">php artisan wms:import-holidays</td>
                                <td class="px-3 py-2">祝日マスタをインポート</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">php artisan wms:transmit-orders</td>
                                <td class="px-3 py-2">承認済み発注をJX/FTP送信</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- 計算フロータブ --}}
        <div x-show="activeTab === 'flow'" x-cloak class="space-y-6">
            {{-- Multi-Echelon計算フロー --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-arrow-path class="w-6 h-6 text-primary-500" />
                        Multi-Echelon 計算フロー
                    </div>
                </x-slot>
                <div class="space-y-4">
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <pre class="text-xs overflow-x-auto whitespace-pre">
┌──────────────────────────────────────────────────────────────────┐
│                    Multi-Echelon 計算フロー                        │
└──────────────────────────────────────────────────────────────────┘

Level 0 (最下流: サテライト倉庫)
    │
    ├─→ 在庫チェック → 不足発見 → 移動候補作成
    │                              │
    │                              ▼
    │                    内部移動需要として上位へ伝播
    │
Level 1 (ハブ倉庫)
    │
    ├─→ 在庫チェック + 下位からの需要 → 不足発見 → 発注候補作成
    │
    ▼
最終的な発注候補・移動候補が確定</pre>
                    </div>
                </div>
            </x-filament::section>

            {{-- 詳細実行フロー --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-queue-list class="w-6 h-6 text-primary-500" />
                        詳細実行フロー
                    </div>
                </x-slot>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                    <pre class="text-xs overflow-x-auto whitespace-pre">
wms:auto-order-calculate [--skip-snapshot]
    │
    ▼
┌─────────────────────────────────────────────────────────────┐
│ Phase 0: StockSnapshotService::generateAll()                │
├─────────────────────────────────────────────────────────────┤
│  1. WmsWarehouseAutoOrderSetting::enabled() で有効倉庫取得   │
│  2. wms_v_stock_available から倉庫×商品別に集計             │
│  3. WmsWarehouseItemTotalStock に UPSERT                    │
└─────────────────────────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────────────────────────┐
│ Phase 1: MultiEchelonCalculationService::calculateAll()     │
├─────────────────────────────────────────────────────────────┤
│  for (level = 0 to maxLevel)                                │
│    └─ calculateLevel($level)                                │
│        └─ WmsItemSupplySetting::enabled()->atLevel($level)  │
│            └─ for each setting                              │
│                ├─ 在庫取得: WmsWarehouseItemTotalStock      │
│                ├─ 内部需要取得: getInternalDemand()         │
│                ├─ 到着日計算: calculateArrivalDate()        │
│                ├─ 必要数計算                                │
│                │                                            │
│                ├─ if (INTERNAL) → 移動候補作成              │
│                │               → 需要を上位へ伝播           │
│                └─ if (EXTERNAL) → 発注候補作成              │
└─────────────────────────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────────────────────────┐
│ 結果出力                                                     │
├─────────────────────────────────────────────────────────────┤
│  • wms_order_candidates (外部発注候補)                       │
│  • wms_stock_transfer_candidates (倉庫間移動候補)            │
│  • wms_order_calculation_logs (計算ログ)                     │
└─────────────────────────────────────────────────────────────┘</pre>
                </div>
            </x-filament::section>

            {{-- 需要伝播の仕組み --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-arrows-pointing-out class="w-6 h-6 text-primary-500" />
                        内部需要の伝播（Multi-Echelonの核心）
                    </div>
                </x-slot>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                    <pre class="text-xs overflow-x-auto whitespace-pre">
┌─────────────────────────────────────────────────────────────┐
│ 例: Satellite A (Level 0) が Hub (Level 1) から補充する場合  │
└─────────────────────────────────────────────────────────────┘

Level 0 (Satellite A):
    有効在庫: 50
    安全在庫: 100
    必要数 = 100 - 50 = 50

    → TransferCandidate(qty=50) 作成
    → addInternalDemand(Hub, item, 50)  ← 需要を記録

                    │
                    ▼

Level 1 (Hub):
    有効在庫: 200
    安全在庫: 150
    internalDemand = 50  ← Satelliteからの需要

    total_required = 150 + 50 - 200 = 0

    → 発注不要（在庫で賄える）

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Level 0 (Satellite A):
    有効在庫: 50
    安全在庫: 100
    必要数 = 100 - 50 = 50

    → TransferCandidate(qty=50) 作成
    → addInternalDemand(Hub, item, 50)

Level 1 (Hub):
    有効在庫: 80
    安全在庫: 100
    internalDemand = 50
    self_shortage = 100 - 80 = 20

    total_required = 20 + 50 = 70

    → OrderCandidate(qty=70) 作成</pre>
                </div>
            </x-filament::section>
        </div>

        {{-- 供給タイプタブ --}}
        <div x-show="activeTab === 'supply'" x-cloak class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-arrows-right-left class="w-6 h-6 text-primary-500" />
                        供給タイプ
                    </div>
                </x-slot>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                        <h4 class="font-bold text-blue-700 dark:text-blue-300 mb-2">INTERNAL（内部移動）</h4>
                        <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                            <li>• ハブ倉庫からサテライト倉庫への移動</li>
                            <li>• 固定リードタイム（日数）を使用</li>
                            <li>• 倉庫休日のみ考慮</li>
                        </ul>
                        <div class="mt-3 pt-3 border-t border-blue-200 dark:border-blue-700">
                            <p class="text-xs text-blue-600 dark:text-blue-400">
                                <strong>出力:</strong> wms_stock_transfer_candidates
                            </p>
                        </div>
                    </div>
                    <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                        <h4 class="font-bold text-green-700 dark:text-green-300 mb-2">EXTERNAL（外部発注）</h4>
                        <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                            <li>• 発注先（問屋等）への発注</li>
                            <li>• 曜日別リードタイムを使用</li>
                            <li>• 発注先臨時休業 + 倉庫休日を考慮</li>
                        </ul>
                        <div class="mt-3 pt-3 border-t border-green-200 dark:border-green-700">
                            <p class="text-xs text-green-600 dark:text-green-400">
                                <strong>出力:</strong> wms_order_candidates
                            </p>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- 階層レベルの説明 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-building-office-2 class="w-6 h-6 text-primary-500" />
                        階層レベル（Hierarchy Level）
                    </div>
                </x-slot>
                <div class="space-y-4">
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <ul class="space-y-2 text-sm">
                            <li><strong>Level 0</strong>: 最下流（Satellite倉庫）- 内部移動で補充される倉庫</li>
                            <li><strong>Level 1+</strong>: 上流（Hub倉庫）- 外部発注で補充される倉庫</li>
                        </ul>
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        <p><strong>自動計算:</strong> 供給設定保存時に <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">recalculateHierarchyLevels()</code> が実行され、階層レベルが自動設定されます。</p>
                    </div>
                </div>
            </x-filament::section>

            {{-- 仮想倉庫の在庫管理 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cube-transparent class="w-6 h-6 text-primary-500" />
                        仮想倉庫の在庫管理
                    </div>
                </x-slot>
                <div class="space-y-4">
                    <div class="prose prose-sm max-w-none dark:prose-invert">
                        <p>
                            倉庫には<strong>実倉庫</strong>（<code>is_virtual=false</code>）と<strong>仮想倉庫</strong>（<code>is_virtual=true</code>）が存在します。
                            仮想倉庫は <code>stock_warehouse_id</code> で親となる実倉庫を参照します。
                        </p>
                    </div>

                    <h4 class="font-bold text-gray-800 dark:text-gray-200">基本方針</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-4 border border-purple-200 dark:border-purple-800">
                            <h5 class="font-bold text-purple-700 dark:text-purple-300 mb-2">1. 仮想倉庫も在庫を持てる</h5>
                            <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                                <li>• 仮想倉庫でも <code class="text-xs bg-gray-200 dark:bg-gray-700 px-1 rounded">real_stocks</code> に在庫を持てる</li>
                                <li>• 在庫引当・出荷は仮想倉庫単位で実行</li>
                            </ul>
                        </div>
                        <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-lg p-4 border border-indigo-200 dark:border-indigo-800">
                            <h5 class="font-bold text-indigo-700 dark:text-indigo-300 mb-2">2. 発注は実倉庫に集約</h5>
                            <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                                <li>• 仮想倉庫の需要は親の実倉庫に集約</li>
                                <li>• 発注先への発注は実倉庫単位で実行</li>
                            </ul>
                        </div>
                        <div class="bg-teal-50 dark:bg-teal-900/20 rounded-lg p-4 border border-teal-200 dark:border-teal-800">
                            <h5 class="font-bold text-teal-700 dark:text-teal-300 mb-2">3. 入荷時の在庫振り分け</h5>
                            <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                                <li>• 物理的な入荷は実倉庫で実施</li>
                                <li>• 発注内容に基づき仮想倉庫に振り分け</li>
                                <li>• 入荷予定は仮想倉庫単位で作成</li>
                            </ul>
                        </div>
                        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
                            <h5 class="font-bold text-amber-700 dark:text-amber-300 mb-2">4. HUB倉庫からの横持ち</h5>
                            <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                                <li>• HUB倉庫依頼分は拠点倉庫側で入荷</li>
                                <li>• 移動候補の入荷予定は依頼元仮想倉庫で作成</li>
                            </ul>
                        </div>
                    </div>

                    <h4 class="font-bold text-gray-800 dark:text-gray-200 mt-4">処理フロー</h4>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <pre class="text-xs overflow-x-auto whitespace-pre">
【発注計算時】
仮想倉庫A (需要50) ─┐
仮想倉庫B (需要30) ─┼→ 実倉庫X (集約需要80) → 発注候補80個
仮想倉庫C (需要0)  ─┘

【発注確定時】
発注80個 (実倉庫X)
    │
    ├→ 入荷予定: 仮想倉庫A 50個
    ├→ 入荷予定: 仮想倉庫B 30個
    └→ (仮想倉庫Cは需要0のため対象外)

【入荷時】
実倉庫Xに80個入荷（物理）
    │
    ├→ 仮想倉庫A在庫 +50
    └→ 仮想倉庫B在庫 +30</pre>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- 計算ロジックタブ --}}
        <div x-show="activeTab === 'calculation'" x-cloak class="space-y-6">
            {{-- リードタイム計算 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-clock class="w-6 h-6 text-primary-500" />
                        リードタイム計算
                    </div>
                </x-slot>
                <div class="space-y-4">
                    <h4 class="font-bold text-gray-800 dark:text-gray-200">外部発注の場合</h4>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <ol class="list-decimal list-inside space-y-2 text-sm">
                            <li><strong>曜日別リードタイム取得</strong>: 発注先の <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">lead_times</code> テーブルから発注日の曜日に対応するリードタイムを取得</li>
                            <li><strong>到着予定日計算</strong>: 発注日 + リードタイム日数</li>
                            <li><strong>発注先休業日スキップ</strong>: 到着予定日が発注先の臨時休業日の場合、翌日にシフト</li>
                            <li><strong>倉庫休日スキップ</strong>: 到着予定日が倉庫の休日の場合、翌営業日にシフト</li>
                        </ol>
                    </div>

                    <h4 class="font-bold text-gray-800 dark:text-gray-200 mt-4">内部移動の場合</h4>
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <ol class="list-decimal list-inside space-y-2 text-sm">
                            <li><strong>固定リードタイム使用</strong>: 供給設定のリードタイム日数</li>
                            <li><strong>到着予定日計算</strong>: 発注日 + リードタイム日数</li>
                            <li><strong>倉庫休日スキップ</strong>: 到着予定日が倉庫の休日の場合、翌営業日にシフト</li>
                        </ol>
                    </div>

                    <h4 class="font-bold text-gray-800 dark:text-gray-200 mt-4">日付シフト時の消費加算</h4>
                    <div class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-4 border border-warning-200 dark:border-warning-700">
                        <p class="text-sm text-warning-700 dark:text-warning-300">
                            到着日がシフトした場合、シフト日数分の追加消費を必要数に加算します。
                        </p>
                        <div class="mt-2 font-mono text-xs bg-white dark:bg-gray-900 p-2 rounded">
                            additional = daily_consumption_qty × (final_date - original_date)
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- 必要数計算式 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-calculator class="w-6 h-6 text-primary-500" />
                        必要数計算式
                    </div>
                </x-slot>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                    <div class="font-mono text-sm bg-white dark:bg-gray-900 p-4 rounded border">
                        <p class="mb-2"><strong>必要数 = </strong></p>
                        <p class="ml-4">(安全在庫 + リードタイム中消費予測 + 下位階層からの需要)</p>
                        <p class="ml-4">- (有効在庫 + 入荷予定数)</p>
                    </div>
                    <div class="mt-4 text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <p><strong>リードタイム中消費予測</strong> = daily_consumption_qty × lead_time_days</p>
                        <p><strong>下位階層からの需要</strong> = Satellite倉庫からHub倉庫への移動需要（Multi-Echelon）</p>
                        <p><strong>発注条件</strong>: 必要数 > 0 の場合のみ候補を作成</p>
                    </div>
                </div>
            </x-filament::section>

            {{-- ステータス説明 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-tag class="w-6 h-6 text-primary-500" />
                        候補ステータス
                    </div>
                </x-slot>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h4 class="font-bold text-gray-800 dark:text-gray-200 mb-2">CandidateStatus</h4>
                        <ul class="text-sm space-y-1">
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">PENDING</code> - 未承認</li>
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">APPROVED</code> - 承認済</li>
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">EXCLUDED</code> - 除外</li>
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">EXECUTED</code> - 実行済</li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="font-bold text-gray-800 dark:text-gray-200 mb-2">LotStatus</h4>
                        <ul class="text-sm space-y-1">
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">RAW</code> - 未適用</li>
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">ADJUSTED</code> - 調整済</li>
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">BLOCKED</code> - ブロック</li>
                            <li><code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">NEED_APPROVAL</code> - 要承認</li>
                        </ul>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- テーブル情報タブ --}}
        <div x-show="activeTab === 'tables'" x-cloak class="space-y-6">
            {{-- 関連テーブル --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-circle-stack class="w-6 h-6 text-primary-500" />
                        関連テーブル
                    </div>
                </x-slot>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">テーブル</th>
                                <th class="px-4 py-2 text-left">用途</th>
                                <th class="px-4 py-2 text-left">管理画面</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                            <tr>
                                <td class="px-4 py-2 font-mono">wms_warehouse_calendars</td>
                                <td class="px-4 py-2">倉庫の休日設定</td>
                                <td class="px-4 py-2"><a href="{{ route('filament.admin.resources.wms-warehouse-calendars.index') }}" class="text-primary-500 hover:underline">倉庫カレンダー</a></td>
                            </tr>
                            <tr>
                                <td class="px-4 py-2 font-mono">wms_contractor_holidays</td>
                                <td class="px-4 py-2">発注先の臨時休業日</td>
                                <td class="px-4 py-2"><a href="{{ route('filament.admin.resources.wms-contractor-holidays.index') }}" class="text-primary-500 hover:underline">発注先休日</a></td>
                            </tr>
                            <tr>
                                <td class="px-4 py-2 font-mono">lead_times</td>
                                <td class="px-4 py-2">発注先の曜日別リードタイム</td>
                                <td class="px-4 py-2"><span class="text-gray-500">基幹システム管理</span></td>
                            </tr>
                            <tr>
                                <td class="px-4 py-2 font-mono">wms_order_candidates</td>
                                <td class="px-4 py-2">発注候補（計算結果）</td>
                                <td class="px-4 py-2"><a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-primary-500 hover:underline">発注候補一覧</a></td>
                            </tr>
                            <tr>
                                <td class="px-4 py-2 font-mono">wms_stock_transfer_candidates</td>
                                <td class="px-4 py-2">移動候補（計算結果）</td>
                                <td class="px-4 py-2"><a href="{{ route('filament.admin.resources.wms-stock-transfer-candidates.index') }}" class="text-primary-500 hover:underline">移動候補一覧</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            {{-- 計算結果の確認 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-document-magnifying-glass class="w-6 h-6 text-primary-500" />
                        計算結果の確認
                    </div>
                </x-slot>
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    <p>計算結果は <code>wms_order_calculation_logs</code> テーブルに記録されます。</p>
                    <ul>
                        <li><strong>calculation_details</strong> カラムに詳細情報が JSON 形式で保存</li>
                        <li>休日によるシフト日数、シフト理由なども記録</li>
                        <li>階層レベル、内部需要の情報も含む</li>
                    </ul>
                </div>
            </x-filament::section>

            {{-- ジョブ管理 --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cog-6-tooth class="w-6 h-6 text-primary-500" />
                        ジョブ管理
                    </div>
                </x-slot>
                <div class="prose prose-sm max-w-none dark:prose-invert">
                    <p>バッチ実行は <code>wms_auto_order_job_controls</code> テーブルで管理されます。</p>
                    <ul>
                        <li><strong>process_name</strong>: STOCK_SNAPSHOT, SATELLITE_CALC, HUB_CALC, ORDER_TRANSMISSION</li>
                        <li><strong>batch_code</strong>: YYYYMMDDHHmmss形式の一意コード</li>
                        <li><strong>status</strong>: PENDING → RUNNING → SUCCESS/FAILED</li>
                    </ul>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
