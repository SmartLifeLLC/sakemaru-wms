import Alpine from 'alpinejs';

/**
 * Handy Incoming App - BHT-M60 Handy Terminal Web Application
 *
 * Alpine.js based SPA for warehouse incoming operations
 */

// API Client
const api = {
    baseUrl: window.HANDY_CONFIG?.baseUrl || '/api',
    apiKey: window.HANDY_CONFIG?.apiKey || '',
    token: null,
    onAuthError: null,  // Callback for auth errors

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
            // Check for authentication error
            if (response.status === 401) {
                console.error('Authentication error - clearing session');
                if (this.onAuthError) {
                    this.onAuthError();
                }
            }

            // Extract error message from various response formats
            const errorMsg = json.result?.error_message
                || json.result?.message
                || json.message
                || json.error
                || `API Error (${response.status})`;
            console.error('API Error:', { url, method, status: response.status, response: json });
            throw new Error(errorMsg);
        }

        return json;
    },

    get(endpoint) { return this.request('GET', endpoint); },
    post(endpoint, data) { return this.request('POST', endpoint, data); },
    put(endpoint, data) { return this.request('PUT', endpoint, data); },
    delete(endpoint) { return this.request('DELETE', endpoint); },
};

// Main Application
window.handyIncomingApp = function() {
    return {
        // Screen Management
        currentScreen: 'login', // login, warehouse, list, process, input, result, history

        // Authentication
        isAuthenticated: false,
        loginForm: { code: '', password: '' },
        loginError: '',
        picker: null,

        // Data
        warehouses: [],
        selectedWarehouse: null,
        allProducts: [],  // All products from API
        workingScheduleIds: [],  // Schedule IDs that are currently being worked on
        currentItem: null,
        history: [],
        filteredLocations: [],
        showLocationDropdown: false,

        // Pagination (infinite scroll)
        displayCount: 50,  // Number of items currently displayed
        perPage: 50,       // Items to load per scroll

        // Input Form
        inputForm: {
            schedule_id: null,
            qty: 0,
            location_search: '',
            location_id: null,
            expiration_date: '',
        },
        searchQuery: '',

        // Sequential schedule processing
        schedulesToProcess: [],      // All schedules for current item
        currentScheduleIndex: 0,     // Current index (0-based)
        selectedScheduleIndex: 0,    // Currently selected schedule in list (for keyboard navigation)

        // State
        isLoading: false,
        loadingMessage: '',
        editingWorkItem: null,
        lastResult: null,

        // Notification
        notification: { show: false, message: '', type: 'info' },

        // Initialize
        async init() {
            const config = window.HANDY_CONFIG || {};
            const urlWarehouseId = config.warehouseId ? Number(config.warehouseId) : null;

            // Set up auth error handler
            api.onAuthError = () => this.handleAuthError();

            // Check for URL parameter auth_key
            if (config.authKey) {
                api.token = config.authKey;
                this.isAuthenticated = true;

                // Fetch picker info with the token
                try {
                    const response = await api.get('/me');
                    if (response.is_success && response.result?.data) {
                        this.picker = response.result.data;
                        localStorage.setItem('handy_token', api.token);
                        localStorage.setItem('handy_picker', JSON.stringify(this.picker));
                    }
                } catch (e) {
                    // Token invalid, fall back to login
                    api.token = null;
                    this.isAuthenticated = false;
                    this.currentScreen = 'login';
                    return;
                }

                // Check for URL parameter warehouse_id
                if (urlWarehouseId) {
                    await this.loadWarehouses();
                    const warehouse = this.warehouses.find(w => Number(w.id) === urlWarehouseId);
                    if (warehouse) {
                        this.selectedWarehouse = warehouse;
                        this.currentScreen = 'list';
                        await this.searchProducts();
                        return;
                    }
                }

                // No warehouse_id, go to warehouse selection
                this.currentScreen = 'warehouse';
                await this.loadWarehouses();
                return;
            }

            // Check for saved token in localStorage
            const savedToken = localStorage.getItem('handy_token');
            const savedPicker = localStorage.getItem('handy_picker');

            if (savedToken && savedPicker) {
                api.token = savedToken;
                this.picker = JSON.parse(savedPicker);
                this.isAuthenticated = true;

                // Check for URL parameter warehouse_id (with localStorage auth)
                if (urlWarehouseId) {
                    await this.loadWarehouses();
                    const warehouse = this.warehouses.find(w => Number(w.id) === urlWarehouseId);
                    if (warehouse) {
                        this.selectedWarehouse = warehouse;
                        this.currentScreen = 'list';
                        await this.searchProducts();
                        return;
                    }
                }

                this.currentScreen = 'warehouse';
                await this.loadWarehouses();
            }
        },

        // Header Title
        getHeaderTitle() {
            // 倉庫選択後は「{倉庫名} 入庫処理」を表示
            if (this.selectedWarehouse) {
                return `${this.selectedWarehouse.name} 入庫処理`;
            }

            const titles = {
                'login': 'ログイン',
                'warehouse': '倉庫選択',
            };
            return titles[this.currentScreen] || '入庫';
        },

        // Navigation
        goBack() {
            if (this.currentScreen === 'list') {
                this.selectedWarehouse = null;
                this.currentScreen = 'warehouse';
            } else if (this.currentScreen === 'process') {
                this.currentScreen = 'list';
                this.editingWorkItem = null;
                // Refresh working status
                this.loadWorkingScheduleIds();
            } else if (this.currentScreen === 'input') {
                this.currentScreen = 'process';
                this.editingWorkItem = null;
            } else if (this.currentScreen === 'result') {
                this.currentScreen = 'list';
            } else if (this.currentScreen === 'history') {
                this.currentScreen = 'list';
            }
        },

        // ==================== Authentication ====================

        // Handle authentication errors - clear session and show login
        handleAuthError() {
            console.log('handleAuthError: Clearing session and redirecting to login');

            // Clear API token
            api.token = null;

            // Clear local state
            this.picker = null;
            this.isAuthenticated = false;
            this.selectedWarehouse = null;

            // Clear localStorage
            localStorage.removeItem('handy_token');
            localStorage.removeItem('handy_picker');

            // Reset form
            this.loginForm = { code: '', password: '' };
            this.loginError = '';

            // Show login screen
            this.currentScreen = 'login';
            this.showNotification('セッションが切れました。再ログインしてください。', 'error');
        },

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
                    api.token = response.result.data.token;
                    this.picker = response.result.data.picker;
                    this.isAuthenticated = true;

                    // Save to localStorage
                    localStorage.setItem('handy_token', api.token);
                    localStorage.setItem('handy_picker', JSON.stringify(this.picker));

                    // Move to warehouse selection
                    this.currentScreen = 'warehouse';
                    await this.loadWarehouses();

                    this.showNotification('ログインしました', 'success');
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

        async logout() {
            try {
                await api.post('/auth/logout');
            } catch (e) {
                // Ignore logout errors
            }

            // Clear local state
            api.token = null;
            this.picker = null;
            this.isAuthenticated = false;
            this.loginForm = { code: '', password: '' };
            this.selectedWarehouse = null;
            this.products = [];
            this.history = [];

            // Clear localStorage
            localStorage.removeItem('handy_token');
            localStorage.removeItem('handy_picker');

            this.currentScreen = 'login';
            this.showNotification('ログアウトしました', 'info');
        },

        // ==================== Warehouses ====================

        async loadWarehouses() {
            this.isLoading = true;
            this.loadingMessage = '倉庫一覧を取得中...';

            try {
                console.log('loadWarehouses: token =', api.token ? 'set' : 'not set');
                const response = await api.get('/master/warehouses');
                console.log('loadWarehouses response:', response);
                if (response.is_success && response.result?.data) {
                    this.warehouses = response.result.data;
                }
            } catch (error) {
                console.error('loadWarehouses error:', error);
                this.showNotification('倉庫一覧の取得に失敗しました: ' + error.message, 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        async selectWarehouse(warehouse) {
            this.selectedWarehouse = warehouse;
            this.currentScreen = 'list';
            this.searchQuery = '';
            await this.searchProducts();
        },

        // ==================== Products ====================

        // Infinite scroll computed properties
        get products() {
            return this.allProducts.slice(0, this.displayCount);
        },

        get totalProducts() {
            return this.allProducts.length;
        },

        get hasMore() {
            return this.displayCount < this.allProducts.length;
        },

        loadMore() {
            if (this.hasMore) {
                this.displayCount += this.perPage;
            }
        },

        // Handle scroll event for infinite scroll
        handleScroll(event) {
            const el = event.target;
            const threshold = 100; // pixels from bottom
            if (el.scrollHeight - el.scrollTop - el.clientHeight < threshold) {
                this.loadMore();
            }
        },

        async searchProducts() {
            if (!this.selectedWarehouse) return;

            this.isLoading = true;
            this.loadingMessage = '商品を検索中...';
            this.displayCount = this.perPage;  // Reset display count on new search

            try {
                let endpoint = `/incoming/schedules?warehouse_id=${this.selectedWarehouse.id}`;
                if (this.searchQuery) {
                    endpoint += `&search=${encodeURIComponent(this.searchQuery)}`;
                }

                const response = await api.get(endpoint);
                if (response.is_success && response.result?.data) {
                    this.allProducts = response.result.data;
                }

                // Load working schedule IDs
                await this.loadWorkingScheduleIds();
            } catch (error) {
                this.showNotification('商品検索に失敗しました', 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        // Load schedule IDs that are currently being worked on
        async loadWorkingScheduleIds() {
            if (!this.selectedWarehouse || !this.picker) {
                this.workingScheduleIds = [];
                return;
            }

            try {
                const response = await api.get(
                    `/incoming/work-items?warehouse_id=${this.selectedWarehouse.id}&status=WORKING&limit=100`
                );
                if (response.is_success && response.result?.data) {
                    this.workingScheduleIds = response.result.data.map(w => Number(w.incoming_schedule_id));
                }
            } catch (e) {
                console.error('Failed to load working schedule IDs:', e);
                this.workingScheduleIds = [];
            }
        },

        // Check if a product has any working schedules
        isProductWorking(item) {
            if (!item.schedules || this.workingScheduleIds.length === 0) return false;
            return item.schedules.some(s => this.workingScheduleIds.includes(Number(s.id)));
        },

        async selectProduct(item) {
            this.currentItem = item;
            this.editingWorkItem = null;

            // Set up all schedules for this item
            this.schedulesToProcess = item.schedules || [];
            this.currentScheduleIndex = 0;
            this.selectedScheduleIndex = 0;

            // Go to process screen (shows list of schedules)
            this.currentScreen = 'process';
        },

        // Move schedule selection with keyboard (up/down arrows, tab)
        moveScheduleSelection(direction) {
            const maxIndex = this.schedulesToProcess.length - 1;
            let newIndex = this.selectedScheduleIndex + direction;

            // Wrap around
            if (newIndex < 0) newIndex = maxIndex;
            if (newIndex > maxIndex) newIndex = 0;

            this.selectedScheduleIndex = newIndex;

            // Scroll selected item into view
            this.$nextTick(() => {
                const list = this.$refs.scheduleList;
                if (list) {
                    const selectedEl = list.querySelector(`[data-index="${newIndex}"]`);
                    if (selectedEl) {
                        selectedEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                }
            });
        },

        // Get current schedule being processed
        get currentSchedule() {
            return this.schedulesToProcess[this.currentScheduleIndex] || null;
        },

        // Get step display text (1/3, 2/3, etc.)
        get stepDisplay() {
            if (this.schedulesToProcess.length <= 1) return '';
            return `${this.currentScheduleIndex + 1}/${this.schedulesToProcess.length}`;
        },

        // Check if this is the last schedule
        get isLastSchedule() {
            return this.currentScheduleIndex >= this.schedulesToProcess.length - 1;
        },

        // Format date as MM/DD
        formatDateMMDD(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${month}/${day}`;
        },

        // Get row class based on schedule status
        getScheduleRowClass(schedule) {
            if (this.isScheduleCompleted(schedule)) {
                return 'bg-green-50';
            }
            if (this.isScheduleWorking(schedule)) {
                return 'bg-amber-50';
            }
            return 'bg-white';
        },

        // Check if schedule is completed
        isScheduleCompleted(schedule) {
            return schedule.status === 'COMPLETED';
        },

        // Check if schedule is currently being worked on
        isScheduleWorking(schedule) {
            return this.workingScheduleIds.includes(Number(schedule.id));
        },

        // Get received quantity for a schedule
        getReceivedQuantity(schedule) {
            return schedule.received_quantity || 0;
        },

        // Select a schedule and go to input screen
        async selectScheduleForInput(index) {
            this.currentScheduleIndex = index;
            const schedule = this.currentSchedule;

            if (!schedule) {
                this.showNotification('スケジュールがありません', 'error');
                return;
            }

            // Reset form for this schedule
            this.inputForm = {
                schedule_id: schedule.id,
                qty: schedule.remaining_quantity || 0,
                location_search: '',
                location_id: null,
                expiration_date: '',
            };

            // Start work for this schedule
            await this.startWork();
        },

        async startWork() {
            if (!this.inputForm.schedule_id || !this.picker) {
                console.error('startWork: Missing data', {
                    schedule_id: this.inputForm.schedule_id,
                    picker: this.picker,
                    currentItem: this.currentItem
                });
                this.showNotification('スケジュールIDまたはピッカー情報がありません', 'error');
                return;
            }

            this.isLoading = true;
            this.loadingMessage = '作業を開始中...';

            try {
                const requestData = {
                    incoming_schedule_id: this.inputForm.schedule_id,
                    picker_id: this.picker.id,
                    warehouse_id: this.selectedWarehouse.id,
                };
                console.log('startWork request:', requestData);

                const response = await api.post('/incoming/work-items', requestData);

                if (response.is_success && response.result?.data) {
                    this.setWorkItemData(response.result.data);
                    this.currentScreen = 'input';
                } else {
                    this.showNotification(response.message || '作業開始に失敗しました', 'error');
                }
            } catch (error) {
                console.error('startWork error:', error.message);

                // Check if already working - try to resume existing work
                if (error.message && error.message.includes('既に作業中')) {
                    console.log('Schedule already has active work, trying to resume...');
                    await this.resumeExistingWork();
                } else {
                    this.showNotification(error.message || '作業開始に失敗しました', 'error');
                }
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        // Helper to set work item data in form
        setWorkItemData(workItem) {
            this.editingWorkItem = workItem;

            // Set default values from work item
            this.inputForm.qty = workItem.work_quantity || this.currentItem?.total_remaining_quantity || 0;
            this.inputForm.expiration_date = workItem.work_expiration_date || '';

            // Set location
            if (workItem.location) {
                this.inputForm.location_id = workItem.location.id;
                this.inputForm.location_search = workItem.location.display_name;
            }
        },

        // Resume existing work item for the current schedule
        async resumeExistingWork() {
            try {
                this.loadingMessage = '既存の作業を取得中...';

                // Fetch existing work items for this warehouse/picker
                const response = await api.get(
                    `/incoming/work-items?warehouse_id=${this.selectedWarehouse.id}&picker_id=${this.picker.id}&status=WORKING&limit=50`
                );

                if (response.is_success && response.result?.data) {
                    // Find work item for current schedule (use Number() for type-safe comparison)
                    const scheduleId = Number(this.inputForm.schedule_id);
                    const existingWork = response.result.data.find(
                        w => Number(w.incoming_schedule_id) === scheduleId
                    );

                    if (existingWork) {
                        console.log('Found existing work item:', existingWork.id);
                        this.setWorkItemData(existingWork);
                        this.currentScreen = 'input';
                        return;
                    }
                }

                // If not found, show error
                this.showNotification('既存の作業データが見つかりません', 'error');
            } catch (e) {
                console.error('resumeExistingWork error:', e);
                this.showNotification('作業データの取得に失敗しました', 'error');
            }
        },

        onScheduleChange() {
            // Update remaining quantity for selected schedule
            const schedule = this.currentItem.schedules.find(s => s.id === this.inputForm.schedule_id);
            if (schedule) {
                this.inputForm.qty = schedule.remaining_quantity;
            }
        },

        // ==================== Location Search ====================

        async searchLocations() {
            if (!this.selectedWarehouse || this.inputForm.location_search.length < 1) {
                this.filteredLocations = [];
                return;
            }

            try {
                const response = await api.get(
                    `/incoming/locations?warehouse_id=${this.selectedWarehouse.id}&search=${encodeURIComponent(this.inputForm.location_search)}&limit=10`
                );
                if (response.is_success && response.result?.data) {
                    this.filteredLocations = response.result.data;
                    this.showLocationDropdown = true;
                }
            } catch (error) {
                // Silent fail
            }
        },

        selectLocation(location) {
            this.inputForm.location_id = location.id;
            this.inputForm.location_search = location.display_name;
            this.showLocationDropdown = false;
            this.filteredLocations = [];
        },

        // ==================== Quantity Adjustment ====================

        adjustQty(amount) {
            let current = parseInt(this.inputForm.qty) || 0;
            let next = current + amount;
            if (next < 0) next = 0;
            this.inputForm.qty = next;
        },

        get canSubmit() {
            return this.inputForm.qty > 0 && this.editingWorkItem;
        },

        // ==================== Submit ====================

        async submitReceiving() {
            if (!this.canSubmit) return;

            this.isLoading = true;
            this.loadingMessage = '入庫処理中...';

            try {
                // First update work item
                const updateData = {
                    work_quantity: this.inputForm.qty,
                };
                if (this.inputForm.location_id) {
                    updateData.location_id = this.inputForm.location_id;
                }
                if (this.inputForm.expiration_date) {
                    updateData.work_expiration_date = this.inputForm.expiration_date;
                }

                await api.put(`/incoming/work-items/${this.editingWorkItem.id}`, updateData);

                // Then complete work
                const response = await api.post(`/incoming/work-items/${this.editingWorkItem.id}/complete`);

                if (response.is_success) {
                    // Mark current schedule as completed locally
                    if (this.currentSchedule) {
                        this.currentSchedule.status = 'COMPLETED';
                    }

                    // Go back to process screen (schedule list)
                    this.editingWorkItem = null;
                    this.currentScreen = 'process';

                    // Refresh working status
                    await this.loadWorkingScheduleIds();

                    this.showNotification('入庫が完了しました', 'success');
                } else {
                    this.showNotification(response.message || '入庫処理に失敗しました', 'error');
                }
            } catch (error) {
                this.showNotification(error.message || '入庫処理に失敗しました', 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        finishProcess() {
            this.searchQuery = '';
            this.currentItem = null;
            this.editingWorkItem = null;
            this.currentScreen = 'list';
            this.searchProducts();
        },

        // ==================== History ====================

        async loadHistory() {
            if (!this.selectedWarehouse || !this.picker) return;

            this.isLoading = true;
            this.loadingMessage = '履歴を取得中...';

            try {
                const today = new Date().toISOString().split('T')[0];
                const response = await api.get(
                    `/incoming/work-items?warehouse_id=${this.selectedWarehouse.id}&picker_id=${this.picker.id}&status=all&from_date=${today}&limit=50`
                );
                if (response.is_success && response.result?.data) {
                    this.history = response.result.data;
                }
            } catch (error) {
                this.showNotification('履歴の取得に失敗しました', 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        async editHistory(hist) {
            if (hist.status !== 'WORKING') return;

            this.editingWorkItem = hist;
            this.currentItem = {
                item_id: hist.schedule?.item_id,
                item_code: hist.schedule?.item_code,
                item_name: hist.schedule?.item_name,
                total_remaining_quantity: hist.schedule?.remaining_quantity,
                schedules: [hist.schedule],
            };

            this.inputForm = {
                schedule_id: hist.incoming_schedule_id,
                qty: hist.work_quantity,
                location_search: hist.location?.display_name || '',
                location_id: hist.location_id,
                expiration_date: hist.work_expiration_date || '',
            };

            this.currentScreen = 'process';
        },

        formatTime(isoString) {
            if (!isoString) return '';
            const date = new Date(isoString);
            return date.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
        },

        // ==================== Notification ====================

        showNotification(message, type = 'info') {
            this.notification = { show: true, message, type };
            setTimeout(() => {
                this.notification.show = false;
            }, 3000);
        },
    };
};

// Wait for Alpine to be ready, then start
document.addEventListener('alpine:init', () => {
    // Alpine is now ready
});

// Initialize Alpine if not already done
window.Alpine = Alpine;
Alpine.start();
