import apiClient from './api-client';

export const masterService = {
    async getWarehouses() {
        return apiClient.get('/master/warehouses');
    },
};
