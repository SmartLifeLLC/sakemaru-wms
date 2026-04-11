import apiClient from './api-client';

export const authService = {
    async login(code, password) {
        return apiClient.post('/auth/login', { code, password });
    },

    async logout() {
        return apiClient.post('/auth/logout');
    },

    async getMe() {
        return apiClient.get('/me');
    },
};
