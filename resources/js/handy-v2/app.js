import Alpine from 'alpinejs';
import intersect from '@alpinejs/intersect';
import apiClient from './services/api-client';
import { createAuthStore } from './stores/auth';
import { createWarehouseStore } from './stores/warehouse';
import { createNotificationStore } from './stores/notification';
import { createIncomingStore } from './stores/incoming';
import { createPickingStore } from './stores/picking';
import { createProxyShipmentStore } from './stores/proxy-shipment';
import { createBarcodeHandler } from './components/shared/barcode-input';
import { SCREENS, TABS } from './utils/constants';

// Initialize API client from server-injected config
const config = window.HANDY_V2_CONFIG || {};
apiClient.init({
    baseUrl: config.baseUrl || '/api',
    apiKey: config.apiKey || '',
});

// Main application component
window.handyV2App = function () {
    return {
        // Stores
        auth: createAuthStore(),
        warehouse: createWarehouseStore(),
        notification: createNotificationStore(),
        incoming: createIncomingStore(),
        picking: createPickingStore(),
        proxyShipment: createProxyShipmentStore(),

        // Barcode handler
        _barcodeHandler: null,

        // UI state
        currentScreen: SCREENS.LOGIN,
        currentTab: TABS.INCOMING,
        isLoading: false,
        showWarehouseModal: false,

        // Search debounce timer
        _searchTimer: null,

        // Constants exposed to template
        SCREENS,
        TABS,

        async init() {
            // Set up auth error handler
            apiClient.onAuthError = () => {
                this.auth.clearSession();
                this.currentScreen = SCREENS.LOGIN;
                this.notification.error('セッションが切れました。再ログインしてください。');
            };

            // Set up barcode handler
            this._barcodeHandler = createBarcodeHandler((barcode) => {
                this.onBarcodeScan(barcode);
            });

            // Restore session
            this.auth.init();
            this.warehouse.init();

            if (this.auth.token) {
                this.isLoading = true;
                try {
                    const valid = await this.auth.checkAuth();
                    if (valid) {
                        if (this.warehouse.selected) {
                            this.currentScreen = SCREENS.HOME;
                        } else {
                            this.currentScreen = SCREENS.HOME;
                            this.showWarehouseModal = true;
                            await this.warehouse.loadWarehouses();
                        }
                    } else {
                        this.currentScreen = SCREENS.LOGIN;
                    }
                } catch {
                    this.currentScreen = SCREENS.LOGIN;
                } finally {
                    this.isLoading = false;
                }
            }
        },

        // === Login ===
        loginForm: { code: '', password: '' },
        loginError: '',

        async login() {
            this.loginError = '';
            this.isLoading = true;
            try {
                const success = await this.auth.login(
                    this.loginForm.code,
                    this.loginForm.password
                );
                if (success) {
                    this.loginForm = { code: '', password: '' };
                    await this.warehouse.loadWarehouses();
                    if (this.warehouse.selected) {
                        this.currentScreen = SCREENS.HOME;
                    } else {
                        this.currentScreen = SCREENS.HOME;
                        this.showWarehouseModal = true;
                    }
                } else {
                    this.loginError = 'ログインに失敗しました';
                }
            } catch (e) {
                this.loginError = e.message || 'ログインに失敗しました';
            } finally {
                this.isLoading = false;
            }
        },

        // === Warehouse Selection ===
        selectWarehouse(warehouse) {
            this.warehouse.select(warehouse);
            this.showWarehouseModal = false;
            this.notification.success(`${warehouse.name} を選択しました`);
        },

        openWarehouseModal() {
            this.warehouse.loadWarehouses();
            this.showWarehouseModal = true;
        },

        // === Navigation ===
        switchTab(tab) {
            this.currentTab = tab;
            switch (tab) {
                case TABS.INCOMING:
                    this.currentScreen = SCREENS.INCOMING_LIST;
                    this.loadIncomingSchedules();
                    break;
                case TABS.PICKING:
                    this.currentScreen = SCREENS.PICKING_TASKS;
                    this.loadPickingTasks();
                    break;
                case TABS.PROXY_SHIPMENT:
                    this.currentScreen = SCREENS.PROXY_SHIPMENT_LIST;
                    this.loadProxyShipments();
                    break;
                case TABS.SETTINGS:
                    this.currentScreen = SCREENS.SETTINGS;
                    break;
            }
        },

        // === Incoming: Schedule List ===
        async loadIncomingSchedules() {
            if (!this.warehouse.selectedId) return;
            this.isLoading = true;
            try {
                await this.incoming.loadSchedules(this.warehouse.selectedId);
            } catch (e) {
                this.notification.error(e.message || '入荷予定の取得に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        onIncomingSearch(query) {
            this.incoming.searchQuery = query;
            if (this._searchTimer) clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => {
                this.loadIncomingSchedules();
            }, 300);
        },

        async loadMoreSchedules() {
            if (!this.warehouse.selectedId) return;
            try {
                await this.incoming.loadMore(this.warehouse.selectedId);
            } catch (e) {
                this.notification.error(e.message || '追加読み込みに失敗しました');
            }
        },

        // === Incoming: Start Work ===
        async startIncomingWork(scheduleItem, scheduleId) {
            if (!this.warehouse.selectedId || !this.auth.picker) return;
            this.isLoading = true;
            try {
                const success = await this.incoming.startWork(
                    this.warehouse.selectedId,
                    scheduleItem,
                    scheduleId,
                    this.auth.picker.id
                );
                if (success) {
                    this.currentScreen = SCREENS.INCOMING_WORK;
                } else {
                    this.notification.error('作業の開始に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '作業の開始に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        // === Incoming: Work Form ===
        async onLocationSearch(query) {
            this.incoming.locationSearch = query;
            if (!this.warehouse.selectedId) return;
            if (this._searchTimer) clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(async () => {
                try {
                    await this.incoming.searchLocations(this.warehouse.selectedId);
                    this.incoming.showLocationDropdown = true;
                } catch (e) {
                    // silently fail
                }
            }, 200);
        },

        async completeIncomingWork() {
            this.isLoading = true;
            try {
                const success = await this.incoming.completeWork();
                if (success) {
                    this.currentScreen = SCREENS.INCOMING_RESULT;
                    this.notification.success('入庫を確定しました');
                } else {
                    this.notification.error('確定に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '確定に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        // === Incoming: Result ===
        backToIncomingList() {
            this.incoming.reset();
            this.currentScreen = SCREENS.INCOMING_LIST;
            this.loadIncomingSchedules();
        },

        // === Incoming: History ===
        async showIncomingHistory() {
            this.isLoading = true;
            try {
                await this.incoming.loadHistory(this.warehouse.selectedId);
                this.currentScreen = SCREENS.INCOMING_HISTORY;
            } catch (e) {
                this.notification.error(e.message || '履歴の取得に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        async editHistoryItem(workItem) {
            this.incoming.editWorkItem(workItem, {
                item_name: workItem.schedule?.item_name,
                item_code: workItem.schedule?.item_code,
                jan_codes: workItem.schedule?.jan_codes,
                images: workItem.schedule?.images || [],
            });
            this.currentScreen = SCREENS.INCOMING_WORK;
        },

        confirmCancelWork(id) {
            this.incoming.cancelTargetId = id;
            this.incoming.showCancelConfirm = true;
        },

        async executeCancelWork() {
            if (!this.incoming.cancelTargetId) return;
            this.isLoading = true;
            try {
                const success = await this.incoming.cancelWork(this.incoming.cancelTargetId);
                if (success) {
                    this.notification.success('キャンセルしました');
                }
            } catch (e) {
                this.notification.error(e.message || 'キャンセルに失敗しました');
            } finally {
                this.incoming.showCancelConfirm = false;
                this.incoming.cancelTargetId = null;
                this.isLoading = false;
            }
        },

        backFromHistory() {
            this.currentScreen = SCREENS.INCOMING_LIST;
        },

        backFromIncomingWork() {
            this.incoming.reset();
            this.currentScreen = SCREENS.INCOMING_LIST;
        },

        // === Picking: Task List ===
        async loadPickingTasks() {
            if (!this.warehouse.selectedId) return;
            this.isLoading = true;
            try {
                await this.picking.loadTasks(this.warehouse.selectedId, this.auth.picker?.id);
            } catch (e) {
                this.notification.error(e.message || 'タスクの取得に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        async startPickingTask(task) {
            this.isLoading = true;
            try {
                const success = await this.picking.startTask(task);
                if (success) {
                    this.currentScreen = SCREENS.PICKING_ITEM;
                } else {
                    this.notification.error('タスクの開始に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || 'タスクの開始に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        // === Picking: Item ===
        onBarcodeScan(barcode) {
            if (this.currentScreen === SCREENS.PICKING_ITEM) {
                this.picking.checkBarcode(barcode);
            }
        },

        handleBarcodeKeyDown(event) {
            if (this._barcodeHandler) {
                this._barcodeHandler.handleKeyDown(event);
            }
        },

        async submitPickingItem() {
            this.isLoading = true;
            try {
                const success = await this.picking.submitItem();
                if (success) {
                    const hasNext = this.picking.moveToNext();
                    if (!hasNext) {
                        // All items picked, go to complete screen
                        this.currentScreen = SCREENS.PICKING_COMPLETE;
                    }
                } else {
                    this.notification.error('更新に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '更新に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        async markPickingShortage() {
            this.isLoading = true;
            try {
                const success = await this.picking.markShortage();
                if (success) {
                    this.notification.warning('欠品として登録しました');
                    const hasNext = this.picking.moveToNext();
                    if (!hasNext) {
                        this.currentScreen = SCREENS.PICKING_COMPLETE;
                    }
                }
            } catch (e) {
                this.notification.error(e.message || '欠品登録に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        skipPickingItem() {
            const hasNext = this.picking.skipItem();
            if (!hasNext) {
                this.currentScreen = SCREENS.PICKING_COMPLETE;
            }
        },

        backFromPickingItem() {
            this.picking.reset();
            this.currentScreen = SCREENS.PICKING_TASKS;
        },

        // === Picking: Complete ===
        async completePickingTask() {
            this.isLoading = true;
            try {
                const success = await this.picking.completeTask();
                if (success) {
                    this.currentScreen = SCREENS.PICKING_RESULT;
                    this.notification.success('タスクを完了しました');
                } else {
                    this.notification.error('完了に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '完了に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        // === Picking: Result ===
        backToPickingTasks() {
            this.picking.reset();
            this.currentScreen = SCREENS.PICKING_TASKS;
            this.loadPickingTasks();
        },

        // === Proxy Shipment: List ===
        async loadProxyShipments() {
            if (!this.warehouse.selectedId) return;
            this.isLoading = true;
            try {
                await this.proxyShipment.loadAllocations(this.warehouse.selectedId);
            } catch (e) {
                this.notification.error(e.message || '横持ち出荷の取得に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        async openProxyShipmentItem(alloc) {
            if (!this.warehouse.selectedId) return;
            this.isLoading = true;
            try {
                const loaded = await this.proxyShipment.loadDetail(
                    alloc.allocation_id,
                    this.warehouse.selectedId,
                );
                if (loaded) {
                    // Start the allocation if RESERVED
                    if (alloc.status === 'RESERVED') {
                        await this.proxyShipment.startAllocation(
                            alloc.allocation_id,
                            this.warehouse.selectedId,
                        );
                    }
                    this.currentScreen = SCREENS.PROXY_SHIPMENT_ITEM;
                } else {
                    this.notification.error('詳細の取得に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '横持ち出荷の開始に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        async updateProxyShipment() {
            if (!this.proxyShipment.currentAllocation || !this.warehouse.selectedId) return;
            this.isLoading = true;
            try {
                const success = await this.proxyShipment.updateAllocation(
                    this.proxyShipment.currentAllocation.allocation_id,
                    this.warehouse.selectedId,
                    this.proxyShipment.pickedQty,
                );
                if (success) {
                    this.notification.success('ピック数を更新しました');
                } else {
                    this.notification.error('更新に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '更新に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        async completeProxyShipment() {
            if (!this.proxyShipment.currentAllocation || !this.warehouse.selectedId) return;
            this.isLoading = true;
            try {
                const success = await this.proxyShipment.completeAllocation(
                    this.proxyShipment.currentAllocation.allocation_id,
                    this.warehouse.selectedId,
                    this.proxyShipment.pickedQty,
                );
                if (success) {
                    this.currentScreen = SCREENS.PROXY_SHIPMENT_RESULT;
                    this.notification.success('横持ち出荷を完了しました');
                } else {
                    this.notification.error('完了に失敗しました');
                }
            } catch (e) {
                this.notification.error(e.message || '完了に失敗しました');
            } finally {
                this.isLoading = false;
            }
        },

        backFromProxyShipmentItem() {
            this.proxyShipment.reset();
            this.currentScreen = SCREENS.PROXY_SHIPMENT_LIST;
        },

        backToProxyShipmentList() {
            this.proxyShipment.reset();
            this.currentScreen = SCREENS.PROXY_SHIPMENT_LIST;
            this.loadProxyShipments();
        },

        // === Logout ===
        async logout() {
            this.isLoading = true;
            try {
                await this.auth.logout();
            } finally {
                this.isLoading = false;
                this.warehouse.clear();
                this.incoming.reset();
                this.picking.reset();
                this.proxyShipment.resetAll();
                this.currentScreen = SCREENS.LOGIN;
                this.currentTab = TABS.INCOMING;
            }
        },

        // === Helpers ===
        get pickerName() {
            return this.auth.picker?.name || '';
        },

        get pickerCode() {
            return this.auth.picker?.code || '';
        },

        get warehouseName() {
            return this.warehouse.selectedName;
        },
    };
};

// Start Alpine
Alpine.plugin(intersect);
window.Alpine = Alpine;
Alpine.start();
