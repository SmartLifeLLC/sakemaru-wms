import { proxyShipmentService } from '../services/proxy-shipment-service';

export function createProxyShipmentStore() {
    return {
        // List
        allocations: [],
        summary: null,
        businessDate: null,

        // Filters
        shipmentDateFilter: '',
        deliveryCourseFilter: null,

        // Current work
        currentAllocation: null,
        candidateLocations: [],
        shortageDetail: null,

        // Picking
        pickedQty: 0,

        // Result
        lastResult: null,

        // Loading
        isSearching: false,

        async loadAllocations(warehouseId) {
            this.isSearching = true;
            try {
                const response = await proxyShipmentService.getList(
                    warehouseId,
                    this.shipmentDateFilter || null,
                    this.deliveryCourseFilter || null,
                );
                if (response.is_success && response.result?.data) {
                    this.allocations = response.result.data.items || [];
                    this.summary = response.result.data.summary || null;
                    if (response.result.data.meta?.business_date && !this.shipmentDateFilter) {
                        this.shipmentDateFilter = response.result.data.meta.business_date;
                        this.businessDate = response.result.data.meta.business_date;
                    }
                }
            } finally {
                this.isSearching = false;
            }
        },

        async loadDetail(allocationId, warehouseId) {
            const response = await proxyShipmentService.getDetail(allocationId, warehouseId);
            if (response.is_success && response.result?.data) {
                this.currentAllocation = response.result.data;
                this.candidateLocations = response.result.data.candidate_locations || [];
                this.shortageDetail = response.result.data.shortage_detail || null;
                this.pickedQty = response.result.data.picked_qty || 0;
                return true;
            }
            return false;
        },

        async startAllocation(allocationId, warehouseId) {
            const response = await proxyShipmentService.start(allocationId, warehouseId);
            if (response.is_success && response.result?.data) {
                this.currentAllocation = response.result.data;
                return true;
            }
            return false;
        },

        async updateAllocation(allocationId, warehouseId, pickedQty) {
            const response = await proxyShipmentService.update(allocationId, warehouseId, pickedQty);
            if (response.is_success && response.result?.data) {
                this.currentAllocation = response.result.data;
                return true;
            }
            return false;
        },

        async completeAllocation(allocationId, warehouseId, pickedQty) {
            const response = await proxyShipmentService.complete(allocationId, warehouseId, pickedQty);
            if (response.is_success && response.result) {
                this.lastResult = {
                    allocation: response.result.data,
                    stockTransferQueueId: response.result.data.stock_transfer_queue_id,
                    message: response.result.message,
                };
                return true;
            }
            return false;
        },

        incrementQty() {
            const max = this.currentAllocation?.assign_qty || 0;
            if (this.pickedQty < max) {
                this.pickedQty++;
            }
        },

        decrementQty() {
            if (this.pickedQty > 0) {
                this.pickedQty--;
            }
        },

        checkBarcode(barcode) {
            if (!this.currentAllocation?.item?.jan_codes) return false;
            return this.currentAllocation.item.jan_codes.includes(barcode);
        },

        reset() {
            this.currentAllocation = null;
            this.candidateLocations = [];
            this.shortageDetail = null;
            this.pickedQty = 0;
            this.lastResult = null;
        },

        resetAll() {
            this.allocations = [];
            this.summary = null;
            this.shipmentDateFilter = '';
            this.deliveryCourseFilter = null;
            this.businessDate = null;
            this.reset();
        },
    };
}
