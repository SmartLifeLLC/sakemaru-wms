import apiClient from './api-client';

export const proxyShipmentService = {
    async getList(warehouseId, shipmentDate = null, deliveryCourseId = null) {
        let endpoint = `/proxy-shipments?warehouse_id=${warehouseId}`;
        if (shipmentDate) {
            endpoint += `&shipment_date=${encodeURIComponent(shipmentDate)}`;
        }
        if (deliveryCourseId) {
            endpoint += `&delivery_course_id=${deliveryCourseId}`;
        }
        return apiClient.get(endpoint);
    },

    async getDetail(allocationId, warehouseId) {
        return apiClient.get(`/proxy-shipments/${allocationId}?warehouse_id=${warehouseId}`);
    },

    async start(allocationId, warehouseId) {
        return apiClient.post(`/proxy-shipments/${allocationId}/start`, {
            warehouse_id: warehouseId,
        });
    },

    async update(allocationId, warehouseId, pickedQty) {
        return apiClient.post(`/proxy-shipments/${allocationId}/update`, {
            warehouse_id: warehouseId,
            picked_qty: pickedQty,
        });
    },

    async complete(allocationId, warehouseId, pickedQty = null) {
        const data = { warehouse_id: warehouseId };
        if (pickedQty !== null) {
            data.picked_qty = pickedQty;
        }
        return apiClient.post(`/proxy-shipments/${allocationId}/complete`, data);
    },
};
