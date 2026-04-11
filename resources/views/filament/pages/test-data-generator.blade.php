<x-filament-panels::page>
    <div class="space-y-6">
        {{-- タブナビゲーション --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button
                    wire:click="setActiveTab('shipping')"
                    type="button"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 {{ $activeTab === 'shipping' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}"
                >
                    <x-heroicon-o-truck class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    出荷テスト用
                </button>
                <button
                    wire:click="setActiveTab('warehouse')"
                    type="button"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 {{ $activeTab === 'warehouse' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}"
                >
                    <x-heroicon-o-building-office-2 class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    倉庫テストデータ
                </button>
                <button
                    wire:click="setActiveTab('autoorder')"
                    type="button"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 {{ $activeTab === 'autoorder' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}"
                >
                    <x-heroicon-o-shopping-cart class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                    発注テスト用
                </button>
            </nav>
        </div>

        {{-- 出荷テスト用タブ --}}
        @if($activeTab === 'shipping')
        <div class="space-y-6">
            {{-- 使い方（出荷テスト用） --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    出荷テストの流れ
                </h2>
                <div class="space-y-4">
                    <div>
                        <h3 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-2">
                            ピッカー別Wave生成（推奨）
                        </h3>
                        <ol class="list-decimal list-inside space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <li><strong>事前準備:</strong> 倉庫タブで「WMSマスタ生成」を実行してFloor、Locationを作成</li>
                            <li><strong>ピッカー生成:</strong> 「ピッカー生成」ボタンでテスト用ピッカーを作成</li>
                            <li><strong>ピッカー別Wave生成:</strong> ピッカーを選択し、配送コース別に伝票枚数を指定</li>
                            <li><strong>実行:</strong> 伝票生成→Wave生成→ピッカー割当が自動実行されます</li>
                        </ol>
                    </div>
                </div>
            </div>

            {{-- アクションボタン --}}
            <div class="flex flex-wrap gap-3">
                @foreach($this->getShippingActionNames() as $actionName)
                    {{ $this->getAction($actionName) }}
                @endforeach
            </div>

            {{-- テストデータ生成カード --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {{-- Wave設定生成 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-cog-6-tooth class="w-8 h-8 text-gray-600 dark:text-gray-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            Wave設定生成
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        倉庫・配送コース別に1時間単位のWave設定を生成します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-gray-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>倉庫指定（全倉庫も可）</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-gray-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>既存設定リセット機能</span>
                        </li>
                    </ul>
                </div>

                {{-- ピッカー生成 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-user-plus class="w-8 h-8 text-gray-600 dark:text-gray-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            ピッカー生成
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        指定した倉庫に5人のピッカーを生成します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-gray-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>各スキルレベル1人ずつ</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-gray-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>異なる作業速度で生成</span>
                        </li>
                    </ul>
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
                        特定のピッカーに複数の配送コースのピッキングタスクを生成します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
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
                            <span>伝票・Wave・タスク割当を一括実行</span>
                        </li>
                    </ul>
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
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>配送コースを複数指定可能</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>特定ロケーションの在庫のみ使用可能</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        @endif

        {{-- 倉庫テストデータタブ --}}
        @if($activeTab === 'warehouse')
        <div class="space-y-6">
            {{-- 使い方（倉庫テストデータ） --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    在庫データの保存・復元
                </h2>
                <div class="space-y-4">
                    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-600 dark:text-gray-400">
                        <li>
                            <strong>在庫データ保存:</strong> 現在のreal_stocks・real_stock_lotsをS3にCSV保存
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1">
                                <li>スナップショット名を付けて管理可能</li>
                                <li>複数のスナップショットを保持</li>
                            </ul>
                        </li>
                        <li>
                            <strong>在庫データ読込:</strong> S3に保存されたスナップショットを選択して復元
                            <ul class="list-disc list-inside ml-6 mt-1 space-y-1">
                                <li>既存データを完全上書き（TRUNCATE→INSERT）</li>
                                <li>real_stocks + real_stock_lots を同時復元</li>
                            </ul>
                        </li>
                    </ol>
                </div>
            </div>

            {{-- アクションボタン --}}
            <div class="flex flex-wrap gap-3">
                @foreach($this->getWarehouseActionNames() as $actionName)
                    {{ $this->getAction($actionName) }}
                @endforeach
            </div>

            {{-- テストデータ生成カード --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>配送コースを複数指定可能</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>特定ロケーションの在庫のみ使用可能</span>
                        </li>
                    </ul>
                </div>

                {{-- 在庫データ保存 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-arrow-down-tray class="w-8 h-8 text-success-600 dark:text-success-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            在庫データ保存
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        現在のreal_stocks・real_stock_lotsデータをCSVとしてS3に保存します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>real_stocks + real_stock_lots の全データ</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>スナップショット名を付けて管理</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-success-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>S3にタイムスタンプ付きで保存</span>
                        </li>
                    </ul>
                </div>

                {{-- 在庫データ読込 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-arrow-up-tray class="w-8 h-8 text-warning-600 dark:text-warning-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            在庫データ読込
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        S3に保存されたスナップショットを選択して在庫データを復元します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>既存データを完全上書き</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>real_stocks + real_stock_lots を同時復元</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-warning-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>復元確認ダイアログ付き</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        @endif

        {{-- 発注テスト用タブ --}}
        @if($activeTab === 'autoorder')
        <div class="space-y-6">
            {{-- 使い方（発注テスト） --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    発注テストの流れ
                </h2>
                <div class="space-y-4">
                    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-600 dark:text-gray-400">
                        <li>
                            <strong>倉庫発注設定:</strong> 倉庫の自動発注を有効化し、休日設定を登録
                        </li>
                        <li>
                            <strong>祝日マスタ生成:</strong> 日本の祝日データを登録
                        </li>
                        <li>
                            <strong>カレンダー生成:</strong> 休日設定と祝日から営業日カレンダーを計算
                        </li>
                        <li>
                            <strong>供給設定生成:</strong> 商品×倉庫の供給方式（EXTERNAL/INTERNAL）を設定
                        </li>
                        <li>
                            <strong>計算実行:</strong> <code class="bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs">php artisan wms:auto-order-calculate</code>
                        </li>
                    </ol>

                    <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-2">前提条件</h4>
                        <ul class="list-disc list-inside text-sm text-blue-700 dark:text-blue-400 space-y-1">
                            <li>倉庫マスタ（warehouses）にデータが存在すること</li>
                            <li>商品マスタ（items）にデータが存在すること</li>
                            <li>発注契約（item_contractors）にデータが存在すること</li>
                            <li>在庫データ（real_stocks）が存在すること（計算用）</li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- アクションボタン --}}
            <div class="flex flex-wrap gap-3">
                @foreach($this->getAutoOrderActionNames() as $actionName)
                    {{ $this->getAction($actionName) }}
                @endforeach
            </div>

            {{-- テストデータ生成カード --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {{-- 倉庫発注設定 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-building-office class="w-8 h-8 text-primary-600 dark:text-primary-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            倉庫発注設定
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        倉庫の自動発注有効化と休日設定を行います。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>自動発注の有効/無効</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>定休日（曜日）の設定</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>祝日休業の設定</span>
                        </li>
                    </ul>
                </div>

                {{-- カレンダー生成 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-calendar class="w-8 h-8 text-primary-600 dark:text-primary-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            カレンダー生成
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        倉庫別の営業日カレンダーを計算・生成します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>定休日・祝日を自動判定</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>1〜12ヶ月分を生成可能</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>手動変更は保持</span>
                        </li>
                    </ul>
                </div>

                {{-- 供給設定生成 --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-arrows-right-left class="w-8 h-8 text-primary-600 dark:text-primary-400 mr-3" />
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                            供給設定生成
                        </h2>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        商品×倉庫の供給設定をitem_contractorsから生成します。
                    </p>
                    <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-2">
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>EXTERNAL: 外部発注</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>INTERNAL: 倉庫間移動</span>
                        </li>
                        <li class="flex items-start">
                            <x-heroicon-m-check-circle class="w-5 h-5 text-primary-500 mr-2 flex-shrink-0 mt-0.5" />
                            <span>階層レベル自動計算</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        @endif
    </div>
</x-filament-panels::page>