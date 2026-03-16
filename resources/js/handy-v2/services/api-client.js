/**
 * API Client for Handy V2
 * Wraps fetch with auth headers, error handling, and response parsing.
 */

class ApiError extends Error {
    constructor(message, code, data) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.data = data;
    }
}

const apiClient = {
    baseUrl: '/api',
    apiKey: '',
    token: null,
    onAuthError: null,

    init(config) {
        this.baseUrl = config.baseUrl || '/api';
        this.apiKey = config.apiKey || '';
    },

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
                console.error('Authentication error - clearing session');
                if (this.onAuthError) {
                    this.onAuthError();
                }
            }

            const errorMsg = json.result?.error_message
                || json.result?.message
                || json.message
                || json.error
                || `API Error (${response.status})`;

            throw new ApiError(errorMsg, json.code, json.result?.data);
        }

        return json;
    },

    get(endpoint) { return this.request('GET', endpoint); },
    post(endpoint, data) { return this.request('POST', endpoint, data); },
    put(endpoint, data) { return this.request('PUT', endpoint, data); },
    delete(endpoint) { return this.request('DELETE', endpoint); },
};

export { apiClient, ApiError };
export default apiClient;
