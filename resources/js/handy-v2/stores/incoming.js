import { incomingService } from '../services/incoming-service';
import { PAGE_SIZE } from '../utils/constants';

export function createIncomingStore() {
    return {
        // Schedule list
        schedules: [],
        searchQuery: '',
        page: 1,
        hasMore: true,
        isSearching: false,

        // Current work
        currentSchedule: null,
        currentScheduleItem: null, // The item-level data from list
        workItem: null,

        // Work form
        workForm: {
            work_quantity: 0,
            work_arrival_date: '',
            work_expiration_date: '',
            location_id: null,
        },

        // Location search
        locations: [],
        locationSearch: '',
        selectedLocation: null,
        showLocationDropdown: false,

        // Result
        lastResult: null,

        // History
        history: [],
        historyTab: 'today', // 'today' | 'all'

        // Edit mode
        isEditing: false,
        editingWorkItem: null,

        // Confirm dialog
        showCancelConfirm: false,
        cancelTargetId: null,

        async loadSchedules(warehouseId, reset = true) {
            if (reset) {
                this.page = 1;
                this.schedules = [];
                this.hasMore = true;
            }
            this.isSearching = true;
            try {
                const response = await incomingService.getSchedules(
                    warehouseId, this.searchQuery, this.page
                );
                if (response.is_success && response.result?.data) {
                    const items = response.result.data;
                    if (reset) {
                        this.schedules = items;
                    } else {
                        this.schedules = [...this.schedules, ...items];
                    }
                    this.hasMore = items.length >= PAGE_SIZE;
                }
            } finally {
                this.isSearching = false;
            }
        },

        async loadMore(warehouseId) {
            if (!this.hasMore || this.isSearching) return;
            this.page++;
            await this.loadSchedules(warehouseId, false);
        },

        async startWork(warehouseId, scheduleItem, scheduleId, pickerId) {
            this.currentScheduleItem = scheduleItem;
            const response = await incomingService.createWorkItem({
                incoming_schedule_id: scheduleId,
                picker_id: pickerId,
                warehouse_id: warehouseId,
            });
            if (response.is_success && response.result?.data) {
                this.workItem = response.result.data;
                this.initWorkForm();
                return true;
            }
            return false;
        },

        initWorkForm() {
            if (!this.workItem) return;
            this.workForm = {
                work_quantity: this.workItem.work_quantity || 0,
                work_arrival_date: this.workItem.work_arrival_date || this.todayStr(),
                work_expiration_date: this.workItem.work_expiration_date || '',
                location_id: this.workItem.location_id || null,
            };
            this.selectedLocation = this.workItem.location || null;
            this.isEditing = false;
        },

        async updateWork() {
            if (!this.workItem) return false;
            const response = await incomingService.updateWorkItem(this.workItem.id, {
                work_quantity: parseInt(this.workForm.work_quantity) || 0,
                work_arrival_date: this.workForm.work_arrival_date,
                work_expiration_date: this.workForm.work_expiration_date || null,
                location_id: this.workForm.location_id,
            });
            if (response.is_success && response.result?.data) {
                this.workItem = response.result.data;
                return true;
            }
            return false;
        },

        async completeWork() {
            if (!this.workItem) return false;
            // First save current form values
            await this.updateWork();
            const response = await incomingService.completeWorkItem(this.workItem.id);
            if (response.is_success) {
                this.lastResult = {
                    workItem: this.workItem,
                    scheduleItem: this.currentScheduleItem,
                    message: response.result?.message || '入庫を確定しました',
                };
                return true;
            }
            return false;
        },

        async loadHistory(warehouseId) {
            const response = await incomingService.getWorkItems(warehouseId);
            if (response.is_success && response.result?.data) {
                this.history = response.result.data;
            }
        },

        async editWorkItem(workItem, scheduleItem) {
            this.workItem = workItem;
            this.currentScheduleItem = scheduleItem;
            this.isEditing = true;
            this.initWorkForm();
        },

        async cancelWork(id) {
            const response = await incomingService.deleteWorkItem(id);
            if (response.is_success) {
                this.history = this.history.filter(h => h.id !== id);
                return true;
            }
            return false;
        },

        async searchLocations(warehouseId) {
            const response = await incomingService.getLocations(warehouseId, this.locationSearch);
            if (response.is_success && response.result?.data) {
                this.locations = response.result.data;
            }
        },

        selectLocation(location) {
            this.selectedLocation = location;
            this.workForm.location_id = location.id;
            this.locationSearch = location.display_name || '';
            this.showLocationDropdown = false;
        },

        incrementQty() {
            this.workForm.work_quantity = (parseInt(this.workForm.work_quantity) || 0) + 1;
        },

        decrementQty() {
            const current = parseInt(this.workForm.work_quantity) || 0;
            if (current > 0) {
                this.workForm.work_quantity = current - 1;
            }
        },

        reset() {
            this.currentSchedule = null;
            this.currentScheduleItem = null;
            this.workItem = null;
            this.lastResult = null;
            this.isEditing = false;
            this.editingWorkItem = null;
            this.locations = [];
            this.locationSearch = '';
            this.selectedLocation = null;
            this.showLocationDropdown = false;
        },

        todayStr() {
            const d = new Date();
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        },
    };
}
