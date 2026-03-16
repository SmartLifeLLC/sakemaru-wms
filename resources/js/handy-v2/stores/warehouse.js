import { masterService } from '../services/master-service';
import { getItem, setItem, removeItem } from '../utils/storage';
import { STORAGE_KEYS } from '../utils/constants';

export function createWarehouseStore() {
    return {
        warehouses: [],
        selected: null,

        init() {
            this.selected = getItem(STORAGE_KEYS.WAREHOUSE);
        },

        async loadWarehouses() {
            const response = await masterService.getWarehouses();
            if (response.is_success && response.result?.data) {
                this.warehouses = response.result.data;
            }
        },

        select(warehouse) {
            this.selected = warehouse;
            setItem(STORAGE_KEYS.WAREHOUSE, warehouse);
        },

        clear() {
            this.selected = null;
            removeItem(STORAGE_KEYS.WAREHOUSE);
        },

        get selectedName() {
            return this.selected?.name || '';
        },

        get selectedId() {
            return this.selected?.id || null;
        },
    };
}
