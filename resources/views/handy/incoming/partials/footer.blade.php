{{-- Fixed Footer with Function Keys - 480x800 optimized --}}
<footer class="bg-gray-800 text-white flex items-stretch shrink-0 h-10 border-t border-gray-600">
    {{-- F1 --}}
    <div class="flex-1 flex items-center justify-center border-r border-gray-600">
        <template x-if="currentScreen === 'input'">
            <button @click="submitReceiving()" :disabled="!canSubmit" class="w-full h-full flex items-center justify-center gap-1 text-handy-sm font-bold active:bg-gray-700 disabled:text-gray-500">
                <span class="text-yellow-400">F1</span>登録
            </button>
        </template>
    </div>

    {{-- F2 --}}
    <div class="flex-1 flex items-center justify-center border-r border-gray-600">
        <template x-if="currentScreen === 'list'">
            <button @click="loadHistory(); currentScreen = 'history'" class="w-full h-full flex items-center justify-center gap-1 text-handy-sm font-bold active:bg-gray-700">
                <span class="text-yellow-400">F2</span>履歴
            </button>
        </template>
        <template x-if="currentScreen === 'history'">
            <button @click="currentScreen = 'list'" class="w-full h-full flex items-center justify-center gap-1 text-handy-sm font-bold active:bg-gray-700">
                <span class="text-yellow-400">F2</span>リスト
            </button>
        </template>
        <template x-if="currentScreen === 'process'">
            <button @click="editingWorkItem = null; loadWorkingScheduleIds(); currentScreen = 'list'" class="w-full h-full flex items-center justify-center gap-1 text-handy-sm font-bold active:bg-gray-700">
                <span class="text-yellow-400">F2</span>リスト
            </button>
        </template>
        <template x-if="currentScreen === 'input'">
            <button @click="editingWorkItem = null; currentScreen = 'process'" class="w-full h-full flex items-center justify-center gap-1 text-handy-sm font-bold active:bg-gray-700">
                <span class="text-yellow-400">F2</span>戻る
            </button>
        </template>
    </div>

    {{-- F3 --}}
    <div class="flex-1 flex items-center justify-center border-r border-gray-600">
        {{-- Reserved for future use --}}
    </div>

    {{-- F8 --}}
    <div class="flex-1 flex items-center justify-center">
        <template x-if="currentScreen === 'list' || currentScreen === 'history' || currentScreen === 'process' || currentScreen === 'input'">
            <button @click="selectedWarehouse = null; currentScreen = 'warehouse'" class="w-full h-full flex items-center justify-center gap-1 text-handy-sm font-bold active:bg-gray-700">
                <span class="text-yellow-400">F8</span>メイン
            </button>
        </template>
    </div>
</footer>
