<x-filament-panels::page>
    <div class="space-y-6">
        {{-- アクションボタン --}}
        <div class="flex flex-wrap gap-3">
            @foreach($this->getActionNames() as $actionName)
                {{ $this->getAction($actionName) }}
            @endforeach
        </div>

        {{-- JX設定一覧 --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <x-heroicon-o-server class="w-5 h-5 inline-block mr-2 -mt-0.5" />
                JX設定一覧
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">名前</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">送信元</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">送信先</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">エンドポイント</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($this->getJxSettings() as $setting)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $setting['id'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $setting['name'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono">{{ $setting['sender_station_code'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono">{{ $setting['receiver_station_code'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono text-xs">{{ $setting['endpoint_url'] }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 text-center">
                                JX設定がありません
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- タブ: 生成ファイル一覧 / 送信ログ / テストサーバ受信 --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg" x-data="{ activeTab: 'files' }">
            {{-- タブヘッダー --}}
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="flex -mb-px">
                    <button
                        @click="activeTab = 'files'"
                        :class="activeTab === 'files' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="px-6 py-3 border-b-2 font-medium text-sm flex items-center gap-2"
                    >
                        <x-heroicon-o-document-text class="w-4 h-4" />
                        生成ファイル
                    </button>
                    <button
                        @click="activeTab = 'logs'"
                        :class="activeTab === 'logs' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="px-6 py-3 border-b-2 font-medium text-sm flex items-center gap-2"
                    >
                        <x-heroicon-o-arrow-up-tray class="w-4 h-4" />
                        送信ログ
                    </button>
                    <button
                        @click="activeTab = 'received'"
                        :class="activeTab === 'received' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="px-6 py-3 border-b-2 font-medium text-sm flex items-center gap-2"
                    >
                        <x-heroicon-o-inbox-arrow-down class="w-4 h-4" />
                        テストサーバ受信
                    </button>
                    <button
                        @click="activeTab = 'xml'"
                        :class="activeTab === 'xml' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                        class="px-6 py-3 border-b-2 font-medium text-sm flex items-center gap-2"
                    >
                        <x-heroicon-o-code-bracket class="w-4 h-4" />
                        送信XML
                    </button>
                </nav>
            </div>

            {{-- タブコンテンツ --}}
            <div class="p-6">
                {{-- 生成ファイル一覧 --}}
                <div x-show="activeTab === 'files'" x-cloak>
                    <div class="overflow-x-auto max-h-64 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="sticky top-0 bg-white dark:bg-gray-800">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">ファイル名</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">サイズ</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">更新日時</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($this->getGeneratedFiles() as $file)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-white font-mono text-xs">{{ $file['filename'] }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['size_formatted'] }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['last_modified'] }}</td>
                                    <td class="px-3 py-2 text-sm">
                                        <a href="{{ route('jx-test-files.download', ['filename' => $file['filename']]) }}"
                                           class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200"
                                           target="_blank">
                                            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                        </a>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                                        生成されたファイルがありません
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- 送信ログ --}}
                <div x-show="activeTab === 'logs'" x-cloak>
                    <div class="overflow-x-auto max-h-64 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="sticky top-0 bg-white dark:bg-gray-800">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">JX設定</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">結果</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">サイズ</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">送信日時</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($this->getRecentTransmissionLogs() as $log)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-white">{{ $log['jx_setting_name'] }}</td>
                                    <td class="px-3 py-2 text-sm">
                                        @if($log['status'] === 'success')
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                                                成功
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100">
                                                失敗
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ number_format($log['data_size'] ?? 0) }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $log['created_at'] }}</td>
                                    <td class="px-3 py-2 text-sm">
                                        @if($log['file_path'])
                                            <a href="{{ route('jx-transmission-logs.download', ['log' => $log['id']]) }}"
                                               class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200"
                                               title="送信データをダウンロード">
                                                <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                                        送信ログがありません
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- テストサーバ受信ファイル --}}
                <div x-show="activeTab === 'received'" x-cloak>
                    <div class="overflow-x-auto max-h-64 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="sticky top-0 bg-white dark:bg-gray-800">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">日付</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">ファイル名</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">サイズ</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">受信日時</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($this->getTestServerReceivedFiles() as $file)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['date'] }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-white font-mono text-xs">{{ $file['filename'] }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['size_formatted'] }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{{ $file['last_modified'] }}</td>
                                    <td class="px-3 py-2 text-sm">
                                        <a href="{{ route('jx-server-files.download', ['path' => $file['path']]) }}"
                                           class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200"
                                           title="ダウンロード">
                                            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                        </a>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                                        受信ファイルがありません
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- 送信XMLファイル --}}
                <div x-show="activeTab === 'xml'" x-cloak x-data="{ selectedXml: null, xmlContent: '' }">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {{-- ファイル一覧 --}}
                        <div class="overflow-x-auto max-h-80 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">種別</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">ファイル名</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">送信日時</th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                    @forelse($this->getSentXmlFiles() as $file)
                                    <tr class="cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                                        :class="selectedXml === '{{ $file['path'] }}' ? 'bg-primary-50 dark:bg-primary-900/20' : ''"
                                        @click="selectedXml = '{{ $file['path'] }}'; $wire.getXmlContent('{{ $file['path'] }}').then(content => xmlContent = content)">
                                        <td class="px-3 py-2 text-sm">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                @if($file['doc_type'] === 'putdocument') bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100
                                                @elseif($file['doc_type'] === 'getdocument') bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100
                                                @else bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100 @endif">
                                                {{ strtoupper($file['doc_type']) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-gray-900 dark:text-white font-mono text-xs truncate max-w-[200px]" title="{{ $file['filename'] }}">{{ $file['filename'] }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $file['last_modified'] }}</td>
                                        <td class="px-3 py-2 text-sm">
                                            <a href="{{ route('jx-xml-files.download', ['path' => $file['path']]) }}"
                                               class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200"
                                               title="ダウンロード"
                                               @click.stop>
                                                <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
                                            </a>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="4" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                                            送信XMLがありません
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- XMLプレビュー --}}
                        <div class="border border-gray-200 dark:border-gray-700 rounded">
                            <div class="bg-gray-50 dark:bg-gray-900 px-3 py-2 border-b border-gray-200 dark:border-gray-700">
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">XMLプレビュー</span>
                            </div>
                            <div class="max-h-72 overflow-auto p-3 bg-gray-900">
                                <pre x-show="xmlContent" class="text-xs text-green-400 font-mono whitespace-pre-wrap" x-text="xmlContent"></pre>
                                <p x-show="!xmlContent" class="text-xs text-gray-500 text-center py-8">
                                    左のリストからファイルを選択してください
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
