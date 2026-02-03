<div class="space-y-4">
    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-600 dark:text-gray-400">
        <li>
            <strong>テストパターンを選択:</strong>
            <ul class="list-disc list-inside ml-6 mt-1 space-y-1">
                <li><strong>空ファイル:</strong> 発注データなし。JX送信プロトコルのテストに使用</li>
                <li><strong>全商品ファイル:</strong> 発注先の全商品を含むファイル</li>
                <li><strong>集約テスト:</strong> 複数発注先のデータを1ファイルに集約（カナカンケース）</li>
            </ul>
        </li>
        <li>
            <strong>ファイル生成:</strong> JX設定を選択してファイルを生成
        </li>
        <li>
            <strong>送信テスト:</strong> 「生成後にJX送信を実行」をONにすると自動送信
        </li>
        <li>
            <strong>確認:</strong> 下の「生成ファイル一覧」と「送信ログ」で結果を確認
        </li>
    </ol>

    <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
        <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-2">テストサーバについて</h4>
        <p class="text-sm text-blue-700 dark:text-blue-400">
            JX設定のエンドポイントが <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">https://wms.sakemaru.test/jx-server</code> の場合、
            ローカルのテストサーバにデータが送信されます。受信データは <code class="bg-blue-100 dark:bg-blue-800 px-1 rounded">storage/app/private/jx-server/documents/</code> に保存されます。
        </p>
    </div>

    <div class="mt-4 space-y-4">
        <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">テストパターン詳細</h4>

        <div class="grid grid-cols-1 gap-4">
            {{-- 空ファイル --}}
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="flex items-center mb-2">
                    <x-heroicon-o-document class="w-5 h-5 text-gray-600 dark:text-gray-400 mr-2" />
                    <h5 class="font-medium text-gray-900 dark:text-white">空ファイル</h5>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                    発注データなしの空ファイル。JX-FINETプロトコルのテストに使用。
                </p>
                <ul class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                    <li>- Aレコード（ヘッダー）のみ</li>
                    <li>- 128バイト + CRLF</li>
                </ul>
            </div>

            {{-- 全商品ファイル --}}
            <div class="p-4 bg-primary-50 dark:bg-primary-900/20 rounded-lg border border-primary-200 dark:border-primary-700">
                <div class="flex items-center mb-2">
                    <x-heroicon-o-document-text class="w-5 h-5 text-primary-600 dark:text-primary-400 mr-2" />
                    <h5 class="font-medium text-gray-900 dark:text-white">全商品ファイル</h5>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                    発注先の全商品を含む発注ファイル。
                </p>
                <ul class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                    <li>- A/B/Dレコード形式</li>
                    <li>- Shift_JIS、128バイト固定長</li>
                    <li>- JAN/発注コード、単価を含む</li>
                </ul>
            </div>

            {{-- 集約テスト --}}
            <div class="p-4 bg-info-50 dark:bg-info-900/20 rounded-lg border border-info-200 dark:border-info-700">
                <div class="flex items-center mb-2">
                    <x-heroicon-o-document-duplicate class="w-5 h-5 text-info-600 dark:text-info-400 mr-2" />
                    <h5 class="font-medium text-gray-900 dark:text-white">集約テスト</h5>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                    複数発注先のデータを1ファイルに集約（カナカンケース向け）。
                </p>
                <ul class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                    <li>- 1106, 1021, 1029等を集約</li>
                    <li>- 各発注先のBレコードを含む</li>
                    <li>- transmission_contractor_idテスト</li>
                </ul>
            </div>
        </div>
    </div>
</div>
