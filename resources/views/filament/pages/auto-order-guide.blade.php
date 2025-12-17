<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 概要セクション --}}
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

        {{-- 計算フロー --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-arrow-path class="w-6 h-6 text-primary-500" />
                    計算フロー
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

        {{-- 供給タイプ --}}
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
                </div>
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
                    <h4 class="font-bold text-green-700 dark:text-green-300 mb-2">EXTERNAL（外部発注）</h4>
                    <ul class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                        <li>• 発注先（問屋等）への発注</li>
                        <li>• 曜日別リードタイムを使用</li>
                        <li>• 発注先臨時休業 + 倉庫休日を考慮</li>
                    </ul>
                </div>
            </div>
        </x-filament::section>

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
                        <li><strong>固定リードタイム使用</strong>: <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">wms_item_supply_settings.lead_time_days</code></li>
                        <li><strong>到着予定日計算</strong>: 発注日 + リードタイム日数</li>
                        <li><strong>倉庫休日スキップ</strong>: 到着予定日が倉庫の休日の場合、翌営業日にシフト</li>
                    </ol>
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
                <div class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                    <p><strong>リードタイム中消費予測</strong> = 1日あたり消費数 × リードタイム日数</p>
                    <p class="mt-1"><strong>発注条件</strong>: 必要数 > 0 の場合のみ発注候補を作成</p>
                </div>
            </div>
        </x-filament::section>

        {{-- テーブル関連図 --}}
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
                            <td class="px-4 py-2 font-mono">wms_item_supply_settings</td>
                            <td class="px-4 py-2">商品×倉庫ごとの供給設定</td>
                            <td class="px-4 py-2"><a href="{{ route('filament.admin.resources.wms-item-supply-settings.index') }}" class="text-primary-500 hover:underline">供給設定</a></td>
                        </tr>
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
                </ul>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
