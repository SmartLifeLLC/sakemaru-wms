import apiClient from './api-client';

export const incomingService = {
    async getSchedules(warehouseId, search = '', page = 1) {
        let endpoint = `/incoming/schedules?warehouse_id=${warehouseId}&page=${page}`;
        if (search) {
            endpoint += `&search=${encodeURIComponent(search)}`;
        }
        return apiClient.get(endpoint);
    },

    async getScheduleDetail(scheduleId) {
        return apiClient.get(`/incoming/schedules/${scheduleId}`);
    },

    async getLocations(warehouseId, search = '') {
        let endpoint = `/incoming/locations?warehouse_id=${warehouseId}`;
        if (search) {
            endpoint += `&search=${encodeURIComponent(search)}`;
        }
        return apiClient.get(endpoint);
    },

    async createWorkItem(data) {
        return apiClient.post('/incoming/work-items', data);
    },

    async updateWorkItem(id, data) {
        return apiClient.put(`/incoming/work-items/${id}`, data);
    },

    async completeWorkItem(id) {
        return apiClient.post(`/incoming/work-items/${id}/complete`);
    },

    async getWorkItems(warehouseId, status = 'all') {
        return apiClient.get(`/incoming/work-items?warehouse_id=${warehouseId}&status=${status}&limit=100`);
    },

    async deleteWorkItem(id) {
        return apiClient.delete(`/incoming/work-items/${id}`);
    },
};
