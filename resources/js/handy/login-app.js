import Alpine from 'alpinejs';

/**
 * Handy Login App - BHT-M60 Handy Terminal Web Application
 *
 * Alpine.js based login page
 */

// API Client
const api = {
    baseUrl: window.HANDY_CONFIG?.baseUrl || '/api',
    apiKey: window.HANDY_CONFIG?.apiKey || '',

    getHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-API-Key': this.apiKey,
        };
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

    post(endpoint, data) { return this.request('POST', endpoint, data); },
};

// Main Application
window.handyLoginApp = function() {
    return {
        // Authentication
        loginForm: { code: '', password: '' },
        loginError: '',

        // State
        isLoading: false,
        loadingMessage: '',

        // Initialize
        async init() {
            // Check for saved token in localStorage
            const savedToken = localStorage.getItem('handy_token');
            if (savedToken) {
                // Already logged in, redirect to home
                window.location.href = '/handy/home?auth_key=' + encodeURIComponent(savedToken);
            }
        },

        // Login
        async login() {
            this.loginError = '';
            this.isLoading = true;
            this.loadingMessage = 'ログイン中...';

            try {
                const response = await api.post('/auth/login', {
                    code: this.loginForm.code,
                    password: this.loginForm.password,
                });

                if (response.is_success && response.result?.data) {
                    const token = response.result.data.token;
                    const picker = response.result.data.picker;

                    // Save to localStorage
                    localStorage.setItem('handy_token', token);
                    localStorage.setItem('handy_picker', JSON.stringify(picker));

                    // Redirect to home with auth_key
                    window.location.href = '/handy/home?auth_key=' + encodeURIComponent(token);
                } else {
                    this.loginError = response.message || 'ログインに失敗しました';
                }
            } catch (error) {
                this.loginError = error.message || 'ログインに失敗しました';
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },
    };
};

// Initialize Alpine
window.Alpine = Alpine;
Alpine.start();
