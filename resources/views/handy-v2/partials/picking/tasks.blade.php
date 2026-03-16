<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-sm font-bold text-gray-800">ピッキングタスク</h2>
        <button
            class="wms-btn text-xs px-3 bg-gray-100 text-gray-700 border border-gray-300"
            style="min-height: 32px;"
            @click="loadPickingTasks()"
        >
            更新
        </button>
    </div>

    {{-- Task Groups --}}
    <div class="flex-1 overflow-y-auto p-3 space-y-2">
        <template x-if="picking.taskGroups.length === 0">
            <div class="text-center text-gray-400 py-8 text-sm">
                ピッキングタスクがありません
            </div>
        </template>

        {{-- Each group = { course, picking_area, wave, picking_list } --}}
        <template x-for="(group, groupIndex) in picking.taskGroups" :key="groupIndex">
            <div class="wms-card overflow-hidden">
                {{-- Group Header --}}
                <button
                    class="w-full px-3 py-2 flex items-center justify-between bg-gray-50 text-left"
                    @click="picking.toggleGroup(groupIndex)"
                >
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400 transition-transform"
                             :class="{ 'rotate-90': picking.expandedGroup === groupIndex }"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                        <span class="text-sm font-bold text-gray-700" x-text="group.course?.name || '未分類'"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-400" x-text="group.picking_area?.name || ''"></span>
                        <span class="text-xs text-gray-500" x-text="(group.picking_list?.length || 0) + '品'"></span>
                    </div>
                </button>

                {{-- Expanded Content --}}
                <div x-show="picking.expandedGroup === groupIndex" x-transition>
                    <div class="px-3 py-2 border-t border-gray-100">
                        {{-- Item count and progress --}}
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-xs text-gray-500">
                                <span>コース: </span>
                                <span class="font-medium" x-text="group.course?.code || '-'"></span>
                            </div>
                            <div class="text-xs text-gray-500">
                                <span x-text="(group.picking_list?.filter(i => i.status === 'PICKED' || i.status === 'COMPLETED').length || 0)"></span>
                                <span> / </span>
                                <span x-text="group.picking_list?.length || 0"></span>
                                <span> 完了</span>
                            </div>
                        </div>

                        {{-- Progress Bar --}}
                        <div class="w-full h-1.5 bg-gray-200 rounded-full mb-3">
                            <div class="h-full bg-orange-500 rounded-full transition-all"
                                 :style="'width: ' + (group.picking_list?.length ? Math.round((group.picking_list.filter(i => i.status === 'PICKED' || i.status === 'COMPLETED').length / group.picking_list.length) * 100) : 0) + '%'"></div>
                        </div>

                        {{-- Item List Preview (first 3) --}}
                        <div class="space-y-1 mb-3">
                            <template x-for="(item, idx) in (group.picking_list || []).slice(0, 3)" :key="item.wms_picking_item_result_id">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-2 h-2 rounded-full flex-shrink-0"
                                          :class="item.picked_qty > 0 ? 'bg-green-500' : 'bg-gray-300'"></span>
                                    <span class="truncate flex-1 text-gray-600" x-text="item.item_name"></span>
                                    <span class="text-gray-400" x-text="item.planned_qty + ' ' + picking.getQtyTypeLabel(item.planned_qty_type)"></span>
                                </div>
                            </template>
                            <template x-if="(group.picking_list?.length || 0) > 3">
                                <div class="text-xs text-gray-400 pl-4" x-text="'... 他 ' + ((group.picking_list?.length || 0) - 3) + '品'"></div>
                            </template>
                        </div>

                        {{-- Start Button --}}
                        <button
                            class="wms-btn wms-btn-primary w-full text-sm"
                            @click="startPickingTask(group)"
                        >
                            ピッキング開始
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
