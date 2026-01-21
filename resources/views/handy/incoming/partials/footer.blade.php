{{-- Fixed Footer with Function Keys - 480x800 optimized --}}
<footer class="bg-gray-800 text-white flex items-stretch shrink-0 h-10 border-t border-gray-600">
    {{-- F1 検索 --}}
    <div class="flex-1 flex items-center justify-center border-r border-gray-600">
        <template x-if="currentScreen === 'list'">
            <button @click="$refs.searchInput?.focus()" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F1</span><span>検索</span>
            </button>
        </template>
        <template x-if="currentScreen === 'input'">
            <button @click="inputForm.qty = currentSchedule?.expected_quantity || 0" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F1</span><span>自動</span>
            </button>
        </template>
    </div>

    {{-- F2 履歴/登録 --}}
    <div class="flex-1 flex items-center justify-center border-r border-gray-600">
        <template x-if="currentScreen === 'list'">
            <button @click="loadHistory(); currentScreen = 'history'" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F2</span><span>履歴</span>
            </button>
        </template>
        <template x-if="currentScreen === 'history'">
            <button @click="currentScreen = 'list'" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F2</span><span>リスト</span>
            </button>
        </template>
        <template x-if="currentScreen === 'process'">
            <button @click="loadHistory(); currentScreen = 'history'" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F2</span><span>履歴</span>
            </button>
        </template>
        <template x-if="currentScreen === 'input'">
            <button @click="submitReceiving()" :disabled="!canSubmit" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 disabled:text-gray-500 leading-tight">
                <span class="text-yellow-400">F2</span><span>登録</span>
            </button>
        </template>
    </div>

    {{-- F3 (Reserved) --}}
    <div class="flex-1 flex items-center justify-center border-r border-gray-600">
        <div class="flex flex-col items-center justify-center text-handy-xs text-gray-500 leading-tight">
            <span>F3</span><span>-</span>
        </div>
    </div>

    {{-- F4 戻る --}}
    <div class="flex-1 flex items-center justify-center">
        <template x-if="currentScreen === 'list'">
            <button @click="selectedWarehouse = null; currentScreen = 'warehouse'" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F4</span><span>戻る</span>
            </button>
        </template>
        <template x-if="currentScreen === 'history'">
            <button @click="currentScreen = 'list'" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F4</span><span>戻る</span>
            </button>
        </template>
        <template x-if="currentScreen === 'process'">
            <button @click="editingWorkItem = null; loadWorkingScheduleIds(); currentScreen = 'list'" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F4</span><span>戻る</span>
            </button>
        </template>
        <template x-if="currentScreen === 'input'">
            <button @click="goBackFromInput()" class="w-full h-full flex flex-col items-center justify-center text-handy-xs font-bold active:bg-gray-700 leading-tight">
                <span class="text-yellow-400">F4</span><span>戻る</span>
            </button>
        </template>
    </div>
</footer>
