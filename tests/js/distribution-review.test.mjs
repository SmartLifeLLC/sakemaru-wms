import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

for (const failure of ['http-error', 'invalid-json', 'unavailable-store', 'row-limit']) {
    test(`initial row load ${failure} reports unavailable data and recovers on retry`, async () => {
        const { app } = component();
        app.activeSubTab = 'direct';
        app.fetchWithTimeout = async () => ({
            ok: failure === 'unavailable-store' || failure === 'invalid-json', status: 503,
            json: async () => {
                if (failure === 'invalid-json') throw new SyntaxError('Invalid JSON');
                return failure === 'row-limit'
                    ? { code: 'DISTRIBUTION_ROWS_LIMIT_EXCEEDED', message: '取得上限' }
                    : { result: { data: { persisted: false } } };
            },
        });
        await app.loadDistributionRowsFromServer();
        assert.match(app.serverRowsLoadError, /読み込めません/);
        assert.equal(app.serverRowsLoaded, false);
        assert.equal(app.serverRowsPersisted, false);
        assert.equal(app.distributionRowsLoading, false);
        app.fetchWithTimeout = async () => ({ ok: true, json: async () => ({ result: { data: {
            persisted: true, direct: [], revisions: { direct: 1 },
            pagination: { direct: { page: 1, last_page: 1, total: 0, per_page: 200 } },
        } } }) });
        await app.loadDistributionRowsFromServer();
        assert.equal(app.serverRowsLoadError, '');
        assert.equal(app.serverRowsLoaded, true);
        assert.equal(app.serverRowsPersisted, true);
        assert.equal(app.distributionRowsLoading, false);
    });
}

test('store row limit keeps retry blocked until a filtered snapshot succeeds', async () => {
    const { app } = component();
    app.activeSubTab = 'store-view';
    app.storeDistributionType = 'allocation';
    app.fetchWithTimeout = async () => ({ ok: false, status: 409,
        json: async () => ({ code: 'DISTRIBUTION_ROWS_LIMIT_EXCEEDED' }) });
    await app.loadDistributionRowsFromServer();
    assert.match(app.serverRowsLoadError, /検索条件を絞り込んで/);
    assert.equal(app.serverRowsPersisted, false);
    assert.equal(app.lastError, undefined);
    app.ensureStoreSlipSelectionState = () => { throw new Error('Output must remain blocked'); };
    app.openStoreSlipOutput();
    assert.equal(app.lastError, undefined);
    app.storeProductSearch = '100567';
    let release;
    app.fetchWithTimeout = async url => {
        assert.equal(new URLSearchParams(url.split('?')[1]).get('product_search'), '100567');
        await new Promise(resolve => { release = resolve; });
        return { ok: true, json: async () => ({ result: { data: {
            persisted: true, allocation: [], revisions: { allocation: 1 },
        } } }) };
    };
    const retry = app.loadDistributionRowsFromServer();
    assert.equal(app.distributionRowsLoading, true);
    assert.notEqual(app.serverRowsLoadError, '');
    release();
    await retry;
    assert.equal(app.serverRowsLoadError, '');
    assert.equal(app.serverRowsPersisted, true);
    assert.equal(app.distributionRowsLoading, false);
});

// Run the actual Alpine component, replacing only Blade's server bootstrap values.
const blade = readFileSync(process.env.DISTRIBUTION_REVIEW_SOURCE
    || new URL('../../resources/views/filament/pages/distribution-app.blade.php', import.meta.url), 'utf8');
const source = blade.slice(blade.indexOf('window.distributionApp = function'), blade.lastIndexOf('</script>'))
    .replace(/\{\{[^\n]+\}\}/g, 'null');

function component({ batch = null } = {}) {
    const context = vm.createContext({
        window: { location: { search: '', hash: '' }, setTimeout, clearTimeout },
        localStorage: { setItem() {}, getItem() { return null; } },
        URLSearchParams, setTimeout, clearTimeout,
        console: { warn() {}, error() {} },
    });
    vm.runInContext(source.replace('const BATCH = null;', 'const BATCH = '+JSON.stringify(batch)+';'), context);
    const app = context.window.distributionApp();
    app.$nextTick = callback => callback();
    app.$refs = {};
    app.showToast = message => { app.lastToast = message; };
    app.openModal = (title, message) => { app.lastError = message; };
    return { app, context };
}

const keys = destinations => Array.from(destinations, destination => destination.key);

test('Excel matrix follows destination mappings, preserves zero and distinguishes unresolved cells', () => {
    const { app } = component();
    const store = { column: 'I', status: 'matched', destination_code: 1 };
    const unresolved = { column: 'J', status: 'unresolved', destination_code: null };
    app.excelImport.preview = { columns: [
        { column: 'B', status: 'ignored' }, store, unresolved,
    ] };
    assert.deepEqual(Array.from(app.excelPreviewColumns(), column => column.column), ['I', 'J']);
    const row = { before: { alloc_01: 5 }, allocations: { '01': 0, '02': 7 } };
    assert.equal(app.excelPreviewQuantity(row, store), 0);
    assert.equal(app.excelPreviewQuantity(row, store, true), 5);
    assert.equal(app.excelPreviewQuantity(row, unresolved), null);
    store.destination_code = 2;
    assert.equal(app.excelPreviewQuantity(row, store), 7);
    assert.equal(app.excelPreviewQuantity(row, store, true), 0);
    store.destination_code = 4;
    assert.equal(app.excelPreviewQuantity(row, store), null);
});

test('Excel preview highlights a wish-only update and retains both previous quantities', () => {
    const { app } = component();
    const store = { status: 'matched', destination_code: 1 };
    const row = { allocations: { '01': 6 }, before: { alloc_01: 6, wish_01: 1 } };
    assert.equal(app.excelPreviewQuantity(row, store, true, 'wish'), 1);
    assert.equal(app.excelPreviewQuantity(row, store, true), 6);
    assert.equal(app.excelPreviewQuantityChanged(row, store), true);
    row.before.wish_01 = 6;
    assert.equal(app.excelPreviewQuantityChanged(row, store), false);
    assert.equal(app.excelPreviewQuantityChanged(row, { status: 'unresolved' }), false);
});

test('an Excel preview containing only skipped rows never submits an import', async () => {
    const { app } = component();
    app.excelImport.preview = { rows: [], errors: [], skipped: [{ reason_code: 'merged_store_quantity' }] };
    app.excelImport.acknowledged = true;
    let requests = 0;
    app.batchRequest = async () => { requests++; throw new Error('No import should be submitted'); };
    await app.commitExcel();
    assert.equal(requests, 0);
    assert.equal(app.excelImport.busy, false);
    assert.equal(app.excelImport.error, '');
});

test('restoring an Excel column to automatic matching removes the override and requires review', () => {
    const { app } = component();
    const column = { column: 'I' };
    app.excelImport.acknowledged = true;
    app.updateExcelPreviewMapping(column, '2');
    assert.equal(app.excelImport.mapping.I, '2');
    assert.equal(app.excelImport.needsPreview, true);
    assert.equal(app.excelImport.acknowledged, false);
    app.updateExcelPreviewMapping(column, '');
    assert.equal(Object.hasOwn(app.excelImport.mapping, 'I'), false);
});

test('the same CSV has independent identities across batches and stable identities within a batch', () => {
    const csv = '商品CD,本店_希望\n100567,3\n';
    const first = component({ batch: { id: 1, client_id: 6, warehouse_id: 91, shipping_date: '2026-11-01' } }).app;
    const second = component({ batch: { id: 2, client_id: 6, warehouse_id: 91, shipping_date: '2026-11-01' } }).app;
    const a = first.buildAllocationRowsFromCsv(csv, 'same.csv')[0];
    const b = second.buildAllocationRowsFromCsv(csv, 'same.csv')[0];
    const again = first.buildAllocationRowsFromCsv(csv, 'renamed.csv')[0];
    assert.notEqual(a.id, b.id);
    assert.notEqual(a.distributionBusinessKey, b.distributionBusinessKey);
    assert.equal(a.id, again.id);
    assert.equal(a.distributionBusinessKey, again.distributionBusinessKey);
    assert.equal(first.filterDuplicateCsvImportRows([again], [a], 'csv_import').rows.length, 0);
    assert.equal(second.filterDuplicateCsvImportRows([b], [], 'csv_import').rows.length, 1);
});

test('existing migrated CSV identities remain unchanged and prevent duplicate reimport', () => {
    const csv = '商品CD,計上日,本店_希望\n100567,2026-11-01,3\n';
    const legacy = component().app.buildAllocationRowsFromCsv(csv, 'same.csv')[0];
    const app = component({ batch: { id: 1, client_id: 6, warehouse_id: 91, is_legacy: true } }).app;
    const imported = app.buildAllocationRowsFromCsv(csv, 'same.csv')[0];
    assert.equal(app.getDistributionBusinessKey(legacy, 'allocation'), legacy.distributionBusinessKey);
    assert.equal(app.filterDuplicateCsvImportRows([imported], [legacy], 'csv_import').rows.length, 0);
});

test('a batch opens without rewriting order/delivery dates or automatically saving rows', async () => {
    const { app } = component({ batch: { id: 1, client_id: 6, warehouse_id: 91, shipping_date: '2026-11-01' } });
    app.rows = [app.makeAllocationRow({ id: 'row', orderDate: '2026-10-25', deliveryDate: '2026-10-27', alloc_01: 3 })];
    let saves = 0;
    app.saveData = () => saves++;
    await app.applyBulkAllocationDates({ silent: true });
    assert.equal(app.rows[0].orderDate, '2026-10-25');
    assert.equal(app.rows[0].deliveryDate, '2026-10-27');
    assert.equal(app.bulkInputDate, '2026-11-01');
    assert.equal(saves, 0);
    assert.equal(app.batchApiUrl('/api/distribution/rows?mode=allocation'), '/api/distribution/batches/1/rows?mode=allocation');
});

test('Excel rows retain explicitly supplied zero-quantity destination columns', () => {
    const { app } = component();
    const row = app.makeAllocationRow({ source: 'excel_import', alloc_01: 12, alloc_11: 0,
        visibleDestinationKeys: ['01', '11'], enteredDestinationKeys: ['01', '11'] });
    assert.deepEqual(keys(app.getAllocationDisplayDestinations([row])), ['01', '11']);
});

for (const mode of ['allocation', 'direct']) {
    test(`${mode}: a manual row retains store columns after the first quantity and after reload`, () => {
        const { app } = component();
        const row = mode === 'direct' ? app.makeDirectRow() : app.makeAllocationRow();
        const display = () => mode === 'direct'
            ? app.getDirectDisplayDestinations([row]) : app.getAllocationDisplayDestinations([row]);
        const before = keys(display());
        row.alloc_01 = 12;
        app.touchDirectRows();
        assert.deepEqual(keys(display()), before);
        assert.equal(app.calcAllocTotal(row), 12);
        assert.deepEqual(Array.from(app.getRowActiveDestinationKeys(row)), ['01']);
        const saved = JSON.parse(JSON.stringify(row));
        const { app: reloaded } = component();
        assert.deepEqual(keys(mode === 'direct'
            ? reloaded.getDirectDisplayDestinations([saved])
            : reloaded.getAllocationDisplayDestinations([saved])), before);
        row.alloc_02 = 5;
        assert.equal(app.calcAllocTotal(row), 17);
    });

    test(`${mode}: imported rows retain their restricted destination scope`, () => {
        const { app } = component();
        for (const source of ['csv_import', 'hq_transfer_request']) {
            const row = { source, visibleDestinationKeys: ['01'], enteredDestinationKeys: ['01'], alloc_01: 3 };
            app.touchDirectRows();
            assert.deepEqual(keys(mode === 'direct'
                ? app.getDirectDisplayDestinations([row])
                : app.getAllocationDisplayDestinations([row])), ['01']);
            assert.equal(app.calcAllocTotal(row), 3);
        }
    });
}

function deletionApp({ protectedIds = [], saveFails = false, reloadFails = false, count = 4, deletedIds = ['a'] } = {}) {
    const { app, context } = component();
    app.activeSubTab = 'direct';
    app.serverRowsPersisted = true;
    app.serverRowsLoaded = true;
    const ids = Array.from({ length: count }, (_, index) => index < 4 ? ['a', 'b', 'c', 'd'][index] : String(index));
    app.directRows = ids.map(id => app.makeDirectRow({ id, itemId: 1, productCode: '1', name: id, alloc_01: 1 }));
    app.serverLoadedRowIds.direct = ids;
    app.serverRowPagination.direct = { total: count, page: Math.ceil(count / 200), lastPage: Math.ceil(count / 200), perPage: 200 };
    let serverRows = JSON.parse(JSON.stringify(app.directRows));
    let putCount = 0;
    app.csrfHeaders = () => ({});
    context.fetch = async (url, options) => {
        assert.equal(options.method, 'PUT');
        putCount++;
        if (saveFails) return { ok: false, status: 409, json: async () => ({ code: 'DISTRIBUTION_REVISION_CONFLICT' }) };
        const body = JSON.parse(options.body);
        assert.equal(body.replace, false);
        assert.deepEqual(body.deleted_row_ids, deletedIds);
        serverRows = serverRows.filter(row => !body.deleted_row_ids.includes(row.id) || protectedIds.includes(row.id));
        return { ok: true, json: async () => ({ result: { data: {
            revision: 1, deleted_count: count - serverRows.length,
            delete_protected_row_ids: protectedIds, resync_required_row_ids: protectedIds,
        } } }) };
    };
    app.fetchWithTimeout = async (url, options = {}) => {
        if (options.method === 'PUT') return context.fetch(url, options);
        if (reloadFails) throw new Error('GET failed');
        const page = Number(new URL(url, 'https://wms.test').searchParams.get('page'));
        return { ok: true, json: async () => ({ result: { data: {
            persisted: true, direct: serverRows.slice((page - 1) * 200, page * 200), revisions: { direct: 1 },
            pagination: { direct: { total: serverRows.length, page, last_page: Math.ceil(serverRows.length / 200), per_page: 200 } },
        } } }) };
    };
    return { app, putCount: () => putCount };
}

test('deleting a row refreshes the server total from four to three', async () => {
    const { app } = deletionApp();
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.length, 3);
    assert.equal(app.getDirectTotalRowCount(), 3);
});

test('a protected delete retains four rows and reports protection rather than success', async () => {
    const { app } = deletionApp({ protectedIds: ['a'] });
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.length, 4);
    assert.equal(app.getDirectTotalRowCount(), 4);
    assert.match(app.lastError, /削除.*でき/);
});

test('a rejected save restores the removed row and preserves the conflict block', async () => {
    const { app } = deletionApp({ saveFails: true });
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.length, 4);
    assert.equal(app.getDirectTotalRowCount(), 4);
    assert.equal(app.serverSaveBlocked.direct, true);
});

test('a committed delete followed by failed GET never restores or re-saves deleted rows', async () => {
    const { app, putCount } = deletionApp({ reloadFails: true });
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.length, 3);
    assert.equal(app.serverSaveBlocked.direct, true);
    assert.equal(putCount(), 1);
    assert.match(app.lastError, /再読み込み/);
});

test('single-row delete delegates to the same guarded persistence path', async () => {
    const { app } = deletionApp();
    app.openConfirm = (message, callback) => { app.confirmCallback = callback; };
    app.removeDirectRow('a');
    await app.confirmCallback();
    assert.equal(app.getDirectTotalRowCount(), 3);
});

test('partial deletion keeps protected rows and uses the actual server count', async () => {
    const { app } = deletionApp({ protectedIds: ['a'], deletedIds: ['a', 'b'] });
    await app.deleteRowsAndPersist(app.directRows.slice(0, 2), true);
    assert.deepEqual(Array.from(app.directRows, row => row.id), ['a', 'c', 'd']);
    assert.equal(app.getDirectTotalRowCount(), 3);
    assert.match(app.lastError, /削除結果は1件/);
});

test('protection resync failure is not mistaken for a rejected PUT', async () => {
    const { app, putCount } = deletionApp({ protectedIds: ['a'], reloadFails: true });
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.serverSaveBlocked.direct, true);
    assert.equal(putCount(), 1);
    assert.match(app.lastError, /削除要求は保存/);
    assert.equal(app.filterApplying, false);
});

test('a failed later page exits resynchronization instead of retrying forever', async () => {
    const { app } = deletionApp({ count: 450 });
    const fetchPage = app.fetchWithTimeout;
    let getCount = 0;
    app.fetchWithTimeout = async (url, options) => {
        if (options?.method === 'PUT') return fetchPage(url, options);
        if (++getCount > 1) throw new Error('second page failed');
        return fetchPage(url);
    };
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(getCount, 2);
    assert.equal(app.serverSaveBlocked.direct, true);
    assert.equal(app.directRows.some(row => row.id === 'a'), false);
});

test('deletion refresh preserves all loaded pages beyond two hundred rows', async () => {
    const { app } = deletionApp({ count: 450 });
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.length, 449);
    assert.equal(app.getDirectTotalRowCount(), 449);
    assert.equal(app.serverRowPagination.direct.page, 3);
    assert.equal(app.directRows.at(-1).id, '449');
});

test('a local edit during a refresh is preserved and prevents further saves', async () => {
    const { app } = deletionApp();
    const fetchPage = app.fetchWithTimeout;
    app.fetchWithTimeout = async (url, options) => {
        if (options?.method === 'PUT') return fetchPage(url, options);
        app.directRows[0].alloc_01 = 9;
        app.touchDirectRows();
        return fetchPage(url);
    };
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows[0].alloc_01, 9);
    assert.equal(app.serverSaveBlocked.direct, true);
});

test('pending-save rejection leaves current data alone and does not issue a delete', async () => {
    const { app, putCount } = deletionApp();
    app.waitForDistributionSaves = async () => { throw new Error('pending save failed'); };
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.length, 4);
    assert.equal(putCount(), 0);
    assert.equal(app.filterApplying, false);
});

test('editor destinations respect master visibility and preserve nonstandard positive quantities', () => {
    const { app } = component();
    app.csvDestinations = [
        { key: '02', code: '02', name: '非表示店', isHidden: true },
        { key: '23', code: '23', name: '追加店舗', id: 23 },
        { key: '91', code: '91', name: '本部', id: 91 },
        { key: '93', code: '93', name: '業販', id: 93 },
    ];
    const row = app.makeDirectRow({ alloc_01: 3, alloc_93: 2 });
    const displayKeys = keys(app.getDirectDisplayDestinations([row]));
    assert.ok(!displayKeys.includes('02'));
    assert.ok(displayKeys.includes('23'));
    assert.ok(!displayKeys.includes('91'));
    assert.ok(displayKeys.includes('93'));
    assert.equal(app.calcDirectAllocTotal(row), 5);
});

for (const count of [14, 15, 16]) {
    test(`manual additions remain last and rendered with ${count} existing rows, including after reload`, () => {
        const { app } = component();
        app.rows = Array.from({ length: count }, (_, i) => app.makeAllocationRow({
            id: String(i), name: '商品', productCode: '1', orderDate: '2026-01-01',
        }));
        app.queueBulkAllocationAutoApply = () => {};
        app.addRow();
        app.rows.at(-1).name = '追加1';
        app.rows.at(-1).orderDate = '2026-10-25';
        app.addRow();
        app.rows.at(-1).name = '追加2';
        app.rows.at(-1).orderDate = '2026-10-26';
        const appendedIds = Array.from(app.rows.slice(-2), row => row.id);
        assert.deepEqual(Array.from(app.getRenderableVisibleRows().slice(-2), row => row.id), appendedIds);
        const saved = JSON.parse(JSON.stringify(app.prepareRowsForServerPersistence(app.getPersistableAllocationRows(), 'allocation')));
        const { app: reloaded } = component();
        reloaded.rows = saved.map(row => reloaded.normalizeAllocationRow(row));
        assert.deepEqual(Array.from(reloaded.getVisibleRows().slice(-2), row => row.id), appendedIds);
        assert.deepEqual(Array.from(reloaded.getVisibleRows().slice(-2), row => row.allocationAppendOrder), [1, 2]);
        reloaded.queueBulkAllocationAutoApply = () => {};
        reloaded.addRow();
        assert.equal(reloaded.getVisibleRows().at(-1).allocationAppendOrder, 3);
    });
}

test('an unknown PUT outcome blocks writes without restoring deleted rows', async () => {
    const { app } = deletionApp();
    app.fetchWithTimeout = async () => { throw new Error('response lost'); };
    await app.deleteRowsAndPersist([app.directRows[0]], true);
    assert.equal(app.directRows.some(row => row.id === 'a'), false);
    assert.equal(app.serverSaveBlocked.direct, true);
    assert.match(app.lastError, /保存結果を確認できません/);
});

test('direct quantities accept zero order fields and reject only positive mismatches', () => {
    const { app } = component();
    const row = app.makeDirectRow({ productCode: '100', unitsPerCase: 12, alloc_01: 3 });
    assert.doesNotThrow(() => app.validateDirectPrintQuantities([row]));
    row.poCase = 1;
    assert.throws(() => app.validateDirectPrintQuantities([row]), /12.*3/);
    row.alloc_01 = 12;
    assert.doesNotThrow(() => app.validateDirectPrintQuantities([row]));
});

test('release of print interaction keeps session alive and does not mark printed', () => {
    const { app } = component();
    app.directPrintSession = { id: 'session' };
    app.directRequestPrintInProgress = true;
    app.directRequestPrintLockedRowIds = ['a'];
    app.directRows = [app.makeDirectRow({ id: 'a' })];
    app.releaseDirectRequestPrintLock();
    assert.equal(app.directRequestPrintInProgress, false);
    assert.equal(app.directPrintSession.id, 'session');
    assert.equal(app.directRows[0].printed, false);
});

test('HQ CSV uses exact applied matches independent of selections and retained rows', () => {
    const { app } = component();
    app.rows = [
        app.makeAllocationRow({ id: 'match', productCode: '1', name: '一致', orderDate: '2026-10-25' }),
        app.makeAllocationRow({ id: 'retained', productCode: '2', name: '対象外', orderDate: '2026-10-25', checked: true }),
    ];
    app.allocationKeywordSearch = '一致';
    app.allocationFilterRetainedRowIds = ['retained'];
    app._exportCSVRows = rows => { app.exportedIds = Array.from(rows, row => row.id); };
    app.downloadCSV();
    assert.deepEqual(app.exportedIds, ['match']);
});

function printingApp(states = ['succeeded']) {
    const { app, context } = component();
    const popup = { closed: false, document: { body: {} }, close() { this.closed = true; } };
    context.window.open = () => popup;
    context.window.setTimeout = callback => { callback(); };
    app.csrfHeaders = () => ({});
    app.rows = [app.makeAllocationRow({ id: 'a', alloc_01: 3 })];
    let calls = 0;
    app.fetchWithTimeout = async (url, opts) => {
        app.requestBody = JSON.parse(opts.body);
        const state = states[Math.min(calls++, states.length - 1)];
        return { ok: true, json: async () => ({ result: { data: {
            queues: [{ row_ids: ['a'], state, message: '生成失敗' }], documents: state === 'succeeded' ? [{ kind: 'transfer' }] : [],
        } } }) };
    };
    app._renderSlipsForDests = () => { app.rendered = true; return true; };
    return { app, context, popup, calls: () => calls };
}

test('acceptance is pending and polling waits for success before printing', async () => {
    const { app, calls } = printingApp(['pending', 'succeeded']);
    app.markRowsAsWarehouseTransferCreated(app.rows, { row_ids: ['a'], queues: [{ queue_id: 1 }] });
    assert.equal(app.rows[0].warehouseTransferState, 'pending');
    assert.equal(app.rows[0].printed, false);
    await app._printSlipsForDests([{ key: '01' }], app.rows, '');
    assert.equal(calls(), 2);
    assert.equal(app.rendered, true);
    assert.equal(app.rows[0].warehouseTransferState, 'succeeded');
});

test('failed worker closes popup and never renders an invented slip', async () => {
    const { app, popup } = printingApp(['failed']);
    await assert.rejects(app._printSlipsForDests([{ key: '01' }], app.rows, ''), /生成失敗/);
    assert.equal(app.rendered, undefined);
    assert.equal(popup.closed, true);
    assert.equal(app.rows[0].printed, false);
});

test('blocked popup does not call result API or mark printed', async () => {
    const { app, context, calls } = printingApp();
    context.window.open = () => null;
    await assert.rejects(app._printSlipsForDests([{ key: '01' }], app.rows, ''), /ポップアップ/);
    assert.equal(calls(), 0);
    assert.equal(app.rows[0].printed, false);
});

test('selected row-store pairs stay separate when retrieving whole actual slips', async () => {
    const { app } = printingApp();
    const rows = [{ id: 'a::01', sourceRowId: 'a', alloc_01: 2 }, { id: 'b::02', sourceRowId: 'b', alloc_02: 3 }];
    await app._printSlipsForDests([{ key: '01' }, { key: '02' }], rows, '');
    assert.deepEqual(app.requestBody.row_destinations, { 'allocation::a': ['01'], 'allocation::b': ['02'] });
});

test('warehouse transfer locks synchronously before asynchronous preparation', async () => {
    const { app, context } = component();
    let opened = 0;
    context.window.open = () => { opened++; return { document: { body: {} }, close() {} }; };
    app.hasSelectedBulkDeliveryCourse = () => true;
    let release;
    app.applyBulkAllocationDates = () => new Promise(resolve => { release = resolve; });
    app.persistGeneratedState = async () => {};
    app.getWarehouseTransferSourceRows = () => [];
    const first = app.generateWarehouseTransfers();
    const lockedDuringPreparation = app.warehouseTransferCreating;
    // A rapid second click must not open a second window or start another request.
    const second = app.generateWarehouseTransfers();
    release();
    assert.equal(lockedDuringPreparation, true);
    assert.equal(opened, 1);
    await Promise.all([first, second]);
    assert.equal(app.warehouseTransferCreating, false);
});

for (const isDirect of [false, true]) {
    test(`delivery date edits normalize and persist the ${isDirect ? 'direct' : 'allocation'} row`, () => {
        const { app } = component();
        const row = isDirect ? app.makeDirectRow({ deliveryDate: '2026/11/05' }) : app.makeAllocationRow({ deliveryDate: '2026/11/05' });
        app.saveRowsForMode = mode => { app.savedMode = mode; };
        app.handleDeliveryDateChanged(row, isDirect);
        assert.equal(row.deliveryDate, '2026-11-05');
        assert.equal(app.savedMode, isDirect);
    });
}

for (const mode of ['allocation', 'direct']) {
    test(`${mode} CSV preserves case and piece quantities for master-based conversion`, () => {
        const { app } = component();
        const csv = '商品CD,入数,ケース,バラ,01_希望,01_分配\n3002,12,2,3,27,27';
        const rows = mode === 'direct'
            ? app.buildDirectRowsFromCsv(csv, 'case-piece.csv')
            : app.buildAllocationRowsFromCsv(csv, 'case-piece.csv');
        assert.equal(rows.length, 1);
        const row = rows[0];
        assert.equal(row.poCase, 2);
        assert.equal(row.poEach, 3);
        assert.equal(app.calcOrderTotalPieces(row, () => 27), 27);
        // Product hydration may replace the CSV case size with the master size.
        row.unitsPerCase = 6;
        assert.equal(app.calcOrderTotalPieces(row, () => 15), 15);
    });
}

for (const filtered of [false, true]) {
    test(`direct add is visible beyond the render boundary (filtered=${filtered})`, () => {
        const { app } = component();
        app.directRows = Array.from({ length: 16 }, (_, i) => app.makeDirectRow({ id: 'existing-' + i, productCode: '3002' }));
        app.directRenderLimit = 15;
        app.directRenderReady = true;
        if (filtered) app.directKeywordSearch = 'unmatched';
        app.addDirectRow();
        const added = app.directRows.at(-1);
        assert.ok(app.getRenderableVisibleDirectRows().some(row => row.id === added.id));
        assert.equal(app.directKeywordSearch, filtered ? 'unmatched' : '');
    });
}

test('append load HTTP failure preserves editable already-loaded rows', async () => {
    const { app } = component();
    app.activeSubTab = 'direct';
    app.serverRowsLoaded = true;
    app.serverRowsPersisted = true;
    app.directRows = [{ id: 'existing', productCode: '3002', alloc_01: 3 }];
    app.fetchWithTimeout = async () => ({ ok: false, status: 503, json: async () => ({ message: 'Unavailable' }) });
    await app.loadDistributionRowsFromServer({ append: true, page: 2 });
    assert.equal(app.serverRowsLoaded, true);
    assert.equal(app.serverRowsPersisted, true);
    assert.equal(app.serverRowsLoadError, '');
    assert.equal(app.directRows[0].alloc_01, 3);
    assert.equal(app.distributionRowsLoading, false);
});

for (const isDirect of [false, true]) {
    test(`${isDirect ? 'direct' : 'allocation'}: invalid quantities never become different valid quantities`, () => {
        const { app } = component();
        const row = isDirect ? app.makeDirectRow() : app.makeAllocationRow();
        let saves = 0;
        app.queueQuantitySave = () => saves++;
        for (const field of ['poCase', 'poEach', 'alloc_01']) {
            row[field] = 7;
            for (const input of ['1.5', '1e3', '-2', '12本', 'NaN', '100000000', '9007199254740993']) {
                if (field === 'alloc_01') app.setAllocationQuantity(row, '01', input, isDirect);
                else app.setOrderQuantity(row, field, input, isDirect);
                assert.equal(row[field], 7, `${field}: ${input} must preserve the previous quantity`);
                assert.match(app.lastToast, /整数/);
            }
        }
        assert.equal(saves, 0);
    });

    test(`${isDirect ? 'direct' : 'allocation'}: case and piece input boundaries and totals`, () => {
        const { app } = component();
        const row = isDirect ? app.makeDirectRow() : app.makeAllocationRow();
        app.queueQuantitySave = () => {};
        row.unitsPerCase = 12;
        app.setOrderQuantity(row, 'poCase', '2', isDirect);
        app.setOrderQuantity(row, 'poEach', '3', isDirect);
        app.setAllocationQuantity(row, '01', '27', isDirect);
        assert.equal(app.calcOrderTotalPieces(row), 27);
        app.setOrderQuantity(row, 'poCase', '', isDirect);
        assert.equal(app.calcOrderTotalPieces(row), 3);
        app.setOrderQuantity(row, 'poEach', '0', isDirect);
        assert.equal(app.calcOrderTotalPieces(row), 27);
        app.setAllocationQuantity(row, '01', '', isDirect);
        assert.equal(app.calcOrderTotalPieces(row), 0);
        app.setOrderQuantity(row, 'poEach', '99999999', isDirect);
        assert.equal(row.poEach, 99999999);
    });
}

for (const isDirect of [false, true]) {
    test(`${isDirect ? 'direct' : 'allocation'}: IME numbers wait for composition and normalize on commit`, () => {
        const { app } = component();
        const row = isDirect ? app.makeDirectRow() : app.makeAllocationRow();
        let saves = 0;
        app.queueQuantitySave = () => saves++;
        for (const key of ['poCase', 'poEach', 'alloc_01']) {
            row[key] = 7;
            const target = { value: '１', dataset: { quantityComposing: 'true' } };
            app.handleQuantityInput({ target, isComposing: true }, row, key, isDirect);
            assert.equal(row[key], 7);
            assert.equal(target.value, '１');
            // Some browsers send change/input before compositionend without isComposing.
            app.handleQuantityInput({ target }, row, key, isDirect, true);
            assert.equal(row[key], 7);
            delete target.dataset.quantityComposing;
            target.value = '１２３';
            app.handleQuantityInput({ target }, row, key, isDirect, true);
            assert.equal(row[key], 123);
            assert.equal(target.value, 123);
            target.value = '１．５';
            app.handleQuantityInput({ target }, row, key, isDirect, true);
            assert.equal(row[key], 123);
            assert.equal(target.value, 123);
        }
        assert.equal(saves, 3);
    });
}

test('fullwidth digits 0 through 9 preserve integer values and reject fullwidth fractions', () => {
    const { app } = component();
    for (let digit = 0; digit <= 9; digit++) {
        const parsed = app.parseCsvQuantityInput(String.fromCharCode(0xFF10 + digit));
        assert.equal(parsed.valid, true);
        assert.equal(parsed.value, digit);
    }
    assert.equal(app.parseCsvQuantityInput('９９９９９９９９').value, 99999999);
    for (const value of ['１．５', '－２', '１ｅ３', '１００００００００']) {
        assert.equal(app.parseCsvQuantityInput(value).valid, false, value);
    }
});

for (const [orderingCode, renderedCode] of [['01000153', '01000153'], ['00<&', '00&lt;&amp;'], ['', '']]) {
    test(`direct request prints current ordering code ${JSON.stringify(orderingCode)} without falling back to JAN`, async () => {
        const { app, context } = component();
        let html = '';
        const popup = { closed: false, close() { this.closed = true; }, document: {
            body: { textContent: '' }, open() {}, write(value) { html += value; }, close() {},
        } };
        context.URL = URL;
        context.window.location.origin = 'https://wms.sakemaru.test';
        context.window.open = () => popup;
        context.window.setInterval = () => 1;
        context.window.clearInterval = () => {};
        const row = { id: 'order-code', itemId: 100, productCode: '100', name: '確認商品',
            jan: 'JAN-NOT-FOR-ORDER', unitsPerCase: 1, checked: true, alloc_01: 2,
            poCase: 0, poEach: 2, supplier: '確認仕入先', orderDate: '2026-10-27', deliveryDate: '2026-10-30' };
        app.directRows = [row];
        app.getDirectRequestPrintableRows = () => [row];
        app.getDirectRequestDestinationPool = () => [{ key: '01', code: '1', name: '本店' }];
        app.persistDistributionRowsImmediately = async () => {};
        app.csrfHeaders = () => ({});
        app.fetchWithTimeout = async () => ({ ok: true, json: async () => ({ result: { data: {
            print_token: 'test-token', rows: [{ ...row, orderingCode }],
        } } }) });
        await app.printDirectRequestForm({});
        assert.equal(app.lastError, undefined);
        assert.equal(popup.closed, false);
        assert.ok(html.includes('発注用コード（JAN等）'));
        assert.ok(html.includes(`<td class="jan-cell">${renderedCode}</td>`));
        assert.ok(!html.includes('JAN-NOT-FOR-ORDER'));
        assert.equal(app.directPrintSession.signature, app.directPrintSignature([row]));
        if (process.env.DISTRIBUTION_PRINT_CAPTURE && orderingCode === '01000153') {
            const { writeFileSync } = await import('node:fs');
            writeFileSync(process.env.DISTRIBUTION_PRINT_CAPTURE, html);
        }
        app.finishDirectRequestPrint(true);
    });
}

test('aggregate store saves use each batch revision without replacing other batches', async () => {
    const { app } = component();
    app.activeSubTab = 'store-view';
    app.csrfHeaders = () => ({});
    app.rows = [app.makeAllocationRow({ id:'x', distributionBatchId:1, distributionBatchRevision:2, alloc_01:12 }),
        app.makeAllocationRow({ id:'y', distributionBatchId:2, distributionBatchRevision:7, alloc_01:24 })];
    const requests=[];
    app.fetchWithTimeout = async (url, options) => {
        const payload=JSON.parse(options.body);requests.push({url,payload});
        return {ok:true,json:async()=>({result:{data:{revision:payload.expected_revision+1}}})};
    };
    await app.persistDistributionRows('allocation', app.rows);
    assert.deepEqual(requests.map(r=>[r.url,r.payload.expected_revision,r.payload.replace,r.payload.rows.length]),
        [['/api/distribution/batches/1/rows',2,false,1],['/api/distribution/batches/2/rows',7,false,1]]);
    await app.persistDistributionRows('allocation', app.rows);
    assert.equal(requests[2].payload.expected_revision,3);
    assert.equal(requests[3].payload.expected_revision,8);
});

test('an ambiguous aggregate store save blocks retries and retains entered quantities', async () => {
    const { app } = component();app.activeSubTab='store-view';app.csrfHeaders=()=>({});
    app.rows=[app.makeAllocationRow({id:'x',distributionBatchId:1,distributionBatchRevision:2,alloc_01:99})];
    app.fetchWithTimeout=async()=>{throw new Error('network interrupted');};
    await assert.rejects(app.persistDistributionRows('allocation',app.rows), e=>e.distributionSaveOutcomeUnknown===true);
    assert.equal(app.serverSaveBlocked.allocation,true);assert.equal(app.rows[0].alloc_01,99);
});

test('opening a newer header never lends its revision to stale detail rows', async () => {
    const {app}=component({batch:{id:1,name:'分配X',revision:2}});
    app.serverRowRevisions.allocation=2;app.rows=[{id:'x',alloc_01:12}];
    app.waitForDistributionSaves=async()=>{};
    app.batchRequest=async()=>({id:1,name:'分配X',revision:3,updater:'担当B',updated_at:'2026-10-28 14:00'});
    await app.editBatchInfo();
    assert.equal(app.serverRowRevisions.allocation,2);
    assert.equal(app.batchEditor.visible,false);
    assert.equal(app.serverSaveBlocked.allocation,true);
    assert.equal(app.rows[0].alloc_01,12);
    assert.match(app.lastError,/担当B/);
});


test('explicit HQ and other warehouses display dynamically while HQ does not create a self transfer', () => {
    const { app } = component({batch: {id: 123, warehouse_id: 91}});
    const destinations = [{key:'01',code:'01',id:1,name:'本店'}, {key:'91',code:'91',id:91,name:'本部',isHidden:true}, {key:'05',code:'05',id:5,name:'武生店',isHidden:true}];
    app.getKnownDestinationPool = () => destinations;
    app.getStoreDestinationPool = () => destinations;
    const row = {source:'excel', visibleDestinationKeys:['01','91','05'], alloc_01:2,alloc_91:3,alloc_05:4,wish_01:2,wish_91:3,wish_05:4};
    assert.deepEqual(keys(app.getAllocationDisplayDestinations([row])).sort(), ['01','05','91']);
    assert.deepEqual(keys(app.getWarehouseTransferDestinationPool(row)).sort(), ['01','05']);
    assert.equal(app.hasWarehouseTransferTargetDestination({alloc_91:3}), false);
});


test('archived batch read-side normalization never queues a forbidden save', async () => {
    const { app } = component({ batch: { id: 4, archived: true, revision: 3 } });
    app.serverRowsPersisted = true;
    app.prepareRowsForServerPersistence = () => { throw new Error('archived data must stay untouched'); };
    app.queuePersistDistributionRows('allocation', [{ id: 'old' }], { immediate: true });
    assert.equal(!!app.serverSaveTimers.allocation, false);
    await app.waitForDistributionSaves('allocation');
});

test('transfer generation date does not replace the optimistic row snapshot', () => {
    const { app } = component();
    const row = {id:'one', itemId:1, productCode:'100001', orderDate:'2026-10-28', deliveryDate:'2026-10-29', alloc_01:2};
    const payload = app.buildStockTransferSlipPayload([row], '', [{id:1,key:'01'}], {processDate:'2026-11-01'});
    assert.equal(payload.process_date, '2026-11-01');
    assert.equal(payload.rows[0].order_date, '2026-10-28');
    assert.equal(payload.rows[0].delivery_date, '2026-10-29');
});


test('transfer print groups incoming pages then outgoing pages then references', async () => {
    const {app, context} = component();
    let html;
    context.Blob = class { constructor(parts) { html = parts.join(''); } };
    context.URL = {createObjectURL: () => 'blob:test', revokeObjectURL() {}};
    context.setTimeout = () => {};
    app.getProductMeta = () => ({});
    const row = { productCode: '100001', name:'確認商品', unitsPerCase:6, alloc_01:2, alloc_02:3 };
    const documents = [
        {kind:'transfer', destination:{key:'01',name:'本店'}, rows:Array(12).fill(row), serial_id:'111',slip_number:'S1'},
        {kind:'transfer', destination:{key:'02',name:'二の宮店'}, rows:[row], serial_id:'222',slip_number:'S2'},
        {kind:'direct-reference', destination:{key:'02',name:'二の宮店'}, rows:[row]},
    ];
    const printWindow = {location:{replace() {}},closed:false};
    assert.equal(app._renderSlipsForDests([], [], '', {documents,printWindow}),true);
    assert.deepEqual(Array.from(html.matchAll(/<div class="document-title">([^<]+)<\/div>/g),m=>m[1]),[
        '転送伝票（入庫用）','転送伝票（入庫用）','転送伝票（入庫用）',
        '転送伝票（出庫用）','転送伝票（出庫用）','転送伝票（出庫用）','直送分配参考（移動伝票なし）',
    ]);
});
