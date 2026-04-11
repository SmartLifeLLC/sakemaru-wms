import { authService } from '../services/auth-service';
import apiClient from '../services/api-client';
import { getItem, setItem, removeItem } from '../utils/storage';
import { STORAGE_KEYS } from '../utils/constants';

export function createAuthStore() {
    return {
        token: null,
        picker: null,

        get isAuthenticated() {
            return !!this.token && !!this.picker;
        },

        init() {
            this.token = getItem(STORAGE_KEYS.TOKEN);
            this.picker = getItem(STORAGE_KEYS.PICKER);
            if (this.token) {
                apiClient.token = this.token;
            }
        },

        async login(code, password) {
            const response = await authService.login(code, password);
            if (response.is_success && response.result?.data) {
                const { token, picker } = response.result.data;
                this.token = token;
                this.picker = picker;
                apiClient.token = token;
                setItem(STORAGE_KEYS.TOKEN, token);
                setItem(STORAGE_KEYS.PICKER, picker);
                return true;
            }
            return false;
        },

        async checkAuth() {
            if (!this.token) return false;
            try {
                const response = await authService.getMe();
                if (response.is_success && response.result?.data) {
                    this.picker = response.result.data;
                    setItem(STORAGE_KEYS.PICKER, this.picker);
                    return true;
                }
            } catch {
                this.clearSession();
            }
            return false;
        },

        async logout() {
            try {
                await authService.logout();
            } catch {
                // ignore logout errors
            }
            this.clearSession();
        },

        clearSession() {
            this.token = null;
            this.picker = null;
            apiClient.token = null;
            removeItem(STORAGE_KEYS.TOKEN);
            removeItem(STORAGE_KEYS.PICKER);
        },
    };
}
