<x-filament-panels::page>
    <div x-data="{
        showFilter: false,
        showColumnSelect: false,
        showForm: false,
        showDetail: false,
        showTabbed: false,
        showConfirm: false,
        showNested: false,
    }">
        {{-- Trigger Buttons --}}
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
            <button @click="showFilter = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fa fa-filter mr-2"></i> フィルタモーダル
            </button>
            <button @click="showColumnSelect = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                <i class="fa fa-columns mr-2"></i> カラム選択モーダル
            </button>
            <button @click="showForm = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition-colors">
                <i class="fa fa-edit mr-2"></i> フォームモーダル
            </button>
            <button @click="showDetail = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 transition-colors">
                <i class="fa fa-info-circle mr-2"></i> 詳細表示モーダル
            </button>
            <button @click="showTabbed = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition-colors">
                <i class="fa fa-folder mr-2"></i> タブ付きモーダル
            </button>
            <button @click="showConfirm = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
                <i class="fa fa-exclamation-triangle mr-2"></i> 確認ダイアログ
            </button>
            <button @click="showNested = true"
                    class="px-4 py-3 text-sm font-medium text-white bg-gray-600 rounded-lg hover:bg-gray-700 transition-colors">
                <i class="fa fa-layer-group mr-2"></i> ネストモーダル
            </button>
        </div>

        {{-- ============================================= --}}
        {{-- 1. Filter Modal (6xl) --}}
        {{-- ============================================= --}}
        <x-modal.container size="6xl" alpine-var="showFilter">
            <x-modal.header icon="filter" title="フィルタ条件" alpine-var="showFilter" />
            <x-modal.content>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (['商品CD', '商品名', 'カテゴリ', '倉庫', '出荷日（開始）', '出荷日（終了）', 'ステータス', '担当者'] as $label)
                        <div class="p-2 rounded bg-slate-50 dark:bg-gray-900">
                            <x-modal.form-group :label="$label">
                                @if (in_array($label, ['カテゴリ', 'ステータス', '倉庫']))
                                    <select class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">選択してください</option>
                                        <option>オプション1</option>
                                        <option>オプション2</option>
                                    </select>
                                @elseif (str_contains($label, '日'))
                                    <input type="date" class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                @else
                                    <input type="text" class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="{{ $label }}を入力">
                                @endif
                            </x-modal.form-group>
                        </div>
                    @endforeach
                </div>
            </x-modal.content>
            <x-modal.footer justify="between">
                <button class="px-3 py-1.5 text-xs font-medium text-slate-500 dark:text-gray-400 hover:text-slate-700 dark:hover:text-gray-200">
                    <i class="fa fa-eraser mr-1"></i> クリア
                </button>
                <div class="flex gap-2">
                    <button @click="showFilter = false"
                            class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-gray-400 bg-slate-100 dark:bg-gray-700 rounded hover:bg-slate-200 dark:hover:bg-gray-600">
                        キャンセル
                    </button>
                    <button class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                        <i class="fa fa-search mr-1"></i> 適用
                    </button>
                </div>
            </x-modal.footer>
        </x-modal.container>

        {{-- ============================================= --}}
        {{-- 2. Column Select Modal (3xl) --}}
        {{-- ============================================= --}}
        <x-modal.container size="3xl" alpine-var="showColumnSelect">
            <x-modal.header icon="columns" title="カラム" alpine-var="showColumnSelect" />
            <x-modal.content>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (['商品CD', '商品名', 'カテゴリ', '在庫数', '引当数', '有効在庫', '賞味期限', '入荷日', 'ロット番号', '倉庫', 'ロケーション', 'ステータス'] as $col)
                        <label class="flex items-center p-2 rounded border cursor-pointer transition-colors hover:bg-slate-50 dark:hover:bg-gray-700 border-slate-200 dark:border-gray-700"
                               x-data="{ checked: {{ rand(0, 1) ? 'true' : 'false' }} }"
                               :class="checked ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/30 dark:border-blue-600' : 'border-slate-200 dark:border-gray-700'">
                            <input type="checkbox" x-model="checked"
                                   class="w-4 h-4 rounded text-blue-600 border-slate-300 dark:border-gray-600">
                            <span class="ml-2 text-xs text-slate-700 dark:text-gray-300">{{ $col }}</span>
                        </label>
                    @endforeach
                </div>
            </x-modal.content>
            <x-modal.footer>
                <button @click="showColumnSelect = false"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                    <i class="fa fa-check mr-1"></i> 完了
                </button>
            </x-modal.footer>
        </x-modal.container>

        {{-- ============================================= --}}
        {{-- 3. Form Modal (lg) --}}
        {{-- ============================================= --}}
        <x-modal.container size="lg" alpine-var="showForm">
            <x-modal.header icon="edit" title="商品編集" alpine-var="showForm" />
            <x-modal.content padding="6">
                <div class="space-y-4">
                    <x-modal.form-group label="商品CD">
                        <input type="text" value="SKU-001234"
                               class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" readonly>
                    </x-modal.form-group>
                    <x-modal.form-group label="商品名">
                        <input type="text" value="サンプル商品A"
                               class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </x-modal.form-group>
                    <x-modal.form-group label="カテゴリ">
                        <select class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option>カテゴリA</option>
                            <option>カテゴリB</option>
                        </select>
                    </x-modal.form-group>
                    <x-modal.form-group label="備考">
                        <textarea rows="4"
                                  class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-xl px-4 py-3 text-sm resize-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="備考を入力してください..."></textarea>
                    </x-modal.form-group>
                </div>
            </x-modal.content>
            <x-modal.footer>
                <div class="flex gap-2">
                    <button @click="showForm = false"
                            class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-gray-400 bg-slate-100 dark:bg-gray-700 rounded hover:bg-slate-200 dark:hover:bg-gray-600">
                        キャンセル
                    </button>
                    <button class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                        <i class="fa fa-save mr-1"></i> 保存
                    </button>
                </div>
            </x-modal.footer>
        </x-modal.container>

        {{-- ============================================= --}}
        {{-- 4. Detail Modal (2xl) --}}
        {{-- ============================================= --}}
        <x-modal.container size="2xl" alpine-var="showDetail">
            <x-modal.header icon="info-circle" title="在庫詳細" alpine-var="showDetail" />
            <x-modal.content>
                <div class="space-y-4">
                    {{-- Info cards --}}
                    <div class="grid grid-cols-3 gap-3">
                        @foreach ([
                            ['項目' => '商品CD', '値' => 'SKU-001234'],
                            ['項目' => '商品名', '値' => 'サンプル清酒 純米大吟醸'],
                            ['項目' => '倉庫', '値' => '第1倉庫'],
                            ['項目' => '在庫数', '値' => '120 ケース'],
                            ['項目' => '引当数', '値' => '30 ケース'],
                            ['項目' => '有効在庫', '値' => '90 ケース'],
                        ] as $item)
                            <div class="p-3 rounded-lg bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700">
                                <dt class="text-xs font-medium text-slate-500 dark:text-gray-400 mb-1">{{ $item['項目'] }}</dt>
                                <dd class="text-sm font-bold text-slate-800 dark:text-gray-200">{{ $item['値'] }}</dd>
                            </div>
                        @endforeach
                    </div>

                    {{-- Table --}}
                    <div class="border border-slate-200 dark:border-gray-700 rounded-lg overflow-hidden">
                        <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                            <thead class="bg-slate-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-3 py-2 text-xs font-medium text-slate-600 dark:text-gray-400 text-left">ロット</th>
                                    <th class="px-3 py-2 text-xs font-medium text-slate-600 dark:text-gray-400 text-left">賞味期限</th>
                                    <th class="px-3 py-2 text-xs font-medium text-slate-600 dark:text-gray-400 text-right">数量</th>
                                    <th class="px-3 py-2 text-xs font-medium text-slate-600 dark:text-gray-400 text-left">ステータス</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-gray-700">
                                @foreach ([
                                    ['LOT-2026A', '2026-09-30', '40', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', '有効'],
                                    ['LOT-2026B', '2026-12-15', '50', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', '有効'],
                                    ['LOT-2025Z', '2025-06-01', '30', 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', '期限切迫'],
                                ] as [$lot, $exp, $qty, $badgeClass, $status])
                                    <tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">
                                        <td class="px-3 py-2 text-sm text-slate-700 dark:text-gray-300">{{ $lot }}</td>
                                        <td class="px-3 py-2 text-sm text-slate-700 dark:text-gray-300">{{ $exp }}</td>
                                        <td class="px-3 py-2 text-sm text-slate-700 dark:text-gray-300 text-right">{{ $qty }}</td>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full {{ $badgeClass }}">{{ $status }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-modal.content>
            <x-modal.footer>
                <button @click="showDetail = false"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                    <i class="fa fa-check mr-1"></i> 閉じる
                </button>
            </x-modal.footer>
        </x-modal.container>

        {{-- ============================================= --}}
        {{-- 5. Tabbed Modal (2xl) --}}
        {{-- ============================================= --}}
        <x-modal.container size="2xl" alpine-var="showTabbed">
            <x-modal.header icon="comments" title="配送業務連絡" alpine-var="showTabbed" />

            <div x-data="{ activeTab: 'list' }">
                {{-- Tabs --}}
                <div class="flex border-b border-slate-200 dark:border-gray-700 px-4">
                    <button @click="activeTab = 'list'"
                            class="px-4 py-2 text-sm font-medium transition-colors"
                            :class="activeTab === 'list'
                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30 dark:text-blue-400 dark:bg-blue-900/20'
                                : 'text-slate-500 dark:text-gray-400 hover:text-slate-700 dark:hover:text-gray-200'">
                        一覧
                    </button>
                    <button @click="activeTab = 'create'"
                            class="px-4 py-2 text-sm font-medium transition-colors"
                            :class="activeTab === 'create'
                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30 dark:text-blue-400 dark:bg-blue-900/20'
                                : 'text-slate-500 dark:text-gray-400 hover:text-slate-700 dark:hover:text-gray-200'">
                        新規作成
                    </button>
                </div>

                {{-- Tab Content --}}
                <div class="p-4 overflow-y-auto flex-1">
                    {{-- List Tab --}}
                    <div x-show="activeTab === 'list'" class="space-y-3">
                        @foreach ([
                            ['category' => '遅延', 'time' => '14:30', 'user' => '田中', 'content' => '首都高速渋滞のため、配送が30分程度遅れる見込みです。'],
                            ['category' => '全体', 'time' => '10:15', 'user' => '佐藤', 'content' => '本日午後より倉庫Bの出荷レーンを変更します。詳細は掲示板を確認してください。'],
                        ] as $msg)
                            <div class="border border-slate-200 dark:border-gray-700 rounded-xl p-4 bg-white dark:bg-gray-800 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-black uppercase tracking-tighter rounded-full bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">{{ $msg['category'] }}</span>
                                        <span class="text-xs text-slate-400 dark:text-gray-500">{{ $msg['time'] }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-slate-100 dark:bg-gray-700 text-slate-500 dark:text-gray-400">
                                            <i class="fa fa-user text-[10px]"></i> {{ $msg['user'] }}
                                        </span>
                                        <button class="text-slate-300 dark:text-gray-600 hover:text-red-500 transition-colors">
                                            <i class="fa fa-times text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                                <p class="text-sm text-slate-700 dark:text-gray-300 whitespace-pre-wrap">{{ $msg['content'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Create Tab --}}
                    <div x-show="activeTab === 'create'" class="space-y-4">
                        <x-modal.form-group label="カテゴリ">
                            <select class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option>全体</option>
                                <option>遅延</option>
                                <option>変更</option>
                            </select>
                        </x-modal.form-group>
                        <x-modal.form-group label="連絡内容">
                            <textarea rows="6"
                                      class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-xl px-4 py-3 text-sm resize-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="連絡内容を入力してください..."></textarea>
                        </x-modal.form-group>
                        <button class="w-full py-3.5 rounded-xl text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-200 dark:shadow-none active:scale-95 transition-all">
                            送信
                        </button>
                    </div>
                </div>
            </div>
        </x-modal.container>

        {{-- ============================================= --}}
        {{-- 6. Confirm Dialog --}}
        {{-- ============================================= --}}
        <x-modal.confirm
            alpine-var="showConfirm"
            title="この在庫データを削除しますか？"
            message="この操作は取り消せません。関連する引当情報も全て削除されます。"
            icon="exclamation-triangle"
            icon-color="red"
            z-index="100"
        >
            <button @click="showConfirm = false"
                    class="px-4 py-1.5 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700">
                <i class="fa fa-trash mr-1"></i> 削除
            </button>
        </x-modal.confirm>

        {{-- ============================================= --}}
        {{-- 7. Nested Modal (z-index test) --}}
        {{-- ============================================= --}}
        <div x-data="{ showInnerConfirm: false }">
            <x-modal.container size="lg" alpine-var="showNested">
                <x-modal.header icon="layer-group" title="ネストモーダルテスト" alpine-var="showNested" />
                <x-modal.content padding="6">
                    <div class="text-center space-y-4">
                        <p class="text-sm text-slate-700 dark:text-gray-300">このモーダルの上に確認ダイアログを表示できます。</p>
                        <button @click="showInnerConfirm = true"
                                class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
                            <i class="fa fa-trash mr-2"></i> 削除して確認ダイアログを開く
                        </button>
                    </div>
                </x-modal.content>
                <x-modal.footer>
                    <button @click="showNested = false"
                            class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-gray-400 bg-slate-100 dark:bg-gray-700 rounded hover:bg-slate-200 dark:hover:bg-gray-600">
                        閉じる
                    </button>
                </x-modal.footer>
            </x-modal.container>

            <x-modal.confirm
                alpine-var="showInnerConfirm"
                title="本当に削除しますか？"
                message="ネストされた確認ダイアログのテストです。z-index: 120 で表示されます。"
                icon="exclamation-triangle"
                icon-color="red"
                z-index="120"
            >
                <button @click="showInnerConfirm = false"
                        class="px-4 py-1.5 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700">
                    <i class="fa fa-trash mr-1"></i> 削除
                </button>
            </x-modal.confirm>
        </div>
    </div>
</x-filament-panels::page>
