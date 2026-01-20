{{-- Process Screen - 480x800 optimized with keyboard navigation --}}
<template x-if="currentScreen === 'process'">
    <div class="flex flex-col h-full bg-white"
         @keydown.up.prevent="moveScheduleSelection(-1)"
         @keydown.down.prevent="moveScheduleSelection(1)"
         @keydown.tab.prevent="moveScheduleSelection(1)"
         @keydown.shift.tab.prevent="moveScheduleSelection(-1)"
         @keydown.enter.prevent="selectScheduleForInput(selectedScheduleIndex)"
         tabindex="0"
         x-init="$el.focus()">
        {{-- Product Info Summary --}}
        <div class="bg-blue-50 px-1 py-1 border-b border-blue-100">
            <div class="flex justify-between text-handy-xs">
                <span class="font-bold font-mono text-gray-900" x-text="currentItem?.jan_codes?.[0] || '-'"></span>
                <span class="text-gray-500 font-mono" x-text="currentItem?.item_code"></span>
            </div>
            <h2 class="text-handy-xs font-bold text-blue-900 leading-tight" x-text="currentItem?.item_name"></h2>
            {{-- Volume & Capacity --}}
            <div class="flex items-center justify-end gap-2 text-handy-xs text-gray-600">
                <span x-show="currentItem?.volume">
                    容量: <b x-text="`${currentItem?.volume}${currentItem?.volume_unit || ''}`"></b>
                </span>
                <span x-show="currentItem?.capacity_case">
                    入数: <b x-text="currentItem?.capacity_case"></b>
                </span>
            </div>
        </div>

        {{-- Total Expected Quantity --}}
        <div class="bg-amber-50 px-2 py-2 border-b border-amber-200">
            <div class="flex items-center justify-between">
                <span class="text-handy-sm text-amber-700 font-bold">合計入荷予定数</span>
                <span class="text-handy-xl font-bold text-amber-900" x-text="currentItem?.total_expected_quantity"></span>
            </div>
        </div>

        {{-- Schedule List (Scrollable) --}}
        <div class="flex-1 overflow-y-auto" x-ref="scheduleList">
            {{-- Empty state --}}
            <template x-if="schedulesToProcess.length === 0">
                <div class="flex flex-col items-center justify-center h-full text-gray-500 p-4">
                    <i class="ph ph-check-circle text-4xl text-green-500 mb-2"></i>
                    <p class="text-handy-sm font-bold">全て入庫完了</p>
                    <button @click="loadHistory(); currentScreen = 'history'"
                            class="mt-2 px-3 py-1 bg-blue-500 text-white rounded text-handy-xs">
                        履歴を確認
                    </button>
                </div>
            </template>

            {{-- Schedule Table --}}
            <table class="w-full text-handy-xs" x-show="schedulesToProcess.length > 0">
                <template x-for="(schedule, index) in schedulesToProcess" :key="schedule.id">
                    <tbody class="border-b" :class="selectedScheduleIndex === index ? 'bg-blue-50 border-b-2 border-blue-500' : 'border-gray-300'">
                        <tr :data-index="index">
                            <td class="px-2 pt-2 text-gray-900 font-bold" x-text="schedule.warehouse_name"></td>
                            <td rowspan="2" class="px-1 py-1 align-middle w-20">
                                <button @click="selectedScheduleIndex = index; selectScheduleForInput(index)"
                                        class="w-full py-1 bg-blue-600 text-white font-bold rounded text-handy-xs active:bg-blue-700">
                                    予定数<br><span class="ml-2 font-bold" x-text="schedule.expected_quantity"></span>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-2 pb-2 text-gray-900">
                                予定日:<span x-text="formatDateMMDD(schedule.expected_arrival_date)"></span>
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>
    </div>
</template>

{{-- Input Screen for single schedule --}}
<template x-if="currentScreen === 'input'">
    <div class="flex flex-col h-full bg-white"
         x-data="{ inputFields: ['expirationInput', 'locationInput', 'qtyInput'], currentFieldIndex: 2 }"
         x-init="$nextTick(() => $refs.qtyInput?.focus())"
         @keydown.tab.prevent="currentFieldIndex = (currentFieldIndex + 1) % 3; $refs[inputFields[currentFieldIndex]]?.focus()"
         @keydown.shift.tab.prevent="currentFieldIndex = (currentFieldIndex + 2) % 3; $refs[inputFields[currentFieldIndex]]?.focus()"
         @keydown.down.prevent="currentFieldIndex = (currentFieldIndex + 1) % 3; $refs[inputFields[currentFieldIndex]]?.focus()"
         @keydown.up.prevent="currentFieldIndex = (currentFieldIndex + 2) % 3; $refs[inputFields[currentFieldIndex]]?.focus()">
        {{-- Product Info (JAN, item code, item name) --}}
        <div class="bg-blue-50 px-2 py-1 border-b border-blue-100">
            <div class="flex items-center gap-1">
                <span class="text-handy-xs font-bold font-mono text-gray-900" x-text="currentItem?.jan_codes?.[0] || currentItem?.item_code"></span>
                <span class="text-[10px] text-gray-500 font-mono" x-text="`(${currentItem?.item_code})`"></span>
            </div>
            <h2 class="text-handy-xs font-bold text-blue-900 leading-tight" x-text="currentItem?.item_name"></h2>
        </div>

        {{-- Schedule Info: Arrival date only --}}
        <div class="bg-amber-50 px-2 py-1 border-b border-amber-200">
            <div class="text-[11px] text-amber-700">
                入荷日: <b class="text-amber-900" x-text="currentSchedule?.expected_arrival_date"></b>
            </div>
        </div>

        {{-- Input Form --}}
        <div class="p-2 space-y-2 flex-1 overflow-y-auto">

            {{-- Expiration Date (1st) --}}
            <div>
                <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                    <i class="ph ph-calendar mr-1"></i>賞味期限 (任意)
                </label>
                <input type="date"
                       x-ref="expirationInput"
                       x-model="inputForm.expiration_date"
                       @focus="currentFieldIndex = 0; $el.showPicker()"
                       class="w-full h-8 px-2 text-handy-xs border border-gray-300 rounded bg-white focus:border-blue-500 outline-none">
            </div>

            {{-- Location Input (2nd) --}}
            <div>
                <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                    <i class="ph ph-map-pin mr-1"></i>ロケ
                </label>
                <div class="relative">
                    <input type="text"
                           x-ref="locationInput"
                           x-model="inputForm.location_search"
                           @input.debounce.300ms="searchLocations()"
                           @focus="currentFieldIndex = 1; showLocationDropdown = true; $el.select()"
                           class="w-full h-8 pl-6 text-handy-xs border-2 border-orange-200 rounded bg-orange-50 focus:bg-white focus:border-orange-500 outline-none uppercase font-bold"
                           placeholder="ロケ">
                    <i class="ph ph-scan absolute left-2 top-2 text-gray-400 text-[11px]"></i>
                </div>
                {{-- Location Dropdown --}}
                <div x-show="showLocationDropdown && filteredLocations.length > 0"
                     @click.outside="showLocationDropdown = false"
                     class="mt-1 bg-white border border-gray-300 rounded shadow-lg max-h-28 overflow-y-auto z-20 relative">
                    <template x-for="loc in filteredLocations" :key="loc.id">
                        <button @click="selectLocation(loc)"
                                class="w-full px-2 py-1 text-left hover:bg-blue-50 border-b border-gray-100 last:border-b-0 text-handy-xs">
                            <span class="font-mono font-bold" x-text="loc.display_name"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Quantity Input (3rd, initial focus) --}}
            <div>
                <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                    <i class="ph ph-package mr-1"></i>入庫予定 : <span class="text-amber-700" x-text="currentSchedule?.expected_quantity"></span>
                </label>
                <input type="number"
                       x-ref="qtyInput"
                       x-model.number="inputForm.qty"
                       @focus="currentFieldIndex = 2; $el.select()"
                       class="w-full h-9 text-center text-handy-sm font-bold border-2 border-blue-300 rounded focus:border-blue-600 outline-none"
                       inputmode="numeric"
                       placeholder="0"
                       min="0">
            </div>
        </div>

    </div>
</template>
