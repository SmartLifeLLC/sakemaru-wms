import Alpine from 'alpinejs';

/**
 * Handy Outgoing App - BHT-M60 Handy Terminal Web Application
 *
 * Alpine.js based SPA for warehouse outgoing (picking/shipping) operations
 */

// API Client
const api = {
    baseUrl: window.HANDY_CONFIG?.baseUrl || '/api',
    apiKey: window.HANDY_CONFIG?.apiKey || '',
    token: null,
    onAuthError: null,

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
            console.error('API Error:', { url, method, status: response.status, response: json });
            const error = new Error(errorMsg);
            error.code = json.code;
            error.data = json.result?.data;
            throw error;
        }

        return json;
    },

    get(endpoint) { return this.request('GET', endpoint); },
    post(endpoint, data) { return this.request('POST', endpoint, data); },
};

// Main Application
window.handyOutgoingApp = function() {
    return {
        // Screen Management
        currentScreen: 'task-list', // task-list, picking, complete, result

        // Authentication
        picker: null,

        // Data
        selectedWarehouse: null,
        tasks: [],
        currentTask: null,
        currentItemIndex: 0,
        pickedQty: 0,
        pickedItems: [], // Track picked items with their quantities

        // Selection for keyboard navigation
        selectedTaskIndex: 0,

        // State
        isLoading: false,
        loadingMessage: '',

        // Notification
        notification: { show: false, message: '', type: 'info' },

        // Initialize
        async init() {
            const config = window.HANDY_CONFIG || {};
            const urlWarehouseId = config.warehouseId ? Number(config.warehouseId) : null;

            // Set up auth error handler
            api.onAuthError = () => this.handleAuthError();

            // Check for auth_key parameter or localStorage
            let token = config.authKey;
            if (!token) {
                token = localStorage.getItem('handy_token');
            }

            if (!token) {
                window.location.href = '/handy/login';
                return;
            }

            api.token = token;

            // Get picker info
            const savedPicker = localStorage.getItem('handy_picker');
            if (savedPicker) {
                this.picker = JSON.parse(savedPicker);
            } else {
                try {
                    const response = await api.get('/me');
                    if (response.is_success && response.result?.data) {
                        this.picker = response.result.data;
                        localStorage.setItem('handy_picker', JSON.stringify(this.picker));
                    }
                } catch (e) {
                    this.handleAuthError();
                    return;
                }
            }

            // Set warehouse from URL parameter
            if (urlWarehouseId) {
                this.selectedWarehouse = { id: urlWarehouseId };
                // Get warehouse name
                try {
                    const response = await api.get('/master/warehouses');
                    if (response.is_success && response.result?.data) {
                        const warehouse = response.result.data.find(w => Number(w.id) === urlWarehouseId);
                        if (warehouse) {
                            this.selectedWarehouse = warehouse;
                        }
                    }
                } catch (e) {
                    console.error('Failed to load warehouse info:', e);
                }

                // Load tasks
                await this.loadTasks();
            } else {
                // No warehouse selected, go to home
                window.location.href = '/handy/home?auth_key=' + encodeURIComponent(api.token);
            }
        },

        // Handle authentication errors
        handleAuthError() {
            localStorage.removeItem('handy_token');
            localStorage.removeItem('handy_picker');
            window.location.href = '/handy/login';
        },

        // Header Title
        getHeaderTitle() {
            if (this.currentScreen === 'picking' && this.currentTask) {
                return this.currentTask.course.name;
            }
            if (this.currentScreen === 'complete') {
                return 'ピッキング確認';
            }
            if (this.currentScreen === 'result') {
                return '完了';
            }
            return `${this.selectedWarehouse?.name || ''} 出荷`;
        },

        // Navigation
        canGoBack() {
            return this.currentScreen !== 'task-list';
        },

        goBack() {
            if (this.currentScreen === 'picking') {
                this.currentScreen = 'task-list';
                this.currentTask = null;
                this.currentItemIndex = 0;
                this.pickedItems = [];
            } else if (this.currentScreen === 'complete') {
                this.currentScreen = 'picking';
            } else if (this.currentScreen === 'result') {
                this.currentScreen = 'task-list';
                this.loadTasks();
            }
        },

        goHome() {
            window.location.href = '/handy/home?auth_key=' + encodeURIComponent(api.token);
        },

        // Load Tasks
        async loadTasks() {
            if (!this.selectedWarehouse) return;

            this.isLoading = true;
            this.loadingMessage = 'タスクを取得中...';

            try {
                const response = await api.get(`/picking/tasks?warehouse_id=${this.selectedWarehouse.id}`);
                if (response.is_success && response.result?.data) {
                    this.tasks = response.result.data;
                    this.selectedTaskIndex = 0;
                }
            } catch (error) {
                this.showNotification('タスクの取得に失敗しました: ' + error.message, 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        refreshTasks() {
            this.loadTasks();
        },

        // Select Task
        async selectTask(task) {
            this.currentTask = task;
            this.currentItemIndex = 0;
            this.pickedItems = [];

            // Start the task
            this.isLoading = true;
            this.loadingMessage = 'タスクを開始中...';

            try {
                await api.post(`/picking/tasks/${task.wave.wms_picking_task_id}/start`);

                // Initialize picked items array
                this.pickedItems = task.picking_list.map(item => ({
                    ...item,
                    picked: false,
                    pickedQty: item.planned_qty, // Default to planned qty
                }));

                // Set initial picked qty
                if (this.currentItem) {
                    this.pickedQty = Number(this.currentItem.planned_qty);
                }

                this.currentScreen = 'picking';
            } catch (error) {
                // If already started (PICKING status), continue
                if (error.message && error.message.includes('PICKING')) {
                    this.pickedItems = task.picking_list.map(item => ({
                        ...item,
                        picked: false,
                        pickedQty: item.planned_qty,
                    }));
                    if (this.currentItem) {
                        this.pickedQty = Number(this.currentItem.planned_qty);
                    }
                    this.currentScreen = 'picking';
                } else {
                    this.showNotification('タスク開始に失敗しました: ' + error.message, 'error');
                }
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        // Current Item (getter)
        get currentItem() {
            if (!this.currentTask || !this.currentTask.picking_list) return null;
            return this.currentTask.picking_list[this.currentItemIndex] || null;
        },

        // Remaining Items (getter)
        get remainingItems() {
            if (!this.currentTask || !this.currentTask.picking_list) return [];
            return this.currentTask.picking_list.slice(this.currentItemIndex + 1);
        },

        // Progress Text
        getProgressText() {
            if (!this.currentTask) return '';
            const total = this.currentTask.picking_list.length;
            return `${this.currentItemIndex + 1} / ${total}`;
        },

        // Quantity Type Label
        getQtyTypeLabel(qtyType) {
            const labels = {
                'CASE': 'ケース',
                'PIECE': 'バラ',
                'CARTON': 'ボール',
            };
            return labels[qtyType] || qtyType;
        },

        // Adjust Quantity
        adjustQty(amount) {
            let next = (this.pickedQty || 0) + amount;
            if (next < 0) next = 0;
            this.pickedQty = next;
        },

        // Submit Picking for current item
        async submitPicking() {
            if (!this.currentItem) return;

            this.isLoading = true;
            this.loadingMessage = 'ピッキング登録中...';

            try {
                // Update item result
                await api.post(`/picking/tasks/${this.currentItem.wms_picking_item_result_id}/update`, {
                    picked_qty: this.pickedQty,
                });

                // Mark as picked in local state
                if (this.pickedItems[this.currentItemIndex]) {
                    this.pickedItems[this.currentItemIndex].picked = true;
                    this.pickedItems[this.currentItemIndex].pickedQty = this.pickedQty;
                }

                // Move to next item or complete screen
                if (this.currentItemIndex < this.currentTask.picking_list.length - 1) {
                    this.currentItemIndex++;
                    // Set picked qty to planned qty for next item
                    if (this.currentItem) {
                        this.pickedQty = Number(this.currentItem.planned_qty);
                    }
                } else {
                    // All items done, go to complete screen
                    this.currentScreen = 'complete';
                }

                this.showNotification('登録しました', 'success');
            } catch (error) {
                this.showNotification('登録に失敗しました: ' + error.message, 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        // Computed: Has Shortage
        get hasShortage() {
            return this.pickedItems.some(item =>
                Number(item.pickedQty) < Number(item.planned_qty)
            );
        },

        // Computed: Completed Count
        get completedCount() {
            return this.pickedItems.filter(item => item.picked).length;
        },

        // Computed: Shortage Count
        get shortageCount() {
            return this.pickedItems.filter(item =>
                item.picked && Number(item.pickedQty) < Number(item.planned_qty)
            ).length;
        },

        // Complete Task
        async completeTask() {
            if (!this.currentTask) return;

            this.isLoading = true;
            this.loadingMessage = 'タスクを完了中...';

            try {
                await api.post(`/picking/tasks/${this.currentTask.wave.wms_picking_task_id}/complete`);

                this.currentScreen = 'result';
                this.showNotification('出荷が完了しました', 'success');
            } catch (error) {
                this.showNotification('完了処理に失敗しました: ' + error.message, 'error');
            } finally {
                this.isLoading = false;
                this.loadingMessage = '';
            }
        },

        // Finish and go back to task list
        finishAndGoBack() {
            this.currentTask = null;
            this.currentItemIndex = 0;
            this.pickedItems = [];
            this.currentScreen = 'task-list';
            this.loadTasks();
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
