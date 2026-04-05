<div class="livewire-root"><x-filament-panels::page>
    <div x-data="{ activeTab: 'overview', menuTab: 'generation' }" class="h-[calc(100vh-120px)] flex flex-col">
        {{-- タブナビゲーション --}}
        <div class="border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            <nav class="-mb-px flex flex-wrap gap-x-6" aria-label="Tabs">
                <button @click="activeTab = 'overview'" type="button"
                    :class="activeTab === 'overview' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">
                    <x-heroicon-o-information-circle class="w-4 h-4 inline-block mr-1 -mt-0.5" />概要
                </button>
                <button @click="activeTab = 'menus'" type="button"
                    :class="activeTab === 'menus' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">
                    <x-heroicon-o-squares-2x2 class="w-4 h-4 inline-block mr-1 -mt-0.5" />メニュー詳細
                </button>
                <button @click="activeTab = 'settings'" type="button"
                    :class="activeTab === 'settings' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm">
                    <x-heroicon-o-cog-6-tooth class="w-4 h-4 inline-block mr-1 -mt-0.5" />設定
                </button>
            </nav>
        </div>

        {{-- 概要タブ --}}
        <div x-show="activeTab === 'overview'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {{-- 全体の流れ --}}
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <h3 class="font-bold text-sm mb-3 flex items-center gap-2">
                        <x-heroicon-o-arrow-path class="w-4 h-4 text-primary-500" />全体の流れ
                    </h3>
                    <div class="space-y-2 text-xs">
                        @foreach([
                            ['1', '在庫スナップショット', 'blue'],
                            ['2', '候補計算', 'blue'],
                            ['3', '移動候補 確認・承認', 'cyan'],
                            ['4', '発注候補 確認・承認', 'green'],
                            ['5', '発注・移動確定', 'orange'],
                            ['6', 'JX送信', 'purple'],
                        ] as [$num, $label, $color])
                        <div class="flex items-center gap-2 p-2 bg-{{ $color }}-50 dark:bg-{{ $color }}-900/20 rounded">
                            <span class="w-5 h-5 rounded-full bg-{{ $color }}-500 text-white flex items-center justify-center text-xs font-bold">{{ $num }}</span>
                            <span>{{ $label }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- 移動と発注の関係 --}}
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <h3 class="font-bold text-sm mb-3 flex items-center gap-2">
                        <x-heroicon-o-arrows-right-left class="w-4 h-4 text-primary-500" />移動と発注の関係
                    </h3>
                    <div class="space-y-2 text-xs">
                        <div class="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-800 rounded">
                            <div class="text-center">
                                <x-heroicon-o-building-office-2 class="w-5 h-5 text-cyan-500 mx-auto" />
                                <div class="text-[10px]">Satellite</div>
                            </div>
                            <x-heroicon-o-arrow-left class="w-4 h-4 text-gray-400" />
                            <div class="text-center">
                                <x-heroicon-o-building-office class="w-5 h-5 text-blue-500 mx-auto" />
                                <div class="text-[10px]">Hub</div>
                            </div>
                        </div>
                        <div class="p-2 bg-cyan-50 dark:bg-cyan-900/20 rounded border-l-2 border-cyan-500">
                            <span class="font-semibold">移動候補</span>: Hub→Satellite移動
                        </div>
                        <div class="p-2 bg-green-50 dark:bg-green-900/20 rounded border-l-2 border-green-500">
                            <span class="font-semibold">発注候補</span>: 問屋への発注
                        </div>
                        <div class="p-2 bg-warning-50 dark:bg-warning-900/20 rounded text-warning-700 dark:text-warning-300">
                            <x-heroicon-o-exclamation-triangle class="w-3 h-3 inline" />
                            移動数変更→発注数自動再計算
                        </div>
                    </div>
                </div>

                {{-- ステータス --}}
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <h3 class="font-bold text-sm mb-3 flex items-center gap-2">
                        <x-heroicon-o-tag class="w-4 h-4 text-primary-500" />ステータス
                    </h3>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        @foreach([
                            ['未承認', 'gray', '確認待ち'],
                            ['承認済', 'green', '確定待ち'],
                            ['除外', 'red', '実行しない'],
                            ['確定済', 'blue', '入荷予定作成'],
                            ['送信済', 'purple', 'JX送信完了'],
                        ] as [$status, $color, $desc])
                        <div class="flex items-center gap-2 p-1.5 bg-{{ $color }}-50 dark:bg-{{ $color }}-900/20 rounded">
                            <span class="w-2 h-2 rounded-full bg-{{ $color }}-500"></span>
                            <div><span class="font-semibold">{{ $status }}</span><span class="text-gray-500 ml-1">{{ $desc }}</span></div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 関連画面リンク --}}
            <div class="mt-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2">
                @foreach([
                    ['発注・移動候補生成', 'wms-auto-order-job-controls', 'heroicon-o-queue-list', 'blue'],
                    ['移動候補一覧', 'wms-stock-transfer-candidates', 'heroicon-o-arrows-right-left', 'cyan'],
                    ['発注候補一覧', 'wms-order-candidates', 'heroicon-o-shopping-cart', 'green'],
                    ['確定待ち', 'wms-order-confirmation-waiting', 'heroicon-o-clipboard-document-check', 'orange'],
                    ['発注確定済み', 'wms-order-confirmed', 'heroicon-o-check-badge', 'blue'],
                    ['発注ファイル', 'wms-order-data-files', 'heroicon-o-document-text', 'green'],
                    ['JX送信', 'wms-order-documents', 'heroicon-o-paper-airplane', 'purple'],
                ] as [$label, $route, $icon, $color])
                <a href="{{ route('filament.admin.resources.'.$route.'.index') }}"
                   class="p-2 bg-{{ $color }}-50 dark:bg-{{ $color }}-900/20 rounded border border-{{ $color }}-200 dark:border-{{ $color }}-800 hover:bg-{{ $color }}-100 dark:hover:bg-{{ $color }}-900/30 text-center text-xs">
                    <x-dynamic-component :component="$icon" class="w-4 h-4 mx-auto mb-1 text-{{ $color }}-500" />
                    {{ $label }}
                </a>
                @endforeach
            </div>
        </div>

        {{-- メニュー詳細タブ --}}
        <div x-show="activeTab === 'menus'" x-cloak class="flex-1 overflow-y-auto pt-3">
            {{-- サブタブ --}}
            <div class="flex flex-wrap gap-1 mb-3">
                @foreach([
                    ['generation', '候補生成', 'blue'],
                    ['transfer', '移動候補', 'cyan'],
                    ['order', '発注候補', 'green'],
                    ['confirm', '確定待ち', 'orange'],
                    ['history', '履歴', 'purple'],
                ] as [$key, $label, $color])
                <button @click="menuTab = '{{ $key }}'" type="button"
                    :class="menuTab === '{{ $key }}' ? 'bg-{{ $color }}-500 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200'"
                    class="px-3 py-1.5 rounded text-xs font-medium transition-colors">
                    {{ $label }}
                </button>
                @endforeach
            </div>

            {{-- 候補生成 --}}
            <div x-show="menuTab === 'generation'" class="space-y-3">
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-sm flex items-center gap-2">
                            <x-heroicon-o-queue-list class="w-4 h-4 text-blue-500" />発注・移動候補生成
                        </h3>
                        <a href="{{ route('filament.admin.resources.wms-auto-order-job-controls.index') }}" class="text-xs text-blue-600 hover:underline">画面を開く →</a>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">在庫スナップショットを取得し、移動候補・発注候補を生成するジョブを実行・管理します。</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                        <div class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-blue-500 text-white rounded text-[10px] whitespace-nowrap">候補計算実行</span>
                            <span class="text-gray-600 dark:text-gray-400">スナップショット取得→候補生成</span>
                        </div>
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-gray-500 text-white rounded text-[10px] whitespace-nowrap">結果確認</span>
                            <span class="text-gray-600 dark:text-gray-400">生成件数・エラー情報を表示</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 移動候補 --}}
            <div x-show="menuTab === 'transfer'" class="space-y-3">
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-sm flex items-center gap-2">
                            <x-heroicon-o-arrows-right-left class="w-4 h-4 text-cyan-500" />移動候補一覧
                        </h3>
                        <a href="{{ route('filament.admin.resources.wms-stock-transfer-candidates.index') }}" class="text-xs text-blue-600 hover:underline">画面を開く →</a>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">Hub倉庫からSatellite倉庫への倉庫間移動候補を確認・承認します。</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                        <div class="p-2 bg-green-50 dark:bg-green-900/20 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-green-500 text-white rounded text-[10px]">承認</span>
                            <span class="text-gray-600 dark:text-gray-400">移動を実行する</span>
                        </div>
                        <div class="p-2 bg-red-50 dark:bg-red-900/20 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-red-500 text-white rounded text-[10px]">除外</span>
                            <span class="text-gray-600 dark:text-gray-400">移動しない（理由入力可）</span>
                        </div>
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-gray-500 text-white rounded text-[10px]">詳細</span>
                            <span class="text-gray-600 dark:text-gray-400">計算根拠・在庫状況</span>
                        </div>
                    </div>
                    <div class="mt-2 p-2 bg-warning-50 dark:bg-warning-900/20 rounded text-xs text-warning-700 dark:text-warning-300">
                        <x-heroicon-o-exclamation-triangle class="w-3 h-3 inline" />
                        移動数量を変更すると、関連する発注候補の数量も自動再計算されます。
                    </div>
                </div>
            </div>

            {{-- 発注候補 --}}
            <div x-show="menuTab === 'order'" class="space-y-3">
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-sm flex items-center gap-2">
                            <x-heroicon-o-shopping-cart class="w-4 h-4 text-green-500" />発注候補一覧
                        </h3>
                        <a href="{{ route('filament.admin.resources.wms-order-candidates.index') }}" class="text-xs text-blue-600 hover:underline">画面を開く →</a>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">問屋への発注候補を確認・承認。発注数量や入荷予定日を変更可能。</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs">
                        <div class="p-2 bg-green-50 dark:bg-green-900/20 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-green-500 text-white rounded text-[10px]">承認</span>
                            <span class="text-gray-600 dark:text-gray-400">発注を実行する</span>
                        </div>
                        <div class="p-2 bg-red-50 dark:bg-red-900/20 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-red-500 text-white rounded text-[10px]">除外</span>
                            <span class="text-gray-600 dark:text-gray-400">発注しない（理由入力可）</span>
                        </div>
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded flex items-start gap-2">
                            <span class="px-2 py-0.5 bg-gray-500 text-white rounded text-[10px]">詳細</span>
                            <span class="text-gray-600 dark:text-gray-400">数量・入荷予定日変更</span>
                        </div>
                    </div>
                    <div class="mt-2 p-2 bg-info-50 dark:bg-info-900/20 rounded text-xs text-info-700 dark:text-info-300">
                        <x-heroicon-o-information-circle class="w-3 h-3 inline" />
                        テーブル上で直接「発注数」「入荷予定」を編集可能（承認前のみ）
                    </div>
                    <div class="mt-2 p-2 bg-danger-50 dark:bg-danger-900/20 rounded text-xs text-danger-700 dark:text-danger-300">
                        <x-heroicon-o-exclamation-triangle class="w-3 h-3 inline" />
                        移動候補に未承認があると承認できません
                    </div>
                </div>
            </div>

            {{-- 確定待ち --}}
            <div x-show="menuTab === 'confirm'" class="space-y-3">
                <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-sm flex items-center gap-2">
                            <x-heroicon-o-clipboard-document-check class="w-4 h-4 text-orange-500" />発注・移動確定待ち
                        </h3>
                        <a href="{{ route('filament.admin.resources.wms-order-confirmation-waiting.index') }}" class="text-xs text-blue-600 hover:underline">画面を開く →</a>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">承認済みの候補を一括確定。入荷予定・移動伝票・発注ファイルを作成。</p>
                    <div class="grid grid-cols-2 gap-2 text-xs mb-3">
                        <div class="p-2 bg-orange-50 dark:bg-orange-900/20 rounded border border-orange-200 dark:border-orange-800 text-center">
                            <div class="font-semibold text-orange-700 dark:text-orange-300">発注確定待ちタブ</div>
                            <div class="text-gray-500">承認済み発注候補</div>
                        </div>
                        <div class="p-2 bg-cyan-50 dark:bg-cyan-900/20 rounded border border-cyan-200 dark:border-cyan-800 text-center">
                            <div class="font-semibold text-cyan-700 dark:text-cyan-300">移動確定待ちタブ</div>
                            <div class="text-gray-500">承認済み移動候補</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                        <div class="p-2 bg-orange-50 dark:bg-orange-900/20 rounded">
                            <span class="px-2 py-0.5 bg-orange-500 text-white rounded text-[10px]">発注・移動確定</span>
                            <div class="mt-1 text-gray-600 dark:text-gray-400">入荷予定・移動伝票・CSVファイル作成</div>
                        </div>
                        <div class="p-2 bg-info-50 dark:bg-info-900/20 rounded">
                            <span class="px-2 py-0.5 bg-info-500 text-white rounded text-[10px]">テストデータ生成</span>
                            <div class="mt-1 text-gray-600 dark:text-gray-400">プレビュー用CSV（JX送信不可）</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 履歴 --}}
            <div x-show="menuTab === 'history'" class="space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    {{-- 発注確定済み --}}
                    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-bold text-sm flex items-center gap-2">
                                <x-heroicon-o-check-badge class="w-4 h-4 text-blue-500" />発注確定済み
                            </h3>
                            <a href="{{ route('filament.admin.resources.wms-order-confirmed.index') }}" class="text-xs text-blue-600 hover:underline">→</a>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">確定済み・送信済みの発注履歴</p>
                        <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded text-xs">
                            <span class="px-2 py-0.5 bg-gray-500 text-white rounded text-[10px]">詳細</span>
                            <span class="ml-1 text-gray-600">計算根拠・確定情報</span>
                        </div>
                    </div>

                    {{-- 発注データファイル --}}
                    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-bold text-sm flex items-center gap-2">
                                <x-heroicon-o-document-text class="w-4 h-4 text-green-500" />発注ファイル
                            </h3>
                            <a href="{{ route('filament.admin.resources.wms-order-data-files.index') }}" class="text-xs text-blue-600 hover:underline">→</a>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">確定で生成されたCSVファイル</p>
                        <div class="flex gap-1 text-xs mb-2">
                            <span class="px-2 py-0.5 bg-primary-100 text-primary-700 rounded">本番</span>
                            <span class="px-2 py-0.5 bg-info-100 text-info-700 rounded">テスト</span>
                        </div>
                        <div class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs">
                            <span class="px-2 py-0.5 bg-blue-500 text-white rounded text-[10px]">DL</span>
                            <span class="ml-1 text-gray-600">CSVダウンロード</span>
                        </div>
                    </div>

                    {{-- JX送信ファイル --}}
                    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-bold text-sm flex items-center gap-2">
                                <x-heroicon-o-paper-airplane class="w-4 h-4 text-purple-500" />JX送信
                            </h3>
                            <a href="{{ route('filament.admin.resources.wms-order-documents.index') }}" class="text-xs text-blue-600 hover:underline">→</a>
                        </div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">JX-FINET経由で問屋へ送信</p>
                        <div class="space-y-1 text-xs">
                            <div class="p-2 bg-purple-50 dark:bg-purple-900/20 rounded">
                                <span class="px-2 py-0.5 bg-purple-500 text-white rounded text-[10px]">送信</span>
                                <span class="ml-1 text-gray-600">取消不可</span>
                            </div>
                            <div class="p-2 bg-gray-50 dark:bg-gray-800 rounded">
                                <span class="px-2 py-0.5 bg-gray-500 text-white rounded text-[10px]">確認</span>
                                <span class="ml-1 text-gray-600">送信前プレビュー</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 設定タブ --}}
        <div x-show="activeTab === 'settings'" x-cloak class="flex-1 overflow-y-auto pt-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach([
                    ['月別発注点', 'wms-monthly-safety-stocks', 'heroicon-o-calendar-days', 'orange', '商品ごとに月別の発注点を設定', '発注マスタ'],
                    ['倉庫カレンダー', 'wms-warehouse-calendars', 'heroicon-o-calendar', 'red', '休日は次営業日に自動調整', '倉庫マスタ'],
                    ['発注先休日', 'wms-contractor-holidays', 'heroicon-o-calendar', 'pink', '休日は前営業日に発注', '発注マスタ'],
                    ['発注先別ロット', 'warehouse-contractors', 'heroicon-o-building-storefront', 'indigo', '最小発注単位・リードタイム', '発注マスタ'],
                    ['JX接続設定', 'wms-order-jx-settings', 'heroicon-o-server', 'purple', '送信先コード・ファイル形式', '発注マスタ'],
                ] as [$label, $route, $icon, $color, $desc, $category])
                <a href="{{ route('filament.admin.resources.'.$route.'.index') }}"
                   class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 hover:border-{{ $color }}-300 transition-colors">
                    <div class="flex items-center gap-2 mb-1">
                        <x-dynamic-component :component="$icon" class="w-4 h-4 text-{{ $color }}-500" />
                        <span class="font-semibold text-sm">{{ $label }}</span>
                        <span class="text-[10px] bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded ml-auto">{{ $category }}</span>
                    </div>
                    <p class="text-xs text-gray-500">{{ $desc }}</p>
                </a>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page></div>
