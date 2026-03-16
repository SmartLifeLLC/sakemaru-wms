import apiClient from './api-client';

export const pickingService = {
    async getTasks(warehouseId, pickerId) {
        let endpoint = `/picking/tasks?warehouse_id=${warehouseId}`;
        if (pickerId) {
            endpoint += `&picker_id=${pickerId}`;
        }
        return apiClient.get(endpoint);
    },

    async getTaskDetail(taskId) {
        return apiClient.get(`/picking/tasks/${taskId}`);
    },

    async getItemDetail(itemId) {
        return apiClient.get(`/picking/items/${itemId}`);
    },

    async startTask(taskId) {
        return apiClient.post(`/picking/tasks/${taskId}/start`);
    },

    async updateItem(itemResultId, data) {
        return apiClient.post(`/picking/tasks/${itemResultId}/update`, data);
    },

    async cancelItem(itemResultId) {
        return apiClient.post(`/picking/tasks/${itemResultId}/cancel`);
    },

    async completeTask(taskId) {
        return apiClient.post(`/picking/tasks/${taskId}/complete`);
    },
};
