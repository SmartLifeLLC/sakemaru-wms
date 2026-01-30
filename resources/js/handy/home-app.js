import Alpine from 'alpinejs';

/**
 * Handy Home App - BHT-M60 Handy Terminal Web Application
 *
 * Alpine.js based home page with warehouse selection and menu
 */

// API Client
const api = {
    baseUrl: window.HANDY_CONFIG?.baseUrl || '/api',
    apiKey: window.HANDY_CONFIG?.apiKey || '',
    token: null,

    getHeaders() {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-API-Key': this.apiKey,
        };
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }
        return headers;
    },

    async request(method, endpoint, data = null) {
        const url = `${this.baseUrl}${endpoint}`;
        const options = {
            method,
            headers: this.getHeaders(),
        };
        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);
        const json = await response.json();

        if (!response.ok) {
            if (response.status === 401) {
                // Redirect to login
                localStorage.removeItem('handy_token');
                localStorage.removeItem('handy_picker');
                window.location.href = '/handy/login';
                return;
            }

            const errorMsg = json.result?.error_message
                || json.result?.message
                || json.message
                || json.error
                || `API Error (${response.status})`;
            const error = new Error(errorMsg);
            error.code = json.code;
            throw error;
        }

        return json;
    },

    get(endpoint) { return this.request('GET', endpoint); },
    post(endpoint, data) { return this.request('POST', endpoint, data); },
};

// Main Application
window.handyHomeApp = function() {
    return {
        // Screen Management
        currentScreen: 'warehouse', // warehouse, menu

        // Authentication
        picker: null,

        // Data
        warehouses: [],
        selectedWarehouse: null,

        // State
        isLoading: false,
        loadingMessage: '',

        // Notification
        notification: { show: false, message: '', type: 'info' },

        // Initialize
        async init() {
            const config = window.HANDY_CONFIG || {};

            // auth_key is required - if not present, redirect to login
            if (!config.authKey) {
                window.location.href = '/handy/login';
                return;
            }

            api.token = config.authKey;

            // Get picker info
            const savedPicker = localStorage.getItem('handy_picker');
            if (savedPicker) {
                this.picker = JSON.parse(savedPicker);
            } else {
                // Fetch picker info
                try {
                    const response = await api.get('/me');
                    if (response.is_success && response.result?.data) {
                        this.picker = response.result.data;
                        localStorage.setItem('handy_picker', JSON.stringify(this.picker));
                    }
                } catch (e) {
                    // Token invalid, redirect to login
                    localStorage.removeItem('handy_token');
                    localStorage.removeItem('handy_picker');
                    window.location.href = '/handy/login';
                    return;
                }
            }

            // Load warehouses
            await this.loadWarehouses();
        },

        // Header Title
        getHeaderTitle() {
            if (this.currentScreen === 'menu') {
                return 'メニュー';
            }
            return '倉庫選択';
        },

        // Load Warehouses
        async loadWarehouses() {
            this.isLoading = true;
            this.loadingMessage = '倉庫一覧を取得中...';

            try {
                const response = await api.get('/master/warehouses');
                if (response.is_success && response.result?.data) {
                    this.warehouses = response.result.data;
                }
            } catch (error) {
                this.showNotification('倉庫一覧の取得に失敗しました: ' + error.message, 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        // Select Warehouse
        selectWarehouse(warehouse) {
            this.selectedWarehouse = warehouse;
            this.currentScreen = 'menu';
        },

        // Change Warehouse
        changeWarehouse() {
            this.currentScreen = 'warehouse';
        },

        // Navigation
        goToIncoming() {
            const authKey = api.token;
            const warehouseId = this.selectedWarehouse.id;
            window.location.href = `/handy/incoming?auth_key=${encodeURIComponent(authKey)}&warehouse_id=${warehouseId}`;
        },

        goToOutgoing() {
            const authKey = api.token;
            const warehouseId = this.selectedWarehouse.id;
            window.location.href = `/handy/outgoing?auth_key=${encodeURIComponent(authKey)}&warehouse_id=${warehouseId}`;
        },

        // Logout
        async logout() {
            try {
                await api.post('/auth/logout');
            } catch (e) {
                // Ignore logout errors
            }

            // Clear localStorage
            localStorage.removeItem('handy_token');
            localStorage.removeItem('handy_picker');

            // Redirect to login
            window.location.href = '/handy/login';
        },

        // Notification
        showNotification(message, type = 'info') {
            this.notification = { show: true, message, type };
            setTimeout(() => {
                this.notification.show = false;
            }, 3000);
        },
    };
};

// Initialize Alpine
window.Alpine = Alpine;
Alpine.start();
