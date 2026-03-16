// Screen names
export const SCREENS = {
    LOGIN: 'login',
    HOME: 'home',
    INCOMING_LIST: 'incoming-list',
    INCOMING_WORK: 'incoming-work',
    INCOMING_RESULT: 'incoming-result',
    INCOMING_HISTORY: 'incoming-history',
    PICKING_TASKS: 'picking-tasks',
    PICKING_ITEM: 'picking-item',
    PICKING_COMPLETE: 'picking-complete',
    PICKING_RESULT: 'picking-result',
    SETTINGS: 'settings',
};

// Tab names (side nav)
export const TABS = {
    INCOMING: 'incoming',
    PICKING: 'picking',
    SETTINGS: 'settings',
};

// Picking task status labels & colors
export const PICKING_STATUS = {
    PENDING: { label: '未着手', color: 'bg-gray-400' },
    PICKING_READY: { label: 'ピッキング待ち', color: 'bg-blue-500' },
    PICKING: { label: 'ピッキング中', color: 'bg-orange-500' },
    COMPLETED: { label: '完了', color: 'bg-green-500' },
    SHORTAGE: { label: '欠品', color: 'bg-red-500' },
};

// Storage keys
export const STORAGE_KEYS = {
    TOKEN: 'wms_v2_token',
    PICKER: 'wms_v2_picker',
    WAREHOUSE: 'wms_v2_warehouse',
};

// Pagination
export const PAGE_SIZE = 50;
