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
        <div class="bg-blue-50 p-2 border-b border-blue-100">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-handy-lg font-bold font-mono text-gray-900" x-text="currentItem?.jan_codes?.[0] || currentItem?.item_code"></span>
                <span class="text-handy-xs text-gray-600 font-mono italic" x-text="`(${currentItem?.item_code})`"></span>
            </div>
            <h2 class="text-handy-base font-bold text-blue-900 leading-tight" x-text="currentItem?.item_name"></h2>
            {{-- Volume & Capacity --}}
            <div class="flex items-center justify-end gap-3 mt-1 text-handy-sm text-gray-600">
                <span x-show="currentItem?.volume">
                    容量: <b x-text="`${currentItem?.volume}${currentItem?.volume_unit || ''}`"></b>
                </span>
                <span x-show="currentItem?.capacity_case">
                    入数: <b x-text="currentItem?.capacity_case"></b>
                </span>
            </div>
        </div>

        {{-- Total Expected Quantity: received/expected --}}
        <div class="bg-amber-50 px-3 py-2 border-b border-amber-200">
            <div class="flex items-center justify-between">
                <span class="text-handy-sm text-amber-700 font-bold">合計入荷予定数</span>
                <span class="text-handy-xl font-bold text-amber-900">
                    <span x-text="currentItem?.total_received_quantity || 0"></span>/<span x-text="currentItem?.total_expected_quantity"></span>
                </span>
            </div>
        </div>

        {{-- Schedule List (Scrollable) --}}
        <div class="flex-1 overflow-y-auto" x-ref="scheduleList">
            <template x-for="(schedule, index) in schedulesToProcess" :key="schedule.id">
                <div @click="selectedScheduleIndex = index; selectScheduleForInput(index)"
                     class="flex items-center justify-between px-3 py-2 border-b border-gray-200 cursor-pointer"
                     :class="[
                         getScheduleRowClass(schedule),
                         selectedScheduleIndex === index ? 'ring-2 ring-inset ring-blue-500' : ''
                     ]"
                     :data-index="index">
                    {{-- Schedule Info --}}
                    <div class="flex-1 min-w-0">
                        {{-- Completed status label (作業中は表示しない) --}}
                        <template x-if="isScheduleCompleted(schedule)">
                            <span class="text-handy-xs font-bold text-green-600">完了</span>
                        </template>
                        {{-- Warehouse and date --}}
                        <div class="flex items-center gap-2">
                            <span class="text-handy-sm font-bold truncate" x-text="schedule.warehouse_name"></span>
                            <span class="text-handy-sm text-gray-600" x-text="formatDateMMDD(schedule.expected_arrival_date)"></span>
                        </div>
                    </div>
                    {{-- Quantity: received/expected --}}
                    <div class="text-handy-lg font-bold text-right shrink-0">
                        <span x-text="getReceivedQuantity(schedule)"></span>/<span x-text="schedule.expected_quantity"></span>
                    </div>
                    {{-- Arrow --}}
                    <div class="ml-2 shrink-0">
                        <i class="ph ph-caret-right text-gray-400 text-handy-lg"></i>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

{{-- Input Screen for single schedule --}}
<template x-if="currentScreen === 'input'">
    <div class="flex flex-col h-full bg-white">
        {{-- Step Indicator --}}
        <template x-if="schedulesToProcess.length > 1">
            <div class="bg-indigo-600 text-white px-3 py-2 flex items-center justify-between">
                <span class="text-handy-sm font-bold">
                    <i class="ph ph-list-numbers mr-1"></i>
                    <span x-text="stepDisplay"></span>
                </span>
                <span class="text-handy-xs bg-indigo-500 px-2 py-1 rounded" x-text="currentSchedule?.warehouse_name"></span>
            </div>
        </template>

        {{-- Schedule Info Header --}}
        <div class="bg-amber-50 px-3 py-2 border-b border-amber-200">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-handy-sm">
                    <span class="font-bold text-amber-900" x-text="currentSchedule?.warehouse_name"></span>
                    <span class="text-amber-700" x-text="currentSchedule?.expected_arrival_date"></span>
                </div>
                <div class="text-handy-xl font-bold text-amber-900" x-text="currentSchedule?.remaining_quantity"></div>
            </div>
        </div>

        {{-- Product Info --}}
        <div class="bg-blue-50 p-2 border-b border-blue-100">
            <div class="flex items-center gap-2">
                <span class="text-handy-base font-bold font-mono text-gray-900" x-text="currentItem?.jan_codes?.[0] || currentItem?.item_code"></span>
                <span class="text-handy-xs text-gray-600 font-mono italic" x-text="`(${currentItem?.item_code})`"></span>
            </div>
            <h2 class="text-handy-sm font-bold text-blue-900 leading-tight mt-1" x-text="currentItem?.item_name"></h2>
        </div>

        {{-- Input Form --}}
        <div class="p-3 space-y-3 flex-1 overflow-y-auto">

            {{-- Location Input --}}
            <div>
                <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                    <i class="ph ph-map-pin mr-1"></i>格納ロケーション
                </label>
                <div class="relative">
                    <input type="text"
                           x-model="inputForm.location_search"
                           @input.debounce.300ms="searchLocations()"
                           @focus="showLocationDropdown = true"
                           class="handy-input w-full pl-8 border-2 border-orange-200 rounded bg-orange-50 focus:bg-white focus:border-orange-500 outline-none uppercase font-bold"
                           placeholder="ロケーション">
                    <i class="ph ph-scan absolute left-2 top-3 text-gray-400 text-handy-lg"></i>
                </div>
                {{-- Location Dropdown --}}
                <div x-show="showLocationDropdown && filteredLocations.length > 0"
                     @click.outside="showLocationDropdown = false"
                     class="mt-1 bg-white border border-gray-300 rounded shadow-lg max-h-32 overflow-y-auto z-20 relative">
                    <template x-for="loc in filteredLocations" :key="loc.id">
                        <button @click="selectLocation(loc)"
                                class="w-full px-2 py-2 text-left hover:bg-blue-50 border-b border-gray-100 last:border-b-0 text-handy-sm">
                            <span class="font-mono font-bold" x-text="loc.display_name"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Quantity Input --}}
            <div>
                <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                    <i class="ph ph-package mr-1"></i>入庫数量
                </label>
                <input type="number"
                       x-model.number="inputForm.qty"
                       class="w-full h-12 text-center text-handy-2xl font-bold border-2 border-blue-300 rounded focus:border-blue-600 outline-none"
                       inputmode="numeric"
                       placeholder="0"
                       min="0">
                {{-- Quick Set Button --}}
                <button @click="inputForm.qty = currentSchedule?.remaining_quantity || 0"
                        class="w-full mt-1 py-2 bg-blue-50 text-blue-600 rounded border border-blue-200 text-handy-sm font-bold">
                    予定数セット
                </button>
            </div>

            {{-- Expiration Date --}}
            <div>
                <label class="block text-gray-700 font-bold mb-1 text-handy-sm">
                    <i class="ph ph-calendar mr-1"></i>賞味期限 (任意)
                </label>
                <input type="date"
                       x-model="inputForm.expiration_date"
                       class="handy-input w-full border border-gray-300 rounded bg-white focus:border-blue-500 outline-none">
            </div>
        </div>

    </div>
</template>
