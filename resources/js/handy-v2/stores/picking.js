import { pickingService } from '../services/picking-service';

export function createPickingStore() {
    return {
        // Task list (grouped by course)
        // API returns: { course, picking_area, wave: { wms_picking_task_id, wms_wave_id }, picking_list: [...items] }
        taskGroups: [],
        expandedGroup: null,

        // Current picking session
        currentTask: null, // The group object being worked on
        currentItemIndex: 0,
        pickedItems: [],

        // Barcode
        scannedBarcode: '',
        barcodeMatch: null, // null | 'match' | 'mismatch'

        // Quantity form
        pickedQty: 0,

        // Result
        lastResult: null,

        async loadTasks(warehouseId, pickerId) {
            const response = await pickingService.getTasks(warehouseId, pickerId);
            if (response.is_success && response.result?.data) {
                this.taskGroups = response.result.data;
                // Auto-expand first group
                if (this.taskGroups.length > 0) {
                    this.expandedGroup = 0;
                }
            }
        },

        toggleGroup(index) {
            this.expandedGroup = this.expandedGroup === index ? null : index;
        },

        // group = { course, picking_area, wave: { wms_picking_task_id }, picking_list: [...] }
        async startTask(group) {
            const taskId = group.wave?.wms_picking_task_id;
            if (!taskId) return false;

            const response = await pickingService.startTask(taskId);
            if (response.is_success) {
                // Use the group data directly since startTask response may not include picking_list
                this.currentTask = {
                    ...group,
                    wms_picking_task_id: taskId,
                };
                this.currentItemIndex = 0;
                this.pickedItems = [];
                this.initCurrentItem();
                return true;
            }
            return false;
        },

        initCurrentItem() {
            const item = this.currentItem;
            if (!item) return;
            this.pickedQty = item.planned_qty || 0;
            this.scannedBarcode = '';
            this.barcodeMatch = null;
        },

        get currentItem() {
            if (!this.currentTask?.picking_list) return null;
            return this.currentTask.picking_list[this.currentItemIndex] || null;
        },

        get totalItems() {
            return this.currentTask?.picking_list?.length || 0;
        },

        get progressText() {
            return `${this.currentItemIndex + 1} / ${this.totalItems}`;
        },

        // Barcode scanning
        checkBarcode(barcode) {
            this.scannedBarcode = barcode;
            const item = this.currentItem;
            if (!item) {
                this.barcodeMatch = 'mismatch';
                return;
            }
            const janList = item.jan_code_list || [];
            const janCode = item.jan_code || '';
            const allCodes = [...janList];
            if (janCode && !allCodes.includes(janCode)) {
                allCodes.push(janCode);
            }
            this.barcodeMatch = allCodes.includes(barcode) ? 'match' : 'mismatch';
        },

        // Quantity
        incrementQty() {
            this.pickedQty = (parseInt(this.pickedQty) || 0) + 1;
        },

        decrementQty() {
            const current = parseInt(this.pickedQty) || 0;
            if (current > 0) {
                this.pickedQty = current - 1;
            }
        },

        // Submit current item
        async submitItem() {
            const item = this.currentItem;
            if (!item) return false;

            const response = await pickingService.updateItem(
                item.wms_picking_item_result_id,
                { picked_qty: parseInt(this.pickedQty) || 0 }
            );

            if (response.is_success) {
                this.pickedItems.push({
                    ...item,
                    picked_qty: parseInt(this.pickedQty) || 0,
                });
                return true;
            }
            return false;
        },

        // Mark current item as shortage (qty=0)
        async markShortage() {
            const item = this.currentItem;
            if (!item) return false;

            const response = await pickingService.updateItem(
                item.wms_picking_item_result_id,
                { picked_qty: 0 }
            );

            if (response.is_success) {
                this.pickedItems.push({
                    ...item,
                    picked_qty: 0,
                    isShortage: true,
                });
                return true;
            }
            return false;
        },

        // Move to next item
        moveToNext() {
            if (this.currentItemIndex < this.totalItems - 1) {
                this.currentItemIndex++;
                this.initCurrentItem();
                return true;
            }
            return false; // All items done
        },

        // Skip current item (don't update, just move)
        skipItem() {
            return this.moveToNext();
        },

        // Complete task
        async completeTask() {
            if (!this.currentTask) return false;

            const taskId = this.currentTask.wms_picking_task_id
                || this.currentTask.wave?.wms_picking_task_id;

            if (!taskId) return false;

            const response = await pickingService.completeTask(taskId);

            if (response.is_success) {
                this.lastResult = {
                    task: this.currentTask,
                    pickedItems: this.pickedItems,
                    message: response.result?.message || 'タスクを完了しました',
                };
                return true;
            }
            return false;
        },

        // Reset
        reset() {
            this.currentTask = null;
            this.currentItemIndex = 0;
            this.pickedItems = [];
            this.scannedBarcode = '';
            this.barcodeMatch = null;
            this.pickedQty = 0;
            this.lastResult = null;
        },

        // Status helpers
        getStatusLabel(status) {
            const map = {
                PENDING: '未着手',
                PICKING_READY: 'ピッキング待ち',
                PICKING: 'ピッキング中',
                COMPLETED: '完了',
                SHORTAGE: '欠品',
            };
            return map[status] || status;
        },

        getStatusColor(status) {
            const map = {
                PENDING: 'bg-gray-400',
                PICKING_READY: 'bg-blue-500',
                PICKING: 'bg-orange-500',
                COMPLETED: 'bg-green-500',
                SHORTAGE: 'bg-red-500',
            };
            return map[status] || 'bg-gray-400';
        },

        getQtyTypeLabel(type) {
            const map = {
                CASE: 'ケース',
                PIECE: 'バラ',
                CARTON: 'ボール',
            };
            return map[type] || type || '';
        },
    };
}
