<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DistributionPersistenceSafetyTest extends TestCase
{
    public function test_distribution_rows_are_upserted_in_bounded_chunks_without_updating_creator(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('array_chunk($saveRows, 250)', $source);
        $this->assertStringContainsString("->upsert(\n", $source);
        $this->assertStringContainsString("'created_by' => (int) \$user->id", $source);
        $this->assertStringNotContainsString("'deleted_at',\n                            'created_by'", $source);
    }

    public function test_distribution_row_protection_does_not_hydrate_all_row_json(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString("->pluck('row_id')", $source);
        $this->assertStringContainsString('foreach ($foundRowIds->chunk(250) as $chunk)', $source);
        $this->assertStringContainsString("->get(['row_id', 'row_data'])", $source);
        $this->assertStringContainsString("JSON_EXTRACT(row_data, '$.orderCandidateGenerated')", $source);
    }

    public function test_store_management_has_no_delete_action(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringNotContainsString('deleteSelectedStoreRows()', $source);
        $this->assertStringNotContainsString('hasStoreDeleteTarget()', $source);
        $this->assertStringNotContainsString('getStoreDeleteButtonLabel()', $source);
    }

    public function test_server_rows_replace_stale_browser_cache_even_when_empty(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('this.rows = options.append', $source);
        $this->assertStringContainsString('this.directRows = options.append', $source);
        $this->assertStringNotContainsString('localAllocationRows.length > 0', $source);
        $this->assertStringNotContainsString('localDirectRows.length > 0', $source);
    }

    public function test_distribution_saves_are_serialized_and_coalesced(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('serverSavePending: {}', $source);
        $this->assertStringContainsString('serverSaveRunning: {}', $source);
        $this->assertStringContainsString('previousPending.resolve({ superseded: true })', $source);
        $this->assertStringContainsString('expected_revision:', $source);
        $this->assertStringContainsString('async deleteRowsAndPersist(targetRows, isDirect = false', $source);
        $this->assertStringContainsString('await this.waitForDistributionSaves(mode);', $source);
        $this->assertStringContainsString('await this.persistDistributionRowsImmediately(mode, persistable);', $source);
        $this->assertStringContainsString('this.saveData({ skipServer: true });', $source);
        $this->assertStringContainsString('this.saveDirectData({ skipServer: true });', $source);
    }

    public function test_generated_state_is_awaited_and_protected_rows_are_resynchronized(): void
    {
        $pageSource = $this->distributionPageSource();
        $controllerSource = $this->controllerSource();

        $this->assertStringContainsString("await this.persistGeneratedState('allocation')", $pageSource);
        $this->assertStringContainsString("await this.persistGeneratedState('direct')", $pageSource);
        $this->assertStringContainsString('resync_required_row_ids', $pageSource);
        $this->assertStringContainsString('preserveOrderCandidateLockedFields', $controllerSource);
        $this->assertStringContainsString('preserveConfirmedLockedFields', $controllerSource);
        $this->assertStringContainsString('$preservedData[\'checked\'] = $incomingChecked;', $controllerSource);
        $this->assertStringContainsString("str_starts_with((string) \$field, 'alloc_')", $controllerSource);
        $this->assertStringContainsString("'distributionBusinessKey', 'source', 'sourceKey'", $controllerSource);
        $this->assertStringContainsString('assertDistributionGenerationRowsAreCurrent', $controllerSource);
        $this->assertStringContainsString('reloadDistributionRowsAfterProtection(mode)', $pageSource);
        $this->assertStringContainsString('hasUnpersistedChunkSuccess', $pageSource);
    }

    public function test_append_load_failure_does_not_disable_existing_server_persistence(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString("if (!options.append) {\n          this.serverRowsLoaded = false;", $source);
        $this->assertStringContainsString("await this.persistGeneratedState('allocation');\n        const sourceRows", $source);
        $this->assertStringContainsString("await this.persistGeneratedState('direct');\n        const result = await this.createOrderCandidates", $source);
    }

    public function test_product_resolution_allows_items_without_a_contractor(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('private function formatContractorAddress(?object $contractor): string', $source);
        $this->assertStringContainsString("if (! \$contractor) {\n            return '';", $source);
    }

    public function test_product_code_match_does_not_skip_other_supported_code_searches(): void
    {
        $source = $this->controllerSource();

        $this->assertStringNotContainsString('An exact item-code match is definitive', $source);
        $this->assertStringContainsString("->table('item_search_information as isi')", $source);
        $this->assertStringContainsString("->table('item_quantity_information as iqi')", $source);
        $this->assertStringContainsString('for ($length = $baseLength; $length <= 13; $length++)', $source);
        $this->assertStringNotContainsString('LPAD(isi.search_string', $source);
        $this->assertStringNotContainsString('LPAD(iqi.product_code', $source);
        $this->assertStringNotContainsString('LPAD(iqi.own_code', $source);
    }

    public function test_arrival_schedule_requests_are_cancelled_and_cache_expires(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('arrivalScheduleCacheTtlMs: 120000', $source);
        $this->assertStringContainsString('>総バラ</th>', $source);
        $this->assertStringContainsString('formatArrivalTotalPieces(item)', $source);
        $this->assertStringContainsString('総バラ合計:', $source);
        $this->assertStringContainsString('getArrivalScheduleTotalPieces()', $source);
        $this->assertStringContainsString("'totalPieces' => \$totalPieces", $this->controllerSource());
        $this->assertStringContainsString('arrivalScheduleAbortController: null', $source);
        $this->assertStringContainsString('this.arrivalScheduleAbortController.abort()', $source);
        $this->assertStringContainsString('requestId !== this.arrivalScheduleRequestId', $source);
        $this->assertStringContainsString('Date.now() - cached.cachedAt < this.arrivalScheduleCacheTtlMs', $source);
    }

    public function test_comparison_destinations_render_stores_before_departments(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('orderStoreSelectionKeys(keys)', $source);
        $this->assertStringContainsString("...this.getStoreControlStoreDestinations(),\n        ...this.getStoreControlDepartmentDestinations(),", $source);
        $this->assertStringContainsString('this.selectedStores = this.orderStoreSelectionKeys(', $source);
        $this->assertStringNotContainsString('.concat(Array.from(selected))', $source);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'x-for="storeKey in selectedStores"')
        );
        $this->assertStringNotContainsString('getRenderableSelectedStoreKeys()', $source);
    }

    public function test_direct_store_selector_includes_destinations_used_by_direct_rows(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString("this.storeIndividualSourceView || 'allocation'", $source);
        $this->assertStringContainsString("this.getStoreSourceActiveDestinationKeySet('direct')", $source);
        $this->assertStringContainsString(
            '!destination.isHidden || (isDirectView && activeKeys.has(String(destination.key)))',
            $source
        );
    }

    public function test_warehouse_transfer_requires_a_selected_row(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString(
            ':disabled="warehouseTransferCreating || transferSlipCreating || !hasWarehouseTransferCreatableRows()"',
            $source
        );
        $this->assertStringContainsString("getWarehouseTransferSourceRows() {\n      return this.getSelectedDeleteRows(false);", $source);
        $this->assertStringContainsString("this.hasAllocationRequiredInfo(row) &&\n        !this.isWarehouseTransferCreated(row)", $source);
        $this->assertStringNotContainsString('(row?.checked || this.isOrderCandidateCreated(row))', $source);
        $this->assertStringContainsString('<div class="leading-tight">選択</div>', $source);
        $this->assertStringContainsString('x-text="getAllocationStatusLabel(row)"', $source);
        $this->assertStringContainsString("if (this.isAllocationTransferCompleted(row)) return '移動生成済';", $source);
        $this->assertStringContainsString(":class=\"isAllocationTransferCompleted(row) ? 'bg-gray-100' : ''\"", $source);
        $this->assertStringContainsString("return '未対応';", $source);
        $this->assertStringNotContainsString('<option value="warehouse_transfer_creatable">移動生成前</option>', $source);
        $this->assertStringNotContainsString("return '移動生成前';", $source);
        $this->assertStringContainsString('(Array.isArray(row?.transferSlipQueueIds) && row.transferSlipQueueIds.length > 0)', $source);
        $this->assertStringContainsString('(Array.isArray(row?.transfer_slip_queue_ids) && row.transfer_slip_queue_ids.length > 0)', $source);
        $this->assertStringContainsString('this.isDistributionCompletedFlag(row?.warehouse_transfer_generated)', $source);
        $this->assertStringContainsString("value === true || value === 1 || String(value ?? '').toLowerCase() === 'true' || String(value ?? '') === '1'", $source);
        $this->assertStringContainsString('(Array.isArray(row?.warehouse_transfer_queue_ids) && row.warehouse_transfer_queue_ids.length > 0)', $source);
        $allocationCompletionStart = strpos($source, 'isAllocationTransferCompleted(row) {');
        $allocationCompletionEnd = strpos($source, 'isWarehouseTransferCreated(row) {', $allocationCompletionStart);
        $allocationCompletion = substr($source, $allocationCompletionStart, $allocationCompletionEnd - $allocationCompletionStart);
        $this->assertStringNotContainsString('this.isTransferSlipCreated(row)', $allocationCompletion);
        $this->assertStringNotContainsString('row?.printed', $allocationCompletion);
        $this->assertStringNotContainsString('row?.transferSlipCreatedAt', $allocationCompletion);
        $this->assertStringContainsString('if (!this.isAllocationTransferCompleted(r)) return false;', $source);
        $this->assertStringContainsString('!this.isOrderCandidateCreated(row) && !this.isAllocationTransferCompleted(row)', $source);
        $this->assertStringContainsString("'件は削除対象から除外します。'", $source);
        $this->assertStringContainsString("this.deleteSelectedRowIds = this.deleteSelectedRowIds.filter(rowId => !rowIds.has(String(rowId)));", $source);
        $this->assertStringContainsString("field.startsWith('alloc_') && toInt(value) > 0", $source);
        $this->assertStringContainsString('getWarehouseTransferDestinationPool(row)', $source);
        $this->assertStringContainsString('isWarehouseTransferExcludedDestination(key, destination = null)', $source);
        $this->assertStringContainsString("STORE_WHOLESALE_DESTINATION_CODES.has(String(key || ''))", $source);
        $this->assertStringContainsString('getDestinationsForKeys(keys)', $source);
        $this->assertStringContainsString('return this.getDestinationsForKeys(Array.from(mergedKeys));', $source);
        $this->assertStringContainsString('DESTS.forEach(destination => mergedKeys.add(String(destination.key)));', $source);
        $this->assertStringContainsString("throw new Error('分配先マスタを確認できません。店舗・部門CD: ' + key);", $source);
        $this->assertStringContainsString('useRowDestinationPool: true,', $source);
        $this->assertStringContainsString("? this.getWarehouseTransferDestinationPool(row)\n          : destsList;", $source);
    }

    public function test_warehouse_transfer_snapshot_excludes_department_allocations(): void
    {
        $controller = new \App\Http\Controllers\Api\DistributionProductController;
        $requestSnapshot = new \ReflectionMethod($controller, 'normalizeDistributionGenerationAllocations');
        $persistedSnapshot = new \ReflectionMethod($controller, 'persistedDistributionGenerationAllocations');

        $this->assertSame(['01' => 2], $requestSnapshot->invoke($controller, [
            ['destination_key' => '01', 'quantity' => 2],
            ['destination_key' => '97', 'quantity' => 3],
        ], 'warehouse-transfer'));
        $this->assertSame(['01' => 2], $persistedSnapshot->invoke($controller, [
            'alloc_01' => 2,
            'alloc_97' => 3,
        ], 'warehouse-transfer'));
        $this->assertSame(['01' => 2, '97' => 3], $persistedSnapshot->invoke($controller, [
            'alloc_01' => 2,
            'alloc_97' => 3,
        ], 'order-candidate'));
    }

    public function test_store_confirmation_filter_resets_to_unconfirmed_on_reload(): void
    {
        $source = $this->distributionPageSource();
        $filterStateStart = strpos($source, 'getStoreFilterState()');
        $filterStateEnd = strpos($source, 'setStoreFilterState(', $filterStateStart);
        $filterState = substr($source, $filterStateStart, $filterStateEnd - $filterStateStart);

        $this->assertStringContainsString("this.storeConfirmStatus = 'unconfirmed';", $source);
        $this->assertStringNotContainsString('confirmStatus:', $filterState);
    }

    public function test_deselecting_a_store_removes_only_its_slip_selections(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('removeStoreSlipSelectionsForStore(storeKey)', $source);
        $this->assertStringContainsString("const prefix = String(storeKey || '') + '::';", $source);
        $this->assertStringContainsString('.filter(key => !key.startsWith(prefix))', $source);
        $this->assertStringContainsString("if (idx >= 0) {\n        this.removeStoreSlipSelectionsForStore(storeKey);", $source);
    }

    public function test_confirmation_timestamp_and_store_selection_cleanup_are_preserved(): void
    {
        $view = $this->distributionPageSource();
        $controller = $this->controllerSource();

        $this->assertStringContainsString('confirmedAt:', $view);
        $this->assertStringContainsString('setRowChecked(row, checked, isDirect = false)', $view);
        $this->assertStringContainsString("this.rememberFilterRetainedRow(row, isDirect);\n      row.checked = !!checked;", $view);
        $this->assertStringContainsString("row.confirmedAt = row.checked ? (row.confirmedAt || new Date().toISOString()) : '';", $view);
        $this->assertStringContainsString("if (isDirect) {\n        this.saveDirectData();\n      } else {\n        this.saveData();", $view);
        $this->assertStringContainsString("? { ...row, checked: flag, confirmedAt: flag ? (row.confirmedAt || new Date().toISOString()) : '' }\n        : row);\n      this.saveData();", $view);
        $this->assertStringContainsString('this.rememberFilterRetainedRows(confirmableRows, false);', $view);
        $this->assertStringContainsString("'orderCandidateCreatedAt',\n        'confirmedAt',", $view);
        $this->assertStringContainsString("this.selectedStores = [];\n        this.selectedStoreSlipKeys = [];", $view);
        $this->assertStringContainsString("JSON_EXTRACT(row_data, '$.confirmedAt')", $controller);
        $this->assertStringContainsString("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.confirmed_at')), '')", $controller);
    }

    public function test_reprint_store_label_prefers_numeric_store_code(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('x-text="getReprintStoreLabel(dest)"', $source);
        $this->assertStringContainsString('getReprintStoreLabel(destination)', $source);
        $this->assertStringContainsString("candidates.find(value => /^\\d+$/.test(value))", $source);
    }

    public function test_direct_default_filter_shows_unprocessed_rows(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('const DIRECT_FILTER_VERSION = 3;', $source);
        $this->assertStringContainsString("this.directDateFrom = isCurrentDirectFilter\n        ? (this.normalizeFlexibleDate(state.dateFrom || '') || '')\n        : getTodayString();", $source);
        $this->assertStringContainsString("this.directDateTo = isCurrentDirectFilter\n        ? (this.normalizeFlexibleDate(state.dateTo || '') || '')\n        : getTodayString();", $source);
        $this->assertStringContainsString("directPrintedFilter: 'unprocessed'", $source);
        $this->assertStringContainsString("statusFilter: 'unprocessed'", $source);
        $this->assertStringContainsString("this.directPrintedFilter = 'unprocessed';", $source);
    }

    public function test_direct_initial_page_loads_up_to_api_safe_limit_without_rendering_every_row(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString("params.set('per_page', '200');", $source);
        $this->assertStringContainsString('const DISTRIBUTION_DIRECT_TABLE_RENDER_LIMIT = 15;', $source);
        $this->assertStringContainsString('const DISTRIBUTION_DIRECT_INITIAL_RENDER_LIMIT = 15;', $source);
        $this->assertStringContainsString('<template x-if="getVisibleDirectRows().length > 0">', $source);
        $this->assertStringContainsString('x-show="isDirectRenderLimited()" x-cloak type="button" @click="loadMoreDirectRows()"', $source);
        $this->assertStringContainsString('toInt(this.serverRowPagination.direct?.total || 0)', $source);
    }

    public function test_allocation_rows_are_rendered_in_incremental_batches(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('const DISTRIBUTION_TABLE_RENDER_LIMIT = 15;', $source);
        $this->assertStringContainsString('const DISTRIBUTION_TABLE_RENDER_INCREMENT = 15;', $source);
        $this->assertStringContainsString('allocationRenderLimit: DISTRIBUTION_TABLE_RENDER_LIMIT', $source);
        $this->assertStringContainsString('allocationVisibleRowsCacheKey:', $source);
        $this->assertStringContainsString('getAllocationVisibleRowsCacheKey()', $source);
        $this->assertStringContainsString('touchAllocationRows()', $source);
        $this->assertStringContainsString('loadMoreAllocationRows()', $source);
        $this->assertStringContainsString("'さらに ' + getAllocationLoadMoreCount() + ' 件表示'", $source);
        $this->assertStringContainsString("' 件 / 全 ' + matchedCount + ' 件'", $source);
        $this->assertSame(1, substr_count($source, 'x-text="getAllocationCountLabel()"'));
        $this->assertStringContainsString('<template x-if="getVisibleRows().length > 0">', $source);
        $this->assertStringContainsString('x-show="isAllocationRenderLimited()" x-cloak type="button" @click="loadMoreAllocationRows()"', $source);
        $this->assertStringContainsString('return isDirect ? this.getVisibleDirectRows() : this.getRenderableVisibleRows();', $source);
        $this->assertStringContainsString('const renderedRows = this.getRenderableVisibleRows();', $source);
        $this->assertStringContainsString('const visibleRows = this.getRenderableVisibleRows();', $source);
        $this->assertStringContainsString(':disabled="getDeleteSelectableRows(false).length === 0"', $source);
        $this->assertStringContainsString('quantitySaveTimers: { allocation: null, direct: null }', $source);
        $this->assertStringContainsString('queueQuantitySave(isDirect = false, delay = 250)', $source);
        $this->assertStringContainsString('flushQuantitySave(isDirect = false)', $source);
        $this->assertStringContainsString('setAllocationQuantity(row, destinationKey, value, isDirect = false)', $source);
        $this->assertStringContainsString('row[key] = Math.max(0, toInt(value));', $source);
        $this->assertStringContainsString("this.saveRowsForMode(isDirect, { immediate: true });", $source);
        $this->assertStringContainsString('hasPendingDistributionSave()', $source);
        $this->assertStringContainsString("window.addEventListener('beforeunload', this.beforeUnloadHandler);", $source);
        $this->assertStringContainsString('if (!options.append) this.resetAllocationRenderWindow();', $source);
        $this->assertStringNotContainsString('画面保護のため先頭 ', $source);
    }

    public function test_direct_request_prints_generation_timestamp_on_every_page(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString("const generatedDateTime = now.getFullYear() + '年'", $source);
        $this->assertStringContainsString('<div class="document-generated-at">生成日時: ${generatedDateTime}</div>', $source);
        $this->assertStringContainsString('.document-generated-at {', $source);
        $this->assertStringNotContainsString('DIRECT DISTRIBUTION REQUEST', $source);
    }

    public function test_direct_request_omits_destination_columns_without_any_quantity(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString(
            "const STORE_WHOLESALE_DESTINATION_CODES = new Set(['90', '92', '93', '94', '95', '96', '97', '98']);",
            $source
        );
        $this->assertStringContainsString('this.calcDirectRequestAllocTotal(row) > 0', $source);
        $this->assertStringContainsString('getDirectRequestDestinationPool(row)', $source);
        $this->assertStringContainsString('!this.isWholesaleDestination(destination)', $source);
        $this->assertStringContainsString('...dataset.map(row => this.getDirectRequestDestinationPool(row))', $source);
        $this->assertStringContainsString(').filter(destination => !this.isWholesaleDestination(destination));', $source);
        $this->assertStringContainsString(
            "const activeDests = dests.filter(dest =>\n            rows.some(row => toInt(row?.['alloc_' + dest.key] || 0) > 0)",
            $source
        );
    }

    public function test_direct_request_marks_only_jx_finet_orders_as_eos(): void
    {
        $view = $this->distributionPageSource();
        $controller = $this->controllerSource();

        $this->assertStringContainsString("String(row.transmissionType || '').toUpperCase() === 'JX_FINET'", $view);
        $this->assertStringContainsString('EOS発注控え</div>', $view);
        $this->assertStringContainsString('.eos-order-badge {', $view);
        $this->assertStringContainsString("'wcs.transmission_type'", $controller);
        $this->assertStringContainsString("'transmissionType' => (string) (\$contractor->transmission_type ?? '')", $controller);
    }

    public function test_order_candidate_payload_uses_each_rows_dynamic_destinations(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString(
            'const allocations = this.getRowDestinationPool(row).map(dest => {',
            $source
        );
    }

    public function test_direct_order_generation_keeps_dynamic_destinations_while_request_excludes_departments(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString(
            'const rowsWithoutAllocation = rows.filter(row => this.calcDirectAllocTotal(row) <= 0);',
            $source
        );
        $this->assertStringContainsString(
            '...dataset.map(row => this.getDirectRequestDestinationPool(row))',
            $source
        );
        $this->assertStringContainsString(
            'const allocations = this.getRowDestinationPool(row).map(dest => {',
            $source
        );
        $this->assertStringContainsString(
            '店舗別の分配数を入力してください。ケース・バラだけでは発注候補を生成できません。',
            $source
        );
    }

    public function test_direct_request_is_marked_printed_only_from_the_print_window(): void
    {
        $source = $this->distributionPageSource();
        $controller = $this->controllerSource();

        $this->assertStringContainsString("event.data?.type === 'distribution-direct-request-printed'", $source);
        $this->assertStringContainsString('markDirectRequestRowsPrinted(rowIds = [])', $source);
        $this->assertStringContainsString("await this.persistDistributionRowsImmediately('direct', persistable);", $source);
        $this->assertStringContainsString("fetch('/api/distribution/rows/direct-request-printed'", $source);
        $this->assertStringContainsString('this.serverRowRevisions.direct = toInt(result.revision', $source);
        $this->assertStringNotContainsString("previousPrinted.set(String(row.id || ''), !!row.printed);", $source);
        $this->assertStringContainsString('directRequestPrintInProgress: false', $source);
        $this->assertStringContainsString('directRequestPrintStatusSaving: false', $source);
        $this->assertStringContainsString('let activeDirectRequestPrintWindow = null;', $source);
        $this->assertStringContainsString('event.source !== activeDirectRequestPrintWindow', $source);
        $this->assertStringContainsString('beginDirectRequestPrint(dataset, w)', $source);
        $this->assertStringContainsString('if (!printWindow || printWindow.closed) this.finishDirectRequestPrint();', $source);
        $this->assertStringContainsString("event.data?.type === 'distribution-direct-request-printing'", $source);
        $this->assertStringContainsString('x-show="directRequestPrintInProgress && directRequestPrintStatusSaving"', $source);
        $printingHandler = substr(
            $source,
            strpos($source, "event.data?.type === 'distribution-direct-request-printing'"),
            strpos($source, "event.data?.type === 'distribution-direct-request-printed'")
                - strpos($source, "event.data?.type === 'distribution-direct-request-printing'")
        );
        $this->assertStringNotContainsString('this.directRequestPrintStatusSaving = true;', $printingHandler);
        $this->assertStringContainsString('if (this.directRequestPrintStatusSaving && !force) return;', $source);
        $this->assertStringContainsString("event.data?.type === 'distribution-direct-request-cancelled'", $source);
        $this->assertStringContainsString('isDirect && this.isDirectRequestPrintLocked(row)', $source);
        $this->assertStringContainsString('出力済み状態を保存できませんでした。', $source);
        $this->assertStringContainsString("str_starts_with((string) \$field, 'alloc_')", $controller);
        $this->assertStringContainsString('DIRECT_REQUEST_PRINT_ROW_HAS_NO_ALLOCATION', $controller);
        $this->assertStringContainsString('isset($validDestinationKeys[$destinationKey])', $controller);
        $this->assertStringContainsString('isset($validDestinationIds[$destinationId])', $controller);
        $this->assertStringContainsString("->where('id', '<>', \$warehouseId)", $controller);
        $this->assertStringNotContainsString('$sourceWarehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);', $controller);
        $this->assertStringContainsString('window.print();if(window.confirm("印刷は完了しましたか？', $source);
        $this->assertStringContainsString('完了した場合のみ出力済みに更新します。', $source);
        $this->assertStringNotContainsString('dataset.forEach(r => { r.printed = true; });', $source);

        $printPosition = strpos($source, 'window.print();if(window.confirm("印刷は完了しましたか？');
        $printedMessagePosition = strpos($source, 'window.opener.postMessage(${JSON.stringify({ type: \'distribution-direct-request-printed\'');

        $this->assertNotFalse($printPosition);
        $this->assertNotFalse($printedMessagePosition);
        $this->assertLessThan($printedMessagePosition, $printPosition);
    }

    public function test_direct_order_to_editing_is_not_exposed(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringNotContainsString('openDirectOrderToChange', $source);
        $this->assertStringNotContainsString('productSearchPartnerChangeOnly', $source);
        $this->assertStringNotContainsString('productSearchPartnerChangeProductCode', $source);
        $this->assertStringNotContainsString('発注先変更', $source);
        $this->assertStringContainsString('<div class="text-base sm:text-lg font-semibold text-white">商品追加</div>', $source);
    }

    public function test_direct_load_more_distinguishes_loaded_rows_from_visible_rows(): void
    {
        $source = $this->distributionPageSource();
        $controller = $this->controllerSource();

        $this->assertStringContainsString('getDirectLoadedRowCount()', $source);
        $this->assertStringContainsString('getDirectServerRemainingRowCount()', $source);
        $this->assertStringContainsString('現在の絞り込み条件の対象外です', $source);
        $this->assertStringContainsString("return 'さらに ' + this.getDirectLoadMoreCount() + ' 件表示';", $source);
        $this->assertStringNotContainsString("' 件 / 読込済み: '", $source);
        $this->assertStringNotContainsString("' 件。未読込 '", $source);
        $this->assertStringContainsString('remainingRows <= DISTRIBUTION_DIRECT_AUTO_LOAD_REMAINDER', $source);
        $this->assertStringContainsString('class="distribution-list-load-more-state"', $source);
        $this->assertStringContainsString("params.set('keyword_search', String(this.directKeywordSearch || '').trim());", $source);
        $this->assertStringContainsString("params.set('status_filter', this.directCheckedOnly ? 'checked' : String(this.directPrintedFilter || 'all'));", $source);
        $this->assertStringContainsString("await this.waitForDistributionSaves('direct');", $source);
        $this->assertStringContainsString('serverSaveErrors: { allocation: null, direct: null }', $source);
        $this->assertStringContainsString('if (this.serverSaveErrors[mode])', $source);
        $this->assertStringContainsString('this.serverSaveErrors[mode] = error;', $source);
        $this->assertStringContainsString("await this.loadDistributionRowsFromServer({ mode: 'direct', page: 1 });", $source);
        $this->assertStringContainsString("if (mode === 'direct') {\n        // Direct rows can be server-filtered.", $source);
        $this->assertStringContainsString("// would delete rows outside the current conditions, so direct saves are always partial.\n        return false;", $source);
        $this->assertStringContainsString("'status_filter' => 'nullable|string|in:all,checked,unchecked,order_created,order_pending,printed,unprinted,unprocessed,request_printed'", $controller);
        $this->assertStringContainsString('applyDirectDistributionRowsFilters($query, $request);', $controller);
        $this->assertStringContainsString('applyDistributionRowKeywordFilter', $controller);
        $this->assertStringContainsString('distributionRowSearchExpression', $controller);
        $this->assertStringContainsString('REGEXP_REPLACE({$searchExpression}, ?, \'\') LIKE ?', $controller);
        $this->assertStringContainsString('COLLATE utf8mb4_0900_ai_ci', $controller);
        $this->assertStringNotContainsString('foreach ([\' \', \'　\', \'[\'', $controller);
        $this->assertStringContainsString('CAST(COALESCE({$expression}, \'\') AS UNSIGNED) = ?', $controller);
        $this->assertStringContainsString("if (!/^\\d+$/.test(compactField) || !/^\\d+$/.test(compactQ)) return false;", $source);
    }

    public function test_direct_distribution_uses_one_selection_and_status_based_locking(): void
    {
        $source = $this->distributionPageSource();
        $controller = $this->controllerSource();

        $this->assertStringContainsString('<div class="leading-tight">選択</div>', $source);
        $this->assertStringContainsString("this.directRows = [];\n      this.deleteSelectedRowIds = [];\n      this.directDeleteSelectedRowIds = [];", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'autocomplete="off"'));
        $this->assertStringContainsString('>状態</th>', $source);
        $this->assertStringNotContainsString('<div class="leading-tight">確定</div>', substr($source, strpos($source, '<!-- ==================== 直送分配 Tab')));
        $this->assertStringContainsString('getSelectedDirectRows(rows = this.getVisibleDirectRows())', $source);
        $this->assertStringContainsString('isDirectRowEditLocked(row)', $source);
        $this->assertStringContainsString("return this.isOrderCandidateCreated(row);", $source);
        $this->assertStringContainsString("if (\$mode === 'direct') {\n                \$incomingData = \$existingData;", $controller);
        $this->assertStringNotContainsString('DIRECT_REQUEST_PRINT_ROW_NOT_CONFIRMED', $controller);
    }

    public function test_generation_lock_is_shared_through_mysql(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString("DbMutex::acquireOrFail(\$databaseLockKey, 1, 'sakemaru')", $source);
        $this->assertStringContainsString("DbMutex::release(\$databaseLockKey, 'sakemaru')", $source);
        $this->assertStringContainsString('DISTRIBUTION_GENERATION_LOCK_ERROR', $source);
        $this->assertStringNotContainsString("Cache::store('file')->lock", $source);
    }

    public function test_direct_csv_drops_rows_without_a_resolved_item(): void
    {
        $source = $this->distributionPageSource();

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'importedRows = importedRows.filter(row => row.itemId);')
        );
    }

    public function test_csv_skips_rows_without_any_quantity_input(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('let hasCsvQuantityInput =', $source);
        $this->assertStringContainsString('if (!hasCsvQuantityInput) {', $source);
        $this->assertStringContainsString('発注数・店舗別希望数・分配数がすべて空欄のためスキップ', $source);
        $this->assertStringContainsString('parseCsvQuantityInput(value)', $source);
        $this->assertStringContainsString("const isPlainInteger = /^\\d+(?:\\.0+)?$/.test(raw);", $source);
        $this->assertStringContainsString("const isGroupedInteger = /^\\d{1,3}(?:,\\d{3})+(?:\\.0+)?$/.test(raw);", $source);
        $this->assertStringContainsString('if (!isPlainInteger && !isGroupedInteger)', $source);
        $this->assertStringContainsString('if (invalidQuantityFields.length > 0) {', $source);
        $this->assertStringContainsString('数量が0以上99,999,999以下の整数ではありません:', $source);
        $this->assertStringContainsString('let hasPositiveCsvQuantity = csvPoCase > 0 || csvPoEach > 0;', $source);
        $this->assertStringContainsString('} else if (!hasPositiveCsvQuantity) {', $source);
        $this->assertStringContainsString('発注数・店舗別希望数・分配数がすべて0のためスキップ', $source);
    }

    public function test_csv_import_errors_are_sorted_by_row_number(): void
    {
        $source = $this->distributionPageSource();

        $this->assertStringContainsString('in getCsvImportFailureDetails()', $source);
        $this->assertStringContainsString("String(detail?.rowNumber ?? '').normalize('NFKC')", $source);
        $this->assertStringContainsString('nextProgress.failureDetails = this.sortCsvImportFailureDetails(updates.failureDetails);', $source);
        $this->assertStringContainsString('this.getCsvImportFailureRowOrder(a) - this.getCsvImportFailureRowOrder(b)', $source);
    }

    public function test_csv_imported_rows_are_placed_before_existing_rows(): void
    {
        $source = $this->distributionPageSource();

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, "...importedRows,\n          ...this.")
        );
        $this->assertStringContainsString('this.resetAllocationRenderWindow();', $source);
        $this->assertStringContainsString('this.resetDirectRenderWindow();', $source);
    }

    public function test_direct_unprocessed_filter_does_not_treat_json_null_as_printed(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString(
            "NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.transferSlipCreatedAt')), 'null'), '')",
            $source
        );
    }

    public function test_store_management_hides_zero_wish_and_allocation_rows(): void
    {
        $source = $this->distributionPageSource();
        $controller = $this->controllerSource();

        $this->assertStringContainsString('hasStoreDistributionQuantity(row, storeKey)', $source);
        $this->assertStringContainsString("toInt(row?.['wish_' + storeKey] || 0) !== 0", $source);
        $this->assertStringContainsString("toInt(row?.['alloc_' + storeKey] || 0) !== 0", $source);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'if (!this.hasStoreDistributionQuantity(row, storeKey))')
        );
        $this->assertStringContainsString('distributionRowQuantityErrors($rows, $existingRowData)', $controller);
        $this->assertStringContainsString('$quantityErrors = $this->distributionRowQuantityErrors($rows);', $controller);
        $this->assertStringContainsString("->whereIn('row_id', \$invalidRowIds->all())", $controller);
        $this->assertStringNotContainsString("->whereIn('row_id', \$rowIds->all())\n                ->get(['row_id', 'row_data'])", $controller);
        $this->assertStringContainsString("'DISTRIBUTION_ROW_QUANTITY_INVALID'", $controller);
        $this->assertStringContainsString("'DISTRIBUTION_ROW_QUANTITY_INVALID',\n                null,\n                \$quantityErrors", $controller);
        $this->assertStringContainsString("str_starts_with(\$field, 'wish_')", $controller);
        $this->assertStringContainsString("str_starts_with(\$field, 'alloc_')", $controller);
        $this->assertLessThan(
            strpos($controller, '$quantityErrors = $this->distributionRowQuantityErrors'),
            strpos($controller, 'if (! $this->canView($permissionService, $user, $requiredPermission))')
        );
    }

    public function test_distribution_row_quantity_validation_rejects_invalid_values(): void
    {
        $controller = new \App\Http\Controllers\Api\DistributionProductController;
        $method = new \ReflectionMethod($controller, 'distributionRowQuantityErrors');

        $this->assertSame([], $method->invoke($controller, [[
            'wish_01' => 0,
            'alloc_01' => '12',
        ]]));

        $errors = $method->invoke($controller, [[
            'wish_01' => -1,
            'alloc_01' => '1.5',
        ]]);

        $this->assertArrayHasKey('rows.0.wish_01', $errors);
        $this->assertArrayHasKey('rows.0.alloc_01', $errors);

        $this->assertSame([], $method->invoke($controller, [[
            'id' => 'legacy-row',
            'wish_01' => -1,
        ]], [
            'legacy-row' => ['wish_01' => -1],
        ]));

        $changedLegacyErrors = $method->invoke($controller, [[
            'id' => 'legacy-row',
            'wish_01' => -2,
        ]], [
            'legacy-row' => ['wish_01' => -1],
        ]);
        $this->assertArrayHasKey('rows.0.wish_01', $changedLegacyErrors);
    }

    private function controllerSource(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Api/DistributionProductController.php');
    }

    private function distributionPageSource(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/pages/distribution-app.blade.php');
    }
}
