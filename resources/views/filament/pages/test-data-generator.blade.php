<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 説明セクション --}}
        <div class="bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-warning-800 dark:text-warning-200">
                        開発環境専用機能
                    </h3>
                    <div class="mt-2 text-sm text-warning-700 dark:text-warning-300">
                        <p>このページは開発・テスト環境でのみ使用できます。</p>
                        <p class="mt-1">本番環境では自動的に非表示になります。</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- テストデータ生成カード --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            {{-- WMSマスタデータ生成 --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center mb-4">
                    <x-heroicon-o-cube class="w-8 h-8 text-warning-600 dark:text-warning-400 mr-3" />
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        WMSマスタ生成
                    </h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    WMS稼働に必要なマスタデータ（ピッキングエリア、ロケーション、在庫、ピッカー）を一括生成します。
                </p>
                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2 mb-4">
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>ピッキングエリア（A-D）を作成</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>ロケーション20箇所を自動作成</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>在庫データを複数ロット生成</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>テストピッカー3名を作成・タスク自動割当</span>
                    </li>
                </ul>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    <strong>コマンド:</strong> <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan testdata:wms</code>
                </div>
            </div>

            {{-- 売上データ生成 --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center mb-4">
                    <x-heroicon-o-currency-yen class="w-8 h-8 text-success-600 dark:text-success-400 mr-3" />
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        売上データ生成
                    </h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    BoozeCore APIを通じてテスト用の売上データ（Earnings）を生成します。
                </p>
                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2 mb-4">
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>複数のシナリオ（ケース/バラ/欠品あり）</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>在庫のある商品から自動選択</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>倉庫と件数をカスタマイズ可能</span>
                    </li>
                </ul>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    <strong>コマンド:</strong> <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan testdata:earnings</code>
                </div>
            </div>

            {{-- Wave生成 --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center mb-4">
                    <x-heroicon-o-queue-list class="w-8 h-8 text-primary-600 dark:text-primary-400 mr-3" />
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Wave生成
                    </h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Wave設定に基づいて、売上データからピッキングタスクを生成します。
                </p>
                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2 mb-4">
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>FEFO/FIFO在庫引当ロジック</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>ピッキングエリア別タスク分割</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>既存データのリセットオプション</span>
                    </li>
                </ul>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    <strong>コマンド:</strong> <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan wms:generate-waves</code>
                </div>
            </div>

            {{-- ピッカー別Wave生成 --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex items-center mb-4">
                    <x-heroicon-o-user-group class="w-8 h-8 text-info-600 dark:text-info-400 mr-3" />
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        ピッカー別Wave生成
                    </h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    特定のピッカーに複数の配送コースのピッキングタスクを一括生成します。
                </p>
                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2 mb-4">
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-info-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>ピッカー選択・タスク自動割当</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-info-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>配送コース別に伝票枚数指定</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-info-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>複数エリアの商品を含む伝票生成</span>
                    </li>
                    <li class="flex items-start">
                        <x-heroicon-m-check-circle class="w-5 h-5 text-info-500 mr-2 flex-shrink-0 mt-0.5" />
                        <span>伝票・Wave・タスク割当を一括実行</span>
                    </li>
                </ul>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    <strong>コマンド:</strong> <code class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">php artisan testdata:picker-wave</code>
                </div>
            </div>
        </div>

        {{-- 使い方 --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                使い方
            </h2>

            <div class="space-y-6">
                <div>
                    <h3 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-2">
                        基本フロー
                    </h3>
                    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-600 dark:text-gray-400">
                        <li>
                            <strong>WMSマスタ生成（最初に実行）:</strong> ヘッダーの「WMSマスタ生成」ボタンからピッキングエリア、ロケーション、在庫データを一括生成
                        </li>
                        <li>
                            <strong>売上データ生成:</strong> 「売上データ生成」ボタンから件数と倉庫を指定してテストデータを作成
                        </li>
                        <li>
                            <strong>Wave生成:</strong> 「Wave生成」ボタンから出荷日を指定してピッキングタスクを生成
                        </li>
                        <li>
                            必要に応じて「既存データをリセット」オプションを使用して、既存のWaveとタスクをクリア
                        </li>
                    </ol>
                </div>

                <div>
                    <h3 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-2">
                        ピッカー別Wave生成（推奨）
                    </h3>
                    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-600 dark:text-gray-400">
                        <li>
                            <strong>WMSマスタ生成:</strong> 最初に「WMSマスタ生成」を実行してピッカー、在庫、ロケーションを作成
                        </li>
                        <li>
                            <strong>ピッカー別Wave生成:</strong> 「ピッカー別Wave生成」ボタンをクリック
                        </li>
                        <li>
                            <strong>ピッカー選択:</strong> テストしたいピッカーを選択（倉庫が自動設定されます）
                        </li>
                        <li>
                            <strong>配送コース設定:</strong> 配送コースを選択し、各コースの伝票枚数を指定（複数コース追加可能）
                        </li>
                        <li>
                            <strong>実行:</strong> 伝票生成→Wave生成→ピッカー割当が自動実行されます
                        </li>
                    </ol>
                    <div class="mt-3 p-3 bg-info-50 dark:bg-info-900/20 border border-info-200 dark:border-info-700 rounded">
                        <p class="text-sm text-info-800 dark:text-info-200">
                            <strong>💡 メリット:</strong> 同じピッカーに複数の配送コースのタスクを割り当て、異なるピッキングエリアの商品を含む実践的なテストデータを一括生成できます。
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- 注意事項 --}}
        <div class="bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-700 rounded-lg p-4">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <x-heroicon-o-shield-exclamation class="w-6 h-6 text-danger-600 dark:text-danger-400" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-danger-800 dark:text-danger-200">
                        注意事項
                    </h3>
                    <div class="mt-2 text-sm text-danger-700 dark:text-danger-300">
                        <ul class="list-disc list-inside space-y-1">
                            <li>生成されたデータは実際のデータベースに保存されます</li>
                            <li>Wave生成時のリセットオプションは、指定日の全Waveデータを削除します</li>
                            <li>本番環境ではこのページは表示されません</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
