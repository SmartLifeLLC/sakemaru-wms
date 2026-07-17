<div class="livewire-root"><x-filament-panels::page>
    <div class="distribution-app-shell">
        <style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap");
    .distribution-app-shell { font-family: "Inter", sans-serif; width: 100%; min-width: 0; background: #f9fafb; }
    [x-cloak] { display: none !important; }
    .fi-no, .fi-no-notifications, .fi-notifications { top: 3.25rem !important; z-index: 10000 !important; }
    .distribution-toast { top: 3.25rem; right: 1rem; z-index: 10000; }
    .filter-loading-overlay { align-items: center; backdrop-filter: blur(1px); background: rgba(15, 23, 42, 0.2); display: flex; inset: 0; justify-content: center; position: fixed; z-index: 180; }
    .filter-loading-panel { align-items: center; background: #fff; border: 1px solid #e0e7ff; border-radius: 0.5rem; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18); display: flex; gap: 0.75rem; padding: 1rem 1.25rem; }
    .filter-loading-spinner { animation: distribution-filter-spin 0.8s linear infinite; border: 2px solid #c7d2fe; border-radius: 9999px; border-top-color: #4f46e5; height: 1.25rem; width: 1.25rem; }
    .filter-loading-spinner-sm { border-color: #c7d2fe; border-top-color: #fff; border-width: 1.5px; height: 0.75rem; width: 0.75rem; }
    @keyframes distribution-filter-spin { to { transform: rotate(360deg); } }
    .alloc-spin-hide::-webkit-inner-spin-button,
    .alloc-spin-hide::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .alloc-spin-hide { -moz-appearance: textfield; }
    .tab-button.active { border-bottom-width: 4px; font-weight: 700; }
    .date-entry { position: relative; display: flex; align-items: center; width: 7.75rem; min-width: 7.75rem; }
    .date-entry-text { padding-right: 1.35rem !important; font-variant-numeric: tabular-nums; }
    .date-entry-picker { position: absolute; right: 2px; top: 2px; bottom: 2px; width: 1.5rem; display: inline-flex; align-items: center; justify-content: center; color: #64748b; border-radius: 0.25rem; cursor: pointer; }
    .date-entry-picker:hover { color: #4f46e5; background: #eef2ff; }
    .date-entry-picker.is-disabled { color: #cbd5e1; background: transparent; cursor: not-allowed; }
    .date-entry-native { position: absolute; inset: 0; z-index: 1; width: 100%; height: 100%; opacity: 0; cursor: pointer; color: transparent; background: transparent; border: 0; font-size: 0; }
    .date-entry-native:disabled { cursor: not-allowed; }
    .date-entry-native::-webkit-datetime-edit,
    .date-entry-native::-webkit-datetime-edit-fields-wrapper,
    .date-entry-native::-webkit-datetime-edit-text,
    .date-entry-native::-webkit-datetime-edit-month-field,
    .date-entry-native::-webkit-datetime-edit-day-field,
    .date-entry-native::-webkit-datetime-edit-year-field { color: transparent; }
    .date-entry-native::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0; }
    .date-entry-picker svg { pointer-events: none; }
    .store-date-entry { width: 8.75rem; min-width: 8.75rem; }
    .store-date-entry .date-entry-text { padding-left: 0.5rem !important; padding-right: 1.9rem !important; text-align: left; width: 100% !important; }
    .store-date-entry .date-entry-picker { right: 4px; }
    .date-column { width: 7.75rem; min-width: 7.75rem; max-width: 7.75rem; }
    .date-column .date-entry { width: 100%; min-width: 100%; }
    .date-stack-column { width: 8rem; min-width: 8rem; max-width: 8rem; }
    .date-stack-column .date-entry { width: 100%; min-width: 100%; }
    .date-stack { display: flex; flex-direction: column; gap: 0.25rem; }
    .partner-stack-column { vertical-align: middle; width: 16rem; min-width: 16rem; max-width: 16rem; }
    .partner-stack { display: flex; flex-direction: column; gap: 0.25rem; height: 100%; justify-content: center; }
    .partner-stack-line { align-items: center; display: grid; line-height: 1.2; min-height: 1.5rem; overflow: visible; overflow-wrap: anywhere; text-overflow: clip; white-space: normal; }
    .partner-stack-line + .partner-stack-line { border-top: 1px solid #e5e7eb; padding-top: 0.25rem; }
    .product-code-entry { width: 5.5rem; min-width: 5.5rem; }
    .distribution-filter-controls,
    .distribution-filter-controls *,
    .filter-row,
    .filter-row * {
      font-family: inherit !important;
      font-size: 0.75rem !important;
      line-height: 1rem !important;
      letter-spacing: 0 !important;
    }
    .distribution-filter-controls input,
    .distribution-filter-controls select,
    .distribution-filter-controls button,
    .filter-row input,
    .filter-row select,
    .filter-row button {
      font-family: inherit !important;
    }
    .distribution-search-input { padding-right: 3.5rem !important; }
    .filter-row .date-entry { flex: 0 0 8.75rem; width: 8.75rem !important; min-width: 8.75rem; }
    .filter-row .date-entry-text { padding-left: 0.5rem !important; padding-right: 1.35rem !important; width: 100% !important; }
    .product-search-table { border-collapse: separate; border-spacing: 0; }
    .product-search-sticky { background-clip: padding-box; position: sticky; }
    .product-search-table thead .product-search-sticky { background-color: #f3f4f6; z-index: 35; }
    .product-search-table tbody .product-search-sticky { background-color: inherit; z-index: 5; }
    .product-search-sticky-select { left: 0; }
    .product-search-sticky-code { left: 54px; }
    .product-search-sticky-name { box-shadow: 2px 0 0 #e5e7eb; left: 174px; }
    .product-search-scroll { container-type: inline-size; position: relative; }
    .product-search-state { align-items: center; display: flex; flex-direction: column; justify-content: center; left: 0; min-height: 7rem; position: sticky; width: 100%; }
    .distribution-list-empty-state { align-items: center; display: flex; justify-content: center; left: 0; min-height: 5.5rem; position: sticky; width: calc(100vw - 2rem); }
    @media (min-width: 1024px) { .distribution-list-empty-state { width: calc(100vw - 3rem); } }
    .store-distribution-detail-table thead th { background: #f9fafb; border-bottom: 1px solid #94a3b8; position: sticky; top: 0; z-index: 20; }
    .store-distribution-detail-table tbody tr { border-top: 1px solid #94a3b8 !important; }
    .store-distribution-detail-table tbody td { border-bottom: 1px solid #94a3b8; }
    .store-comparison-store-col { max-width: 8.5rem; min-width: 8.5rem; width: 8.5rem; }
    .allocation-destination-grid {
      background: #fff;
      border: 1px solid #cbd5e1;
      border-radius: 0.375rem;
      display: grid;
      grid-auto-columns: 5rem;
      grid-auto-flow: column;
      grid-template-columns: none;
      gap: 0;
      min-width: max-content;
      overflow: visible;
      width: max-content;
    }
    .allocation-destination-store {
      background: #fff;
      border-bottom: 1px solid #cbd5e1;
      border-right: 1px solid #cbd5e1;
      max-width: 5rem;
      min-width: 5rem;
      overflow: hidden;
    }
    .allocation-destination-store-header {
      background: #fafafa;
      border-bottom: 1px solid #d1d5db;
      min-height: 2.25rem;
      padding: 0.25rem;
      text-align: center;
    }
    .allocation-destination-input {
      box-sizing: border-box;
      margin: 0.125rem;
      width: calc(100% - 0.25rem);
    }
    .allocation-destination-cell {
      padding: 0.5rem;
      position: relative;
    }
    .allocation-destination-shell {
      box-sizing: border-box;
      left: 0.5rem;
      max-width: min(100%, calc(100vw - 2rem));
      min-width: 0;
      overflow-x: auto;
      overflow-y: hidden;
      position: sticky;
      width: min(100%, calc(100vw - 2rem));
    }
    @media (min-width: 640px) {
      .allocation-destination-shell {
        max-width: min(100%, calc(100vw - 3rem));
        width: min(100%, calc(100vw - 3rem));
      }
    }

    /* Responsive adjustments */
    @media (max-width: 1279px) {
      .filter-row { gap: 4px 8px !important; }
      .filter-row label.ml-3, .filter-row label.ml-2 { margin-left: 0 !important; }
      .filter-row input[type="text"]:not(.date-entry-text) { width: 120px !important; }
      .filter-row .date-entry { flex: 0 0 8.75rem; width: 8.75rem !important; min-width: 8.75rem; }
      .filter-row .date-entry-text { width: 100% !important; }
    }
    @media (max-width: 767px) {
      .filter-row { font-size: 12px !important; }
      .filter-row input[type="date"], .filter-row input[type="text"], .filter-row select { font-size: 12px !important; min-width: 0 !important; }
      .filter-row input[type="text"]:not(.date-entry-text) { width: 100px !important; }
      .filter-row .date-entry { flex: 0 0 8.75rem; width: 8.75rem !important; min-width: 8.75rem; }
      .filter-row .date-entry-text { width: 100% !important; }
    }
        </style>

<div x-data="distributionApp()" x-init="init()" class="w-full min-w-0 px-3 sm:px-4 lg:px-5 text-neutral-900">

  <!-- Toast -->
  <div x-show="toastVisible" x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 -translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 -translate-y-2"
    class="distribution-toast fixed">
    <div class="rounded-xl bg-black/80 text-white px-4 py-2 shadow-lg text-sm" x-text="toastMessage"></div>
  </div>

  <!-- Filter Loading -->
  <div x-show="filterApplying" x-cloak class="filter-loading-overlay">
    <div class="filter-loading-panel text-sm">
      <span class="filter-loading-spinner"></span>
      <div>
        <div class="font-semibold text-slate-800" x-text="filterApplyingLabel"></div>
        <div class="mt-0.5 text-xs text-slate-500">条件を反映しています</div>
      </div>
    </div>
  </div>

  <!-- Alert Modal -->
  <div x-show="modalVisible" x-cloak class="fixed inset-0 z-[200] flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/40" @click="modalVisible = false"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-lg sm:max-w-xl overflow-hidden" @click.stop>
      <div class="px-5 py-3 font-semibold text-sm" :class="modalTitle === 'エラー' ? 'bg-red-600 text-white' : 'bg-indigo-600 text-white'" x-text="modalTitle"></div>
      <div class="p-5 text-sm leading-relaxed text-gray-700" x-text="modalMessage"></div>
      <div class="px-5 pb-4 flex justify-end">
        <button @click="modalVisible = false" class="px-4 py-1.5 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">OK</button>
      </div>
    </div>
  </div>

  <!-- Result Modal -->
  <div x-show="resultModalVisible" x-cloak class="fixed inset-0 z-[205] flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/45" @click="resultModalVisible = false"></div>
    <div x-show="resultModalVisible"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 transform scale-95"
         x-transition:enter-end="opacity-100 transform scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 transform scale-100"
         x-transition:leave-end="opacity-0 transform scale-95"
         class="relative w-full max-w-lg sm:max-w-xl overflow-hidden rounded-lg bg-white shadow-2xl" @click.stop>
      <div class="flex items-center gap-3 px-5 py-3 text-white" :class="resultModalVariant === 'danger' ? 'bg-red-600' : 'bg-emerald-600'">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/15">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path x-show="resultModalVariant !== 'danger'" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
            <path x-show="resultModalVariant === 'danger'" stroke-linecap="round" stroke-linejoin="round" d="M12 8v5m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z"></path>
          </svg>
        </div>
        <div class="min-w-0">
          <div class="text-sm font-semibold" x-text="resultModalTitle"></div>
          <div class="mt-0.5 text-xs text-white/80">処理結果を確認してください</div>
        </div>
      </div>
      <div class="px-5 py-5 text-sm leading-relaxed text-gray-700" x-text="resultModalMessage"></div>
      <div class="flex justify-end px-5 pb-4">
        <button @click="resultModalVisible = false" :class="resultModalVariant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'" class="rounded-md px-4 py-1.5 text-sm font-medium text-white">OK</button>
      </div>
    </div>
  </div>

  <!-- CSV Import Progress Modal -->
  <div x-show="csvImportProgress.visible" x-cloak class="fixed inset-0 z-[210] flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/45"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-2xl overflow-hidden" @click.stop>
      <div class="px-5 py-3 font-semibold text-sm text-white" :class="csvImportProgress.failedRows > 0 ? 'bg-amber-600' : (csvImportProgress.error ? 'bg-red-600' : 'bg-emerald-600')">
        CSV取込状況
      </div>
      <div class="p-5 text-sm text-gray-700 space-y-4">
        <div class="flex items-center gap-3">
          <div x-show="csvImportProgress.running" x-cloak class="h-8 w-8 rounded-full border-4 border-emerald-200 border-t-emerald-600 animate-spin"></div>
          <div x-show="!csvImportProgress.running" x-cloak class="h-8 w-8 rounded-full flex items-center justify-center" :class="csvImportProgress.failedRows > 0 || csvImportProgress.error ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path x-show="!csvImportProgress.error" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
              <path x-show="csvImportProgress.error" stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z"></path>
            </svg>
          </div>
          <div class="min-w-0">
            <div class="font-semibold text-gray-900" x-text="csvImportProgress.status || 'CSVを確認しています'"></div>
            <div class="mt-0.5 truncate text-xs text-gray-500" x-text="csvImportProgress.fileName"></div>
          </div>
        </div>

        <div class="space-y-1.5">
          <div class="flex items-center justify-between text-xs text-gray-600">
            <span x-text="csvImportProgress.progressLabel || (csvImportProgress.running ? '処理中' : '完了')"></span>
            <span class="font-semibold tabular-nums" x-text="csvImportProgressPercent() + '%'"></span>
          </div>
          <div class="h-2.5 overflow-hidden rounded-full bg-gray-200">
            <div class="h-full rounded-full transition-all duration-300"
                 :class="csvImportProgress.error ? 'bg-red-500' : (csvImportProgress.failedRows > 0 && !csvImportProgress.running ? 'bg-amber-500' : 'bg-emerald-500')"
                 :style="'width: ' + csvImportProgressPercent() + '%'"></div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 text-xs">
          <div class="rounded-md bg-slate-50 p-3">
            <div class="text-gray-500">CSV明細行</div>
            <div class="mt-1 text-lg font-bold text-gray-900" x-text="csvImportProgress.totalRows"></div>
          </div>
          <div class="rounded-md bg-emerald-50 p-3">
            <div class="text-emerald-700">取込行数</div>
            <div class="mt-1 text-lg font-bold text-emerald-800" x-text="csvImportProgress.importedRows"></div>
          </div>
          <div class="rounded-md bg-indigo-50 p-3">
            <div class="text-indigo-700">商品確定</div>
            <div class="mt-1 text-lg font-bold text-indigo-800" x-text="csvImportProgress.resolvedRows"></div>
          </div>
          <div class="rounded-md bg-amber-50 p-3">
            <div class="text-amber-700">確認が必要</div>
            <div class="mt-1 text-lg font-bold text-amber-800" x-text="csvImportProgress.failedRows"></div>
          </div>
        </div>

        <div class="rounded-md border border-slate-200 bg-white p-3 text-xs leading-5">
          <div>スキップ: <span class="font-semibold" x-text="csvImportProgress.skippedRows"></span> 件</div>
          <div>商品未確定: <span class="font-semibold" x-text="csvImportProgress.unresolvedRows"></span> 件</div>
          <div>商品候補複数: <span class="font-semibold" x-text="csvImportProgress.ambiguousRows"></span> 件</div>
          <template x-if="csvImportProgress.error">
            <div class="mt-2 text-red-600" x-text="csvImportProgress.error"></div>
          </template>
          <template x-if="!csvImportProgress.error && !csvImportProgress.running && csvImportProgress.failedRows === 0">
            <div class="mt-2 text-emerald-700">CSVの取込対象行はすべて取り込まれました。</div>
          </template>
          <template x-if="!csvImportProgress.error && !csvImportProgress.running && csvImportProgress.failedRows > 0">
            <div class="mt-2 text-amber-700">取込は完了しましたが、取込できない行があります。エラーリストを確認してください。</div>
          </template>
        </div>

        <template x-if="csvImportProgress.failureDetails && csvImportProgress.failureDetails.length > 0">
          <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs">
            <div class="mb-2 flex items-center justify-between">
              <div class="font-semibold text-amber-900">エラーリスト</div>
              <div class="text-amber-700" x-text="csvImportProgress.failureDetails.length + '件'"></div>
            </div>
            <div class="max-h-52 overflow-auto rounded border border-amber-100 bg-white">
              <template x-for="(detail, index) in csvImportProgress.failureDetails" :key="index">
                <div class="grid grid-cols-[4.5rem_1fr] gap-2 border-b border-amber-100 px-2 py-1.5 last:border-b-0">
                  <div class="font-mono text-amber-800" x-text="'行 ' + (detail.rowNumber || '-')"></div>
                  <div class="min-w-0">
                    <div class="font-medium text-gray-800" x-text="detail.reason || '-'"></div>
                    <div class="mt-0.5 truncate text-gray-500" x-show="detail.code || detail.name" x-text="[detail.code ? 'コード: ' + detail.code : '', detail.name ? '商品名: ' + detail.name : ''].filter(Boolean).join(' / ')"></div>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </template>
      </div>
      <div class="px-5 pb-4 flex justify-end gap-2">
        <button
          x-show="!csvImportProgress.running && csvImportProgress.failureDetails && csvImportProgress.failureDetails.length > 0"
          x-cloak
          @click="downloadCsvImportFailureDetails()"
          class="px-4 py-1.5 rounded-md bg-amber-600 text-white text-sm font-medium hover:bg-amber-700">
          エラーCSV出力
        </button>
        <button @click="csvImportProgress.visible = false" :disabled="csvImportProgress.running" class="px-4 py-1.5 rounded-md bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-700 disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed">閉じる</button>
      </div>
    </div>
  </div>

  <!-- 確認モーダル -->
  <div x-show="confirmVisible" x-cloak class="fixed inset-0 z-[200] flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/50" @click="confirmVisible = false; confirmCallback = null"></div>
    <div x-show="confirmVisible"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 transform scale-95"
         x-transition:enter-end="opacity-100 transform scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 transform scale-100"
         x-transition:leave-end="opacity-0 transform scale-95"
         class="relative bg-white rounded-lg shadow-xl p-4 sm:p-6 max-w-lg sm:max-w-2xl w-full" @click.stop>
      <div class="flex items-center gap-3 mb-4">
        <template x-if="confirmModalType === 'delete'">
          <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
            <svg class="w-7 h-7 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
          </div>
        </template>
        <template x-if="confirmModalType !== 'delete'">
          <div class="flex-shrink-0 w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center">
            <svg class="w-7 h-7 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          </div>
        </template>
        <div class="min-w-0 flex-1">
          <p class="text-lg font-bold" :class="confirmModalType === 'delete' ? 'text-red-600' : 'text-yellow-600'" x-text="confirmModalType === 'delete' ? '削除確認' : '確認'"></p>
        <p class="text-gray-700 mt-1 leading-relaxed whitespace-pre-line" x-text="confirmMessage"></p>
        </div>
      </div>
      <div class="flex justify-end gap-3">
        <button @click="confirmVisible = false; confirmCallback = null" class="px-4 py-2 text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg font-medium">キャンセル</button>
        <button @click="doConfirm()" class="px-4 py-2 text-white rounded-lg font-medium" :class="confirmModalType === 'delete' ? 'bg-red-600 hover:bg-red-700' : 'bg-yellow-600 hover:bg-yellow-700'" x-text="confirmModalType === 'delete' ? '削除' : 'OK'"></button>
      </div>
    </div>
  </div>

  <!-- ==================== Allocation Tab ==================== -->
  <div x-show="activeSubTab === 'allocation'" x-cloak>

    <!-- Filters -->
    <div class="sticky top-0 z-20 bg-white/90 backdrop-blur shadow-sm">
      <div class="px-0 py-1">
        <div class="distribution-filter-controls flex flex-wrap items-center gap-1.5 text-xs">
          <div class="relative w-full flex-none sm:w-[20rem] lg:w-[24rem]">
            <input type="text" x-model="allocationFilterDraft.keywordSearch" @keydown.enter.prevent="applyAllocationFilters()" placeholder="商品CD/商品名/JAN 複数語OK" class="distribution-search-input w-full rounded-md border border-gray-300 bg-white px-2 py-1 pr-14 text-xs shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
            <button x-show="allocationFilterDraft.keywordSearch" x-cloak @click="allocationFilterDraft.keywordSearch = ''; applyAllocationFilters()" class="absolute rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" style="right: 1.75rem; top: 50%; transform: translateY(-50%);" title="検索クリア">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <button type="button" @click="applyAllocationFilters()" :disabled="filterApplying" class="absolute z-10 inline-flex h-5 w-5 items-center justify-center rounded-full border border-indigo-200 bg-indigo-50 text-indigo-700 shadow-sm hover:bg-indigo-100 hover:text-indigo-800 disabled:cursor-wait disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400" style="right: 0.375rem; top: 50%; transform: translateY(-50%);" title="検索実行">
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path stroke-linecap="round" stroke-linejoin="round" d="M20 20l-4.35-4.35"></path></svg>
            </button>
          </div>
          <label class="text-gray-700 font-medium whitespace-nowrap">状態</label>
          <select x-model="allocationFilterDraft.statusFilter" @change="applyAllocationFilters()" class="rounded-md border-gray-300 border px-2 py-1 text-xs bg-white shadow-sm">
            <option value="hide">修正不可を非表示</option>
            <option value="all">すべて表示</option>
            <option value="warehouse_transfer_creatable">倉庫移動・伝票待ち</option>
            <option value="printed">出力済のみ</option>
          </select>
          <span x-show="filterApplying" x-cloak class="filter-loading-spinner filter-loading-spinner-sm"></span>
        </div>
      </div>
    </div>

    <!-- Controls -->
    <div class="px-0 pt-1">
      <div class="mb-1 flex flex-wrap items-center gap-1.5 sm:gap-2">
        <button @click="addRow()" class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md bg-green-600 hover:bg-green-700 text-white font-medium shadow transition text-xs sm:text-sm">+ 行追加</button>
        <button @click="toggleAllCheckedVisible()"
          :class="allVisibleChecked() ? 'bg-gray-200 hover:bg-gray-300 text-gray-700' : 'bg-indigo-600 hover:bg-indigo-700 text-white'"
          class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md font-medium shadow transition text-xs sm:text-sm"
          x-text="allVisibleChecked() ? '☐ 全解除（表示中）' : '全確定'">
        </button>
        <button @click="deleteSelectedRows(false)"
          :disabled="getSelectedDeleteRowCount(false) === 0"
          :class="getSelectedDeleteRowCount(false) > 0 ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-gray-300 text-gray-500 cursor-not-allowed'"
          class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md font-medium shadow transition text-xs sm:text-sm"
          x-text="'選択削除 (' + getSelectedDeleteRowCount(false) + '件)'">
        </button>
        <div class="whitespace-nowrap rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm" aria-live="polite">
          <span>表示件数: </span>
          <span class="font-semibold text-gray-900" x-text="getVisibleRows().length"></span>
          <span> / 全</span>
          <span class="font-semibold text-gray-900" x-text="getAllocationTotalRowCount()"></span>
          <span>件</span>
        </div>

        <div class="flex flex-wrap items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs shadow-sm">
          <span class="font-semibold text-slate-700 whitespace-nowrap">一括設定</span>
          <span class="text-slate-500 whitespace-nowrap">入力日</span>
          <div class="date-entry" style="width: 8.75rem; min-width: 8.75rem;">
            <input type="text" x-model="bulkInputDate" @blur="bulkInputDate = normalizeFlexibleDate(bulkInputDate)" @keydown.enter.prevent="bulkInputDate = normalizeFlexibleDate(bulkInputDate); $event.target.blur()" inputmode="numeric" placeholder="YYYY-MM-DD" class="date-entry-text w-full rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm" />
            <label class="date-entry-picker" title="カレンダー選択">
              <input type="date" :value="bulkInputDate" @change="bulkInputDate = $event.target.value" class="date-entry-native" />
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </label>
          </div>
          <span class="text-slate-500 whitespace-nowrap">配送コース</span>
          <div class="relative" @click.outside="bulkDeliveryCourseOptions = []">
            <input type="text" x-model="bulkDeliveryCourseSearch" @focus="openBulkDeliveryCourseOptions()" @input.debounce.300ms="bulkDeliveryCourseSelected = null; searchBulkDeliveryCourses()" @keydown.enter.prevent="selectExactBulkDeliveryCourse()" placeholder="CD/名称" class="w-44 rounded-md border border-gray-300 px-2 py-1 text-xs shadow-sm focus:ring-2 focus:ring-slate-500" />
            <button type="button" x-show="bulkDeliveryCourseSelected" x-cloak @click="clearBulkDeliveryCourse()" class="absolute right-1 top-1/2 -translate-y-1/2 rounded px-1 text-[10px] text-gray-500 hover:bg-gray-100">×</button>
            <div x-show="bulkDeliveryCourseOptions.length > 0 || bulkDeliveryCourseLoading || bulkDeliveryCourseError" x-cloak class="absolute left-0 top-full z-50 mt-1 max-h-56 w-72 overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
              <div x-show="bulkDeliveryCourseLoading" class="px-2 py-1.5 text-xs text-gray-500">検索中...</div>
              <div x-show="bulkDeliveryCourseError" class="px-2 py-1.5 text-xs text-red-600" x-text="bulkDeliveryCourseError"></div>
              <template x-for="course in bulkDeliveryCourseOptions" :key="course.id">
                <button type="button" @click="selectBulkDeliveryCourse(course)" class="block w-full px-2 py-1.5 text-left text-xs hover:bg-slate-50">
                  <span class="block font-medium text-slate-700" x-text="formatDeliveryCourseLabel(course)"></span>
                  <span x-show="course.warehouse_name" class="block text-[10px] text-slate-400" x-text="'倉庫: [' + course.warehouse_code + '] ' + course.warehouse_name"></span>
                </button>
              </template>
            </div>
          </div>
          <button @click="applyBulkAllocationDates()" :disabled="bulkDateApplying || !hasBulkAllocationDateTargets()" :class="bulkDateApplying || !hasBulkAllocationDateTargets() ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : 'bg-slate-700 hover:bg-slate-800 text-white'" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium shadow-sm transition">
            <span x-show="bulkDateApplying" x-cloak class="filter-loading-spinner filter-loading-spinner-sm"></span>
            <span x-text="bulkDateApplying ? '反映中' : '表示中に反映'"></span>
          </button>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
          <input x-ref="allocationCsvInput" type="file" accept=".csv,text/csv" class="hidden" @change="importAllocationCsv($event)" />
          <button @click="$refs.allocationCsvInput.click()" :disabled="csvImporting" class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed font-medium py-1 sm:py-1.5 px-2 sm:px-3 rounded-md shadow transition text-xs">
            <svg x-show="csvImporting" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <svg x-show="!csvImporting" x-cloak class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5M5 20h14"></path>
            </svg>
            <span x-text="csvImporting ? '取込中' : 'CSV取込'"></span>
          </button>
          <button @click="downloadCSV()" class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-1 sm:py-1.5 px-2 sm:px-3 rounded-md shadow transition text-xs">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-5-5m5 5l5-5M5 20h14"></path>
            </svg>
            <span>CSV出力</span>
          </button>
          <button type="button" data-slip-action="warehouse-transfer" @click.prevent.stop="generateWarehouseTransfers()" :disabled="warehouseTransferCreating || transferSlipCreating" :title="getWarehouseTransferButtonTitle()" :class="(warehouseTransferCreating || transferSlipCreating) ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : (hasWarehouseTransferCreatableRows() ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-gray-300 text-gray-600 hover:bg-gray-400 hover:text-white')" class="font-medium py-1 sm:py-1.5 px-2 sm:px-3 rounded-md shadow transition text-xs">倉庫移動生成</button>
          <button x-cloak style="display: none;" @click="generateOrderCandidates()" :disabled="orderCandidateCreating || !hasOrderCandidateGeneratableRows()" :class="(orderCandidateCreating || !hasOrderCandidateGeneratableRows()) ? 'bg-gray-300 text-gray-500 cursor-not-allowed' : 'bg-amber-500 hover:bg-amber-600 text-white'" class="inline-flex items-center gap-1.5 font-medium py-1 sm:py-1.5 px-2 sm:px-3 rounded-md shadow transition text-xs">
            <svg x-show="orderCandidateCreating" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span x-text="orderCandidateCreating ? '作成中' : '発注候補生成'"></span>
          </button>
        </div>
      </div>
    </div>

    <!-- Main Table -->
    <div class="px-0 pb-2">
      <div x-ref="allocationMainScroll" class="overflow-auto rounded-lg bg-white shadow-md" style="max-height: calc(100vh - 180px);">
        <table class="w-full table-auto text-xs" :style="'min-width: ' + getAllocationTableMinWidth() + 'px;'">
          <thead class="sticky top-0 z-10">
            <tr class="divide-x divide-gray-200 border-t border-gray-300 bg-neutral-50 text-xs text-neutral-500">
              <th class="whitespace-nowrap w-10 p-1 text-center">
                <input type="checkbox"
                  :checked="allVisibleDeleteSelected(false)"
                  :disabled="getDeleteSelectableRows(false).length === 0"
                  @change="toggleVisibleDeleteSelection(false, $event.target.checked)"
                  class="rounded border-gray-300"
                  title="表示中の削除可能行を選択" />
              </th>
              <th class="date-stack-column hidden whitespace-nowrap p-1 text-left">
                <div>発注日</div>
                <div class="mt-0.5 text-[10px] leading-none text-neutral-400">納品希望日</div>
              </th>
              <th class="partner-stack-column hidden whitespace-nowrap p-1 text-left">
                <div>発注先</div>
                <div class="mt-0.5 text-[10px] leading-none text-neutral-400">仕入先</div>
              </th>
              <th class="whitespace-nowrap w-28 p-1 text-left">商品コード</th>
              <th class="whitespace-nowrap min-w-[192px] p-1 text-left">商品名</th>
              <th class="whitespace-nowrap w-12 p-1 text-center">入数</th>
              <th class="whitespace-nowrap w-12 p-1 text-center">ロット</th>
              <th class="whitespace-nowrap w-14 min-w-[3.5rem] p-1 text-center">理論</th>
              <th class="whitespace-nowrap w-14 min-w-[3.5rem] p-1 text-center">希計</th>
              <th class="whitespace-nowrap w-14 min-w-[3.5rem] p-1 text-center">分計</th>
              <th class="whitespace-nowrap w-16 p-1 text-center">発注ケース</th>
              <th class="whitespace-nowrap w-16 p-1 text-center">発注バラ</th>
              <th class="whitespace-nowrap w-12 p-1 text-center">総バラ</th>
              <th class="whitespace-nowrap w-10 p-1 text-center">確定</th>
              <template x-for="dest in getAllocationDisplayDestinations()" :key="dest.key">
                <th class="p-1 text-center border-l border-gray-300 w-[80px] min-w-[80px] max-w-[80px]">
                  <div class="text-[10px] text-gray-400 leading-tight" x-text="dest.key"></div>
                  <div class="font-semibold text-neutral-700 leading-tight" :class="dest.name.length > 5 ? 'text-[10px]' : 'text-xs'" x-text="dest.name"></div>
                </th>
              </template>
              <th x-show="showAllocationDetailMemoButton" x-cloak class="whitespace-nowrap w-24 p-1 text-center">明細備考</th>
              <th class="whitespace-nowrap w-10 p-1 text-center">削除</th>
            </tr>
          </thead>
          <template x-for="row in getVisibleRows()" :key="row.id">
            <tbody class="text-xs">
              <tr :class="row.checked ? 'bg-gray-100' : ''" class="divide-x divide-gray-200 border-t border-gray-200 hover:bg-neutral-50">
                <td class="p-1 text-center">
                  <input type="checkbox"
                    :checked="isRowDeleteSelected(row, false)"
                    :disabled="isRowDeleteLocked(row)"
                    @change="setRowDeleteSelected(row, false, $event.target.checked)"
                    :class="isRowDeleteLocked(row) ? 'cursor-not-allowed opacity-50' : ''"
                    class="rounded border-gray-300"
                    title="削除対象にする" />
                </td>
                <!-- 発注日 / 納品希望日 -->
                <td class="date-stack-column hidden p-1">
                  <div class="date-stack">
                    <div class="date-entry">
                      <input type="text" :value="row.orderDate" @input.debounce.600ms="row.orderDate = $event.target.value; handleOrderDateChanged(row, false)" @blur="row.orderDate = $event.target.value; handleOrderDateChanged(row, false)" @keydown.enter.prevent="$event.target.blur()" inputmode="numeric" :disabled="isRowEditLocked(row)" :class="isRowEditLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : 'focus:ring-2 focus:ring-indigo-500'" class="date-entry-text w-full rounded-md border-gray-300 border px-1 py-1 text-xs shadow-sm" />
                      <label class="date-entry-picker" :class="isRowEditLocked(row) ? 'is-disabled' : ''" title="発注日をカレンダー選択">
                        <input type="date" :value="row.orderDate" @change="row.orderDate = $event.target.value; handleOrderDateChanged(row, false)" :disabled="isRowEditLocked(row)" class="date-entry-native" />
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                      </label>
                    </div>
                    <div class="date-entry">
                      <input type="text" x-model="row.deliveryDate" @blur="row.deliveryDate = normalizeFlexibleDate(row.deliveryDate)" @keydown.enter.prevent="row.deliveryDate = normalizeFlexibleDate(row.deliveryDate); $event.target.blur()" inputmode="numeric" :disabled="isRowEditLocked(row)" :class="isRowEditLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : (isDeliveryDateBeforeOrderDate(row) ? 'border-red-500 bg-red-50 text-red-700 focus:ring-2 focus:ring-red-500' : 'focus:ring-2 focus:ring-indigo-500')" class="date-entry-text w-full rounded-md border-gray-300 border px-1 py-1 text-xs shadow-sm" />
                      <label class="date-entry-picker" :class="isRowEditLocked(row) ? 'is-disabled' : ''" title="納品希望日をカレンダー選択">
                        <input type="date" :value="row.deliveryDate" @change="row.deliveryDate = $event.target.value" :disabled="isRowEditLocked(row)" class="date-entry-native" />
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                      </label>
                    </div>
                    <div x-show="isDeliveryDateBeforeOrderDate(row)" x-cloak class="text-[10px] font-semibold leading-tight text-red-600">納品希望日が発注日より過去です</div>
                    <div x-show="isKeptVisibleByPendingFilter(row, false)" x-cloak class="text-[10px] font-semibold leading-tight text-amber-600">現在の条件外</div>
                  </div>
                </td>
                <!-- 発注先 / 仕入先 -->
                <td class="partner-stack-column hidden align-middle p-1">
                  <div class="partner-stack">
                    <div class="partner-stack-line px-1 py-0.5 text-xs text-gray-600" :title="getOrderToDisplay(row)" x-text="getOrderToDisplay(row)"></div>
                    <div class="partner-stack-line px-1 py-0.5 text-xs text-gray-500" :title="getSupplierDisplay(row)" x-text="getSupplierDisplay(row)"></div>
                  </div>
                </td>
                <!-- 商品コード + search + arrival -->
                <td class="p-1">
                  <div class="flex items-center gap-1">
                    <input type="text" x-model="row.productCode"
                      @focus="productCodeEditBefore[row.id] = row.productCode || ''"
                      @input.debounce.150ms="resolveManualProductCodeFast(row)"
                      @blur="resolveManualProductCode(row)"
                      @keydown.enter.prevent="resolveManualProductCode(row)"
                      inputmode="numeric"
                      :disabled="isProductEditLocked(row)"
                      :class="isProductEditLocked(row) ? 'bg-neutral-50 cursor-not-allowed text-gray-400' : 'focus:ring-2 focus:ring-indigo-500'"
                      class="product-code-entry rounded-md border border-gray-300 px-1 py-1 font-mono text-xs shadow-sm"
                      placeholder="商品CD/JAN" />
                    <!-- Search icon (magnifying glass SVG) -->
                    <button @click="openProductSearch(row.id)" :disabled="isProductEditLocked(row)"
                      :class="isProductEditLocked(row) ? 'text-gray-300 cursor-not-allowed' : 'text-gray-500 hover:text-indigo-600'"
                      class="shrink-0 p-0.5" title="商品検索">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                      </svg>
                    </button>
                    <!-- Arrival schedule icon (calendar SVG, blue) -->
                    <template x-if="hasArrivalSchedule(row)">
                      <button @click="openArrivalSchedule(row)"
                        class="shrink-0 p-0.5 text-blue-500 hover:text-blue-700" title="入荷予定">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                      </button>
                    </template>
                  </div>
                </td>
                <!-- 商品名 -->
                <td class="p-1">
                  <div class="px-1 py-1 whitespace-nowrap text-xs" x-text="row.name || '-'"></div>
                </td>
                <!-- 入数 -->
                <td class="p-1 text-center"><div class="px-1 py-1 whitespace-nowrap text-xs" x-text="row.unitsPerCase || ''"></div></td>
                <!-- ロット (clickable for popover) -->
                <td class="p-1 text-center">
                  <button @click="if(lotPopoverRowId === row.id){ lotPopoverRowId = null; } else { const r = $event.currentTarget.getBoundingClientRect(); const ph = 360; const below = r.bottom + 4; const above = r.top - 4; lotPopoverPos = (below + ph > window.innerHeight) ? { top: above, left: r.left + r.width/2, anchor: 'above' } : { top: below, left: r.left + r.width/2, anchor: 'below' }; lotPopoverRowId = row.id; }" class="w-6 h-6 rounded-full bg-amber-500 hover:bg-amber-600 text-white inline-flex items-center justify-center transition shadow-sm" title="ロット条件">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  </button>
                </td>
                <!-- 理論 -->
                <td class="w-14 min-w-[3.5rem] p-1 text-right" :class="toInt(row.reserved) < calcAllocTotal(row) ? 'bg-red-100' : ''">
                  <div class="px-1 py-1 font-semibold" :class="toInt(row.reserved) < calcAllocTotal(row) ? 'text-red-700' : ''" :title="getStockSummaryTitle(row)" x-text="row.reserved"></div>
                </td>
                <!-- 希計 -->
                <td class="w-14 min-w-[3.5rem] p-1 text-right"><div class="px-1 py-1 font-semibold" x-text="calcWishTotal(row)"></div></td>
                <!-- 分計 -->
                <td class="w-14 min-w-[3.5rem] p-1 text-right"><div class="px-1 py-1 font-semibold" x-text="calcAllocTotal(row)"></div></td>
                <!-- 発注ケース -->
                <td class="p-1 text-right">
                  <input type="number" :value="orderQuantityInputValue(row.poCase)" @input="setOrderQuantity(row, 'poCase', $event.target.value)" :disabled="isQuantityLocked(row)"
                    :class="isQuantityLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : 'focus:ring-2 focus:ring-indigo-500'"
                    class="w-full rounded-md border-gray-300 border px-1 py-1 text-right shadow-sm alloc-spin-hide" />
                </td>
                <!-- 発注バラ -->
                <td class="p-1 text-right">
                  <input type="number" :value="orderQuantityInputValue(row.poEach)" @input="setOrderQuantity(row, 'poEach', $event.target.value)" :disabled="isQuantityLocked(row)"
                    :class="isQuantityLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : 'focus:ring-2 focus:ring-indigo-500'"
                    class="w-full rounded-md border-gray-300 border px-1 py-1 text-right shadow-sm alloc-spin-hide" />
                </td>
                <!-- 総バラ -->
                <td class="p-1 text-right bg-gray-50">
                  <div class="px-1 py-1 font-semibold" x-text="calcOrderTotalPieces(row, currentRow => calcAllocTotal(currentRow))"></div>
                </td>
                <!-- チェック -->
                <td class="p-1 text-center">
                  <input type="checkbox" x-model="row.checked"
                    :disabled="isRowLocked(row) || !hasRowProductInfo(row)"
                    :title="!hasRowProductInfo(row) ? getMissingProductInfoMessage() : ''"
                    :class="(isRowLocked(row) || !hasRowProductInfo(row)) ? 'cursor-not-allowed opacity-70' : ''"
                    class="rounded border-gray-300" />
                </td>
                <template x-for="dest in getAllocationDisplayDestinations()" :key="dest.key">
                  <td class="p-0 border-l border-gray-300 w-[80px] min-w-[80px] max-w-[80px]">
                    <div class="flex flex-col">
                      <div class="bg-red-50 border-b-2 border-gray-300 px-2 py-1.5 text-right text-xs font-medium text-red-800"
                        x-text="row['wish_'+dest.key] || 0"></div>
                      <input type="text" inputmode="numeric"
                        :value="row['alloc_'+dest.key] || 0"
                        @input="row['alloc_'+dest.key] = parseInt($event.target.value) || 0"
                        @keydown.enter.prevent="focusNextAllocationDestinationInput($event)"
                        @focus="$event.target.select()"
                        :disabled="isQuantityLocked(row)"
                        :class="isQuantityLocked(row) ? 'bg-gray-100 cursor-not-allowed text-gray-400 border-gray-300' : (Number(row['alloc_'+dest.key] || 0) > 0 ? 'bg-yellow-50 border-2 border-yellow-400 focus:ring-2 focus:ring-yellow-400 text-yellow-800' : 'bg-green-50 border-2 border-green-300 focus:ring-2 focus:ring-green-500 focus:border-green-500 focus:bg-white text-green-900')"
                        class="allocation-destination-input w-full m-0.5 rounded px-1.5 py-1 text-right text-xs font-semibold" />
                    </div>
                  </td>
                </template>
                <!-- 明細備考 -->
                <td class="p-1 text-center">
                  <button @click="memoPopupRowId = row.id; memoPopupText = row.memo || ''" :disabled="isRowEditLocked(row)"
                    :class="isRowEditLocked(row) ? 'text-gray-300 cursor-not-allowed' : row.memo ? 'text-indigo-600 hover:text-indigo-800' : 'text-gray-400 hover:text-gray-600'"
                    class="p-1 relative" :title="row.memo || '明細備考入力'">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span x-show="row.memo" class="absolute -top-1 -right-1 w-2.5 h-2.5 bg-indigo-500 rounded-full"></span>
                  </button>
                </td>
                <!-- 削除 -->
                <td class="p-1 text-center">
                  <button @click="removeRow(row.id)" :disabled="isRowDeleteLocked(row)" :class="isRowDeleteLocked(row) ? 'text-gray-300 cursor-not-allowed' : 'text-red-400 hover:text-red-600'" class="p-1" title="削除">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </template>
          <tbody class="text-xs">
            <template x-if="getVisibleRows().length === 0">
              <tr><td colspan="99" class="p-8 text-center text-gray-500"><div class="distribution-list-empty-state">該当するデータがありません</div></td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ==================== 直送分配 Tab ==================== -->
  <div x-show="activeSubTab === 'direct'" x-cloak>

    <!-- Filters -->
    <div class="sticky top-0 z-20 bg-white/90 backdrop-blur shadow-sm">
      <div class="px-0 py-1">
        <div class="distribution-filter-controls flex flex-wrap items-center gap-1.5 text-xs">
          <div class="relative w-full flex-none sm:w-[20rem] lg:w-[24rem]">
            <input type="text" x-model="directFilterDraft.keywordSearch" @keydown.enter.prevent="applyDirectFilters()" placeholder="商品/JAN/発注先CD/仕入先CD 複数語OK" class="distribution-search-input w-full rounded-md border border-gray-300 bg-white px-2 py-1 pr-14 text-xs shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
            <button x-show="directFilterDraft.keywordSearch" x-cloak @click="directFilterDraft.keywordSearch = ''; applyDirectFilters()" class="absolute rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" style="right: 1.75rem; top: 50%; transform: translateY(-50%);" title="検索クリア">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <button type="button" @click="applyDirectFilters()" :disabled="filterApplying" class="absolute z-10 inline-flex h-5 w-5 items-center justify-center rounded-full border border-indigo-200 bg-indigo-50 text-indigo-700 shadow-sm hover:bg-indigo-100 hover:text-indigo-800 disabled:cursor-wait disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400" style="right: 0.375rem; top: 50%; transform: translateY(-50%);" title="検索実行">
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path stroke-linecap="round" stroke-linejoin="round" d="M20 20l-4.35-4.35"></path></svg>
            </button>
          </div>
          <button @click="directFilterOpen = !directFilterOpen"
            :class="directFilterOpen ? 'bg-indigo-600 text-white hover:bg-indigo-700' : (getDirectFilterCount() ? 'border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100' : 'bg-white text-gray-700 hover:bg-gray-50')"
            :title="getDirectFilterCount() ? 'フィルタ（' + getDirectFilterCount() + '件）' : 'フィルタ'"
            class="relative inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs font-medium shadow-sm transition">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18l-7 8v5l-4 2v-7L3 5z"/></svg>
            フィルタ
            <span x-show="getDirectFilterCount()" x-cloak class="ml-0.5 inline-flex min-w-4 justify-center text-[11px] font-semibold leading-none" :class="directFilterOpen ? 'text-white' : 'text-slate-700'" x-text="formatFilterCount(getDirectFilterCount())"></span>
          </button>
        </div>
        <div x-show="directFilterOpen" x-cloak class="mt-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg">
          <div class="filter-row flex flex-wrap items-center gap-1 text-xs">
            <label class="text-gray-700 font-medium whitespace-nowrap">発注日</label>
            <div class="date-entry w-32">
              <input type="text" x-model="directFilterDraft.dateFrom" @blur="directFilterDraft.dateFrom = normalizeFlexibleDate(directFilterDraft.dateFrom)" @keydown.enter.prevent="directFilterDraft.dateFrom = normalizeFlexibleDate(directFilterDraft.dateFrom); $event.target.blur()" inputmode="numeric" placeholder="YYYY-MM-DD" class="date-entry-text w-full rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm" />
              <label class="date-entry-picker" title="カレンダー選択">
                <input type="date" :value="directFilterDraft.dateFrom" @change="directFilterDraft.dateFrom = $event.target.value" class="date-entry-native" />
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              </label>
            </div>
            <span class="text-gray-500">～</span>
            <div class="date-entry w-32">
              <input type="text" x-model="directFilterDraft.dateTo" @blur="directFilterDraft.dateTo = normalizeFlexibleDate(directFilterDraft.dateTo)" @keydown.enter.prevent="directFilterDraft.dateTo = normalizeFlexibleDate(directFilterDraft.dateTo); $event.target.blur()" inputmode="numeric" placeholder="YYYY-MM-DD" class="date-entry-text w-full rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm" />
              <label class="date-entry-picker" title="カレンダー選択">
                <input type="date" :value="directFilterDraft.dateTo" @change="directFilterDraft.dateTo = $event.target.value" class="date-entry-native" />
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              </label>
            </div>
            <label class="text-gray-700 font-medium xl:ml-3 whitespace-nowrap">納品希望日</label>
            <div class="date-entry w-32">
              <input type="text" x-model="directFilterDraft.deliveryFrom" @blur="directFilterDraft.deliveryFrom = normalizeFlexibleDate(directFilterDraft.deliveryFrom)" @keydown.enter.prevent="directFilterDraft.deliveryFrom = normalizeFlexibleDate(directFilterDraft.deliveryFrom); $event.target.blur()" inputmode="numeric" placeholder="YYYY-MM-DD" class="date-entry-text w-full rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm" />
              <label class="date-entry-picker" title="カレンダー選択">
                <input type="date" :value="directFilterDraft.deliveryFrom" @change="directFilterDraft.deliveryFrom = $event.target.value" class="date-entry-native" />
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              </label>
            </div>
            <span class="text-gray-500">～</span>
            <div class="date-entry w-32">
              <input type="text" x-model="directFilterDraft.deliveryTo" @blur="directFilterDraft.deliveryTo = normalizeFlexibleDate(directFilterDraft.deliveryTo)" @keydown.enter.prevent="directFilterDraft.deliveryTo = normalizeFlexibleDate(directFilterDraft.deliveryTo); $event.target.blur()" inputmode="numeric" placeholder="YYYY-MM-DD" class="date-entry-text w-full rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm" />
              <label class="date-entry-picker" title="カレンダー選択">
                <input type="date" :value="directFilterDraft.deliveryTo" @change="directFilterDraft.deliveryTo = $event.target.value" class="date-entry-native" />
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              </label>
            </div>
            <label class="text-gray-700 font-medium xl:ml-3 whitespace-nowrap">商品</label>
            <input type="text" x-model="directFilterDraft.productSearch" placeholder="コード/名前/JAN" class="rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm w-28 sm:w-36" />
            <label class="text-gray-700 font-medium xl:ml-3 whitespace-nowrap">発注先</label>
            <input type="text" x-model="directFilterDraft.orderToSearch" placeholder="発注先CD/名" class="rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm w-24 sm:w-28" />
            <label class="text-gray-700 font-medium xl:ml-3 whitespace-nowrap">仕入先</label>
            <input type="text" x-model="directFilterDraft.supplierSearch" placeholder="仕入先CD/名" class="rounded-md border-gray-300 border px-2 py-1 text-xs shadow-sm w-24 sm:w-28" />
            <label class="inline-flex items-center gap-1 text-xs text-gray-700 whitespace-nowrap">
              <input type="checkbox" x-model="directFilterDraft.checkedOnly" class="rounded border-gray-300" />
              確定のみ
            </label>
            <select x-model="directFilterDraft.printedFilter" class="rounded-md border-gray-300 border px-2 py-1 text-xs bg-white shadow-sm">
              <option value="all">全て表示</option>
              <option value="printed">出力済みのみ</option>
              <option value="unprinted">未出力のみ</option>
            </select>
            <button @click="applyDirectFilters()" :disabled="filterApplying" class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2 py-1 text-xs font-medium text-white shadow-sm hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-70 whitespace-nowrap">
              <span x-show="filterApplying" x-cloak class="filter-loading-spinner filter-loading-spinner-sm"></span>
              フィルタ実行
            </button>
            <button @click="clearDirectFilters()" class="px-2 py-1 text-xs rounded-md bg-gray-200 hover:bg-gray-300 text-gray-700 whitespace-nowrap">条件クリア</button>
            <button @click="directFilterOpen = false" class="px-2 py-1 text-xs rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 whitespace-nowrap">閉じる</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Controls -->
    <div class="px-0 pt-1">
      <div class="mb-1 flex flex-wrap items-center gap-1.5 sm:gap-2 text-xs sm:text-sm">
        <button @click="addDirectRow()" class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md bg-blue-600 text-white text-xs sm:text-sm font-medium hover:bg-blue-700">+ 行追加</button>
        <button @click="toggleAllDirectChecked()"
          :class="allVisibleDirectChecked() ? 'bg-gray-200 hover:bg-gray-300 text-gray-700' : 'bg-indigo-600 hover:bg-indigo-700 text-white'"
          class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md text-xs sm:text-sm font-medium"
          x-text="allVisibleDirectChecked() ? '☐ 全解除（表示中）' : '全確定'"></button>
        <button @click="deleteSelectedRows(true)"
          :disabled="getSelectedDeleteRowCount(true) === 0"
          :class="getSelectedDeleteRowCount(true) > 0 ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-gray-300 text-gray-500 cursor-not-allowed'"
          class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md text-xs sm:text-sm font-medium shadow transition"
          x-text="'選択削除 (' + getSelectedDeleteRowCount(true) + '件)'"></button>
        <div class="whitespace-nowrap rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm" aria-live="polite">
          <span>表示件数: </span>
          <span class="font-semibold text-gray-900" x-text="getVisibleDirectRows().length"></span>
          <span> / 全 </span>
          <span class="font-semibold text-gray-900" x-text="getDirectTotalRowCount()"></span>
          <span> 件</span>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-1.5 sm:gap-2">
          <input x-ref="directCsvInput" type="file" accept=".csv,text/csv" class="hidden" @change="importDirectCsv($event)" />
          <button @click="$refs.directCsvInput.click()" :disabled="csvImporting" class="inline-flex items-center gap-1.5 px-2 sm:px-3 py-1 sm:py-1.5 rounded-md bg-emerald-600 text-white disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed text-xs font-medium hover:bg-emerald-700 shadow transition">
            <svg x-show="csvImporting" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <svg x-show="!csvImporting" x-cloak class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5M5 20h14"></path>
            </svg>
            <span x-text="csvImporting ? '取込中' : 'CSV取込'"></span>
          </button>
          <button @click="downloadDirectCSV()" class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700 shadow transition">CSV出力</button>
          <button type="button" data-slip-action="direct-request" @click.prevent.stop="openDirectRequestOutput()" :disabled="transferSlipCreating" :class="hasDirectRequestPrintableRows() ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-gray-300 text-gray-600 hover:bg-gray-400 hover:text-white'" class="px-2 sm:px-3 py-1 sm:py-1.5 rounded-md text-xs font-medium shadow transition">依頼書出力</button>
          <button @click="generateDirectOrderCandidates()" :disabled="orderCandidateCreating || !hasDirectOrderCandidateGeneratableRows()" :class="(!orderCandidateCreating && hasDirectOrderCandidateGeneratableRows()) ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-gray-300 text-gray-500 cursor-not-allowed'" class="inline-flex items-center gap-1.5 px-2 sm:px-3 py-1 sm:py-1.5 rounded-md text-xs font-medium shadow transition">
            <svg x-show="orderCandidateCreating" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <span x-text="orderCandidateCreating ? '作成中' : '発注候補生成'"></span>
          </button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="px-0 pb-4">
      <div class="overflow-auto rounded-lg bg-white shadow-md" style="max-height: calc(100vh - 220px);">
        <table class="w-full min-w-[2160px] table-auto text-xs">
          <thead class="sticky top-0 z-10">
            <tr class="divide-x divide-gray-200 border-t border-gray-300 bg-neutral-50 text-xs text-neutral-500">
              <th class="whitespace-nowrap w-10 p-1 text-center">
                <input type="checkbox"
                  :checked="allVisibleDeleteSelected(true)"
                  :disabled="getDeleteSelectableRows(true).length === 0"
                  @change="toggleVisibleDeleteSelection(true, $event.target.checked)"
                  class="rounded border-gray-300"
                  title="表示中の削除可能行を選択" />
              </th>
              <th class="date-stack-column whitespace-nowrap p-1 text-left">
                <div>発注日</div>
                <div class="mt-0.5 text-[10px] leading-none text-neutral-400">納品希望日</div>
              </th>
              <th class="partner-stack-column whitespace-nowrap p-1 text-left">
                <div>発注先</div>
                <div class="mt-0.5 text-[10px] leading-none text-neutral-400">仕入先</div>
              </th>
              <th class="whitespace-nowrap w-28 p-1 text-left">商品コード</th>
              <th class="whitespace-nowrap min-w-[192px] p-1 text-left">商品名</th>
              <th class="whitespace-nowrap w-12 p-1 text-center">入数</th>
              <th class="whitespace-nowrap w-12 p-1 text-center">ロット</th>
              <th class="whitespace-nowrap w-14 min-w-[3.5rem] p-1 text-center">理論</th>
              <th class="whitespace-nowrap w-14 min-w-[3.5rem] p-1 text-center">希計</th>
              <th class="whitespace-nowrap w-14 min-w-[3.5rem] p-1 text-center">分計</th>
              <th class="whitespace-nowrap w-16 p-1 text-center">発注ケース</th>
              <th class="whitespace-nowrap w-16 p-1 text-center">発注バラ</th>
              <th class="whitespace-nowrap w-12 p-1 text-center">総バラ</th>
              <th class="whitespace-nowrap w-10 p-1 text-center">確定</th>
              <template x-for="dest in DESTS" :key="dest.key">
                <th class="p-1 text-center border-l border-gray-300 w-[80px] min-w-[80px] max-w-[80px]">
                  <div class="text-[10px] text-gray-400 leading-tight" x-text="dest.key"></div>
                  <div class="font-semibold text-neutral-700 leading-tight" :class="dest.name.length > 5 ? 'text-[10px]' : 'text-xs'" x-text="dest.name"></div>
                </th>
              </template>
              <th class="whitespace-nowrap w-10 p-1 text-center">削除</th>
            </tr>
          </thead>
          <tbody class="text-xs">
            <template x-for="row in getVisibleDirectRows()" :key="row.id">
              <tr :class="row.checked ? 'bg-gray-100' : ''" class="divide-x divide-gray-200 border-t border-gray-200 hover:bg-neutral-50">
                <td class="p-1 text-center">
                  <input type="checkbox"
                    :checked="isRowDeleteSelected(row, true)"
                    :disabled="isRowDeleteLocked(row)"
                    @change="setRowDeleteSelected(row, true, $event.target.checked)"
                    :class="isRowDeleteLocked(row) ? 'cursor-not-allowed opacity-50' : ''"
                    class="rounded border-gray-300"
                    title="削除対象にする" />
                </td>
                <td class="date-stack-column p-1">
                  <div class="date-stack">
                    <div class="date-entry">
                      <input type="text" :value="row.orderDate" @input.debounce.600ms="row.orderDate = $event.target.value; handleOrderDateChanged(row, true)" @blur="row.orderDate = $event.target.value; handleOrderDateChanged(row, true)" @keydown.enter.prevent="$event.target.blur()" inputmode="numeric" :disabled="isRowEditLocked(row)" :class="isRowEditLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : 'focus:ring-2 focus:ring-indigo-500'" class="date-entry-text w-full rounded-md border-gray-300 border px-1 py-1 text-xs shadow-sm" />
                      <label class="date-entry-picker" :class="isRowEditLocked(row) ? 'is-disabled' : ''" title="発注日をカレンダー選択">
                        <input type="date" :value="row.orderDate" @change="row.orderDate = $event.target.value; handleOrderDateChanged(row, true)" :disabled="isRowEditLocked(row)" class="date-entry-native" />
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                      </label>
                    </div>
                    <div class="date-entry">
                      <input type="text" x-model="row.deliveryDate" @blur="row.deliveryDate = normalizeFlexibleDate(row.deliveryDate)" @keydown.enter.prevent="row.deliveryDate = normalizeFlexibleDate(row.deliveryDate); $event.target.blur()" inputmode="numeric" :disabled="isRowEditLocked(row)" :class="isRowEditLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : (isDeliveryDateBeforeOrderDate(row) ? 'border-red-500 bg-red-50 text-red-700 focus:ring-2 focus:ring-red-500' : 'focus:ring-2 focus:ring-indigo-500')" class="date-entry-text w-full rounded-md border-gray-300 border px-1 py-1 text-xs shadow-sm" />
                      <label class="date-entry-picker" :class="isRowEditLocked(row) ? 'is-disabled' : ''" title="納品希望日をカレンダー選択">
                        <input type="date" :value="row.deliveryDate" @change="row.deliveryDate = $event.target.value" :disabled="isRowEditLocked(row)" class="date-entry-native" />
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                      </label>
                    </div>
                    <div x-show="isDeliveryDateBeforeOrderDate(row)" x-cloak class="text-[10px] font-semibold leading-tight text-red-600">納品希望日が発注日より過去です</div>
                    <div x-show="isKeptVisibleByPendingFilter(row, true)" x-cloak class="text-[10px] font-semibold leading-tight text-amber-600">現在の条件外</div>
                  </div>
                </td>
                <td class="partner-stack-column align-middle p-1">
                  <div class="partner-stack">
                    <div class="partner-stack-line px-1 py-0.5 text-xs text-gray-600" :title="getOrderToDisplay(row)" x-text="getOrderToDisplay(row)"></div>
                    <div class="partner-stack-line px-1 py-0.5 text-xs text-gray-500" :title="getSupplierDisplay(row)" x-text="getSupplierDisplay(row)"></div>
                  </div>
                </td>
                <td class="p-1">
                  <div class="flex items-center gap-1">
                    <input type="text" x-model="row.productCode"
                      @focus="productCodeEditBefore[row.id] = row.productCode || ''"
                      @input.debounce.150ms="resolveManualProductCodeFast(row)"
                      @blur="resolveManualProductCode(row)"
                      @keydown.enter.prevent="resolveManualProductCode(row)"
                      inputmode="numeric"
                      :disabled="isProductEditLocked(row)"
                      :class="isProductEditLocked(row) ? 'bg-neutral-50 cursor-not-allowed text-gray-400' : 'focus:ring-2 focus:ring-indigo-500'"
                      class="product-code-entry rounded-md border border-gray-300 px-1 py-1 font-mono text-xs shadow-sm"
                      placeholder="商品CD/JAN" />
                    <button @click="openDirectProductSearch(row.id)" :disabled="isProductEditLocked(row)" :class="isProductEditLocked(row) ? 'text-gray-300 cursor-not-allowed' : 'text-gray-500 hover:text-indigo-600'" class="shrink-0 p-0.5" title="商品検索">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </button>
                  </div>
                </td>
                <td class="p-1"><div class="px-1 py-1 whitespace-nowrap text-xs" x-text="row.name || '-'"></div></td>
                <td class="p-1 text-center"><div class="px-1 py-1 whitespace-nowrap text-xs" x-text="row.unitsPerCase || ''"></div></td>
                <td class="p-1 text-center">
                  <button @click="if(directLotPopoverRowId === row.id){ directLotPopoverRowId = null; } else { const r = $event.currentTarget.getBoundingClientRect(); const ph = 360; const below = r.bottom + 4; const above = r.top - 4; directLotPopoverPos = (below + ph > window.innerHeight) ? { top: above, left: r.left + r.width/2, anchor: 'above' } : { top: below, left: r.left + r.width/2, anchor: 'below' }; directLotPopoverRowId = row.id; }" class="w-6 h-6 rounded-full bg-amber-500 hover:bg-amber-600 text-white inline-flex items-center justify-center transition shadow-sm" title="ロット条件">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                  </button>
                </td>
                <td class="w-14 min-w-[3.5rem] p-1 text-right" :class="toInt(row.reserved) < calcDirectAllocTotal(row) ? 'bg-red-100' : ''"><div class="px-1 py-1 font-semibold" :class="toInt(row.reserved) < calcDirectAllocTotal(row) ? 'text-red-700' : ''" :title="getStockSummaryTitle(row)" x-text="row.reserved"></div></td>
                <td class="w-14 min-w-[3.5rem] p-1 text-right"><div class="px-1 py-1 font-semibold" x-text="calcDirectWishTotal(row)"></div></td>
                <td class="w-14 min-w-[3.5rem] p-1 text-right"><div class="px-1 py-1 font-semibold" x-text="calcDirectAllocTotal(row)"></div></td>
                <td class="p-1 text-right"><input type="number" :value="orderQuantityInputValue(row.poCase)" @input="setOrderQuantity(row, 'poCase', $event.target.value)" :disabled="isQuantityLocked(row)" :class="isQuantityLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : 'focus:ring-2 focus:ring-indigo-500'" class="w-full rounded-md border-gray-300 border px-1 py-1 text-right shadow-sm alloc-spin-hide" /></td>
                <td class="p-1 text-right"><input type="number" :value="orderQuantityInputValue(row.poEach)" @input="setOrderQuantity(row, 'poEach', $event.target.value)" :disabled="isQuantityLocked(row)" :class="isQuantityLocked(row) ? 'bg-neutral-50 cursor-not-allowed' : 'focus:ring-2 focus:ring-indigo-500'" class="w-full rounded-md border-gray-300 border px-1 py-1 text-right shadow-sm alloc-spin-hide" /></td>
                <td class="p-1 text-right bg-gray-50"><div class="px-1 py-1 font-semibold" x-text="calcOrderTotalPieces(row, currentRow => calcDirectAllocTotal(currentRow))"></div></td>
                <td class="p-1 text-center">
                  <input type="checkbox" x-model="row.checked"
                    :disabled="isRowLocked(row) || !hasRowProductInfo(row)"
                    :title="!hasRowProductInfo(row) ? getMissingProductInfoMessage() : ''"
                    :class="(isRowLocked(row) || !hasRowProductInfo(row)) ? 'cursor-not-allowed opacity-70' : ''"
                    class="rounded border-gray-300" />
                </td>
                <template x-for="dest in DESTS" :key="dest.key">
                  <td class="p-0 border-l border-gray-300 w-[80px] min-w-[80px] max-w-[80px]">
                    <div class="flex flex-col">
                      <div class="bg-red-50 border-b-2 border-gray-300 px-2 py-1.5 text-right text-xs font-medium text-red-800" x-text="row['wish_'+dest.key] || 0"></div>
                      <input type="text" inputmode="numeric" :value="row['alloc_'+dest.key] || 0" @input="row['alloc_'+dest.key] = parseInt($event.target.value) || 0" @keydown.enter.prevent="focusNextAllocationDestinationInput($event)" @focus="$event.target.select()" :disabled="isQuantityLocked(row)"
                        :class="isQuantityLocked(row) ? 'bg-gray-100 cursor-not-allowed text-gray-400 border-gray-300' : (Number(row['alloc_'+dest.key] || 0) > 0 ? 'bg-yellow-50 border-2 border-yellow-400 focus:ring-2 focus:ring-yellow-400 text-yellow-800' : 'bg-blue-50 border-2 border-blue-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white text-blue-900')"
                        class="allocation-destination-input w-full m-0.5 rounded px-1.5 py-1 text-right text-xs font-semibold" />
                    </div>
                  </td>
                </template>
                <td class="p-1 text-center">
                  <button @click="removeDirectRow(row.id)" :disabled="isRowDeleteLocked(row)" :class="isRowDeleteLocked(row) ? 'text-gray-300 cursor-not-allowed' : 'text-red-400 hover:text-red-600'" class="p-1" title="削除">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                  </button>
                </td>
              </tr>
            </template>
            <template x-if="getVisibleDirectRows().length === 0">
              <tr><td colspan="99" class="p-8 text-center text-gray-500"><div class="distribution-list-empty-state">該当するデータがありません</div></td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

  <!-- 直送分配明細備考入力ポップアップ -->
  </div>

  <div x-show="directMemoPopupRowId !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/30" @click="directMemoPopupRowId = null"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-sm sm:max-w-[384px] flex flex-col" @click.stop>
      <div class="px-4 py-3 border-b bg-indigo-600 rounded-t-lg flex items-center justify-between">
        <span class="text-white font-semibold text-sm">明細備考入力</span>
        <button @click="directMemoPopupRowId = null" class="text-white/70 hover:text-white text-lg leading-none">&times;</button>
      </div>
      <div class="p-4">
        <textarea x-model="directMemoPopupText" rows="4" class="w-full rounded-md border-gray-300 border px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="明細備考を入力..."></textarea>
      </div>
      <div class="px-4 pb-4 flex justify-end gap-2">
        <button @click="directMemoPopupRowId = null" class="px-4 py-1.5 rounded-md border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">キャンセル</button>
        <button @click="const r = directRows.find(x => x.id === directMemoPopupRowId); if(r){ r.memo = directMemoPopupText; saveDirectData(); } directMemoPopupRowId = null;" class="px-4 py-1.5 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">保存</button>
      </div>
    </div>
  </div>

  <!-- ==================== Store View Tab ==================== -->
  <div x-show="activeSubTab === 'store-view'" x-cloak>
    <div class="bg-gray-50 p-0">
      <div class="hidden">
        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-800 mb-1 sm:mb-2">店舗別分配管理</h1>
        <p class="text-gray-600 text-xs sm:text-sm lg:text-base">各店舗に配分された商品を確認・管理します</p>
      </div>

      <!-- Controls -->
      <div class="bg-white rounded-lg shadow-md p-2 sm:p-3 mb-2">
        <div class="space-y-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="distribution-filter-controls flex flex-wrap items-center gap-1.5 text-xs">
              <div class="relative w-full flex-none sm:w-[20rem] lg:w-[24rem]">
                <input type="text" x-model="storeFilterDraft.productSearch" @keydown.enter.prevent="applyStoreFilters()" placeholder="商品コード/商品名" class="distribution-search-input w-full rounded-md border border-gray-300 bg-white px-2 py-1 pr-14 text-xs shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
                <button x-show="storeFilterDraft.productSearch" x-cloak @click="storeFilterDraft.productSearch = ''; applyStoreFilters()" class="absolute rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" style="right: 1.75rem; top: 50%; transform: translateY(-50%);" title="商品検索クリア">
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <button type="button" @click="applyStoreFilters()" :disabled="filterApplying" class="absolute z-10 inline-flex h-5 w-5 items-center justify-center rounded-full border border-indigo-200 bg-indigo-50 text-indigo-700 shadow-sm hover:bg-indigo-100 hover:text-indigo-800 disabled:cursor-wait disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400" style="right: 0.375rem; top: 50%; transform: translateY(-50%);" title="検索実行">
                  <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path stroke-linecap="round" stroke-linejoin="round" d="M20 20l-4.35-4.35"></path></svg>
                </button>
              </div>
              <label class="text-gray-700 font-medium whitespace-nowrap">確定</label>
              <select x-model="storeFilterDraft.confirmStatus" @change="applyStoreFilters()" class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="unconfirmed">未確定のみ</option>
                <option value="confirmed">確定のみ</option>
                <option value="all">全て</option>
              </select>
              <label class="text-gray-700 font-medium whitespace-nowrap">区分</label>
              <select x-model="storeFilterDraft.distributionType" @change="applyStoreFilters()" class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                <option value="all">すべて</option>
                <option value="allocation">通常分配</option>
                <option value="direct">直送分配</option>
              </select>
              <button @click="clearStoreFilters()" class="px-2 py-1 text-xs rounded-md bg-gray-200 hover:bg-gray-300 text-gray-700 whitespace-nowrap">クリア</button>
            </div>
            <button type="button" data-slip-action="store-slip" data-store-slip-output-button="1" @click.prevent.stop="openStoreSlipOutput()"
              :disabled="transferSlipCreating"
              :class="hasStoreSlipOutputTarget() ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : 'bg-gray-300 text-gray-500 cursor-not-allowed'"
              class="font-medium py-1.5 sm:py-2 px-3 sm:px-4 rounded-lg shadow transition text-xs sm:text-sm disabled:cursor-not-allowed"
              x-text="getStoreSlipOutputButtonLabel()">
            </button>
          </div>

          <div class="flex items-center gap-2 overflow-x-auto pb-0.5">
            <div class="flex items-center gap-1.5 shrink-0">
              <label class="text-xs font-medium text-gray-700 whitespace-nowrap">表示モード:</label>
              <div class="flex gap-1">
                <button @click="storeViewMode = 'individual'"
                  :class="storeViewMode === 'individual' ? 'bg-slate-700 text-white ring-1 ring-slate-700' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200 hover:bg-slate-200'"
                  class="px-2 py-1 rounded-md text-xs font-medium transition-colors whitespace-nowrap">店別表示</button>
                <button @click="storeViewMode = 'comparison'"
                  :class="storeViewMode === 'comparison' ? 'bg-slate-700 text-white ring-1 ring-slate-700' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200 hover:bg-slate-200'"
                  class="px-2 py-1 rounded-md text-xs font-medium transition-colors whitespace-nowrap">比較表示</button>
              </div>
            </div>
            <div class="flex min-w-max items-center gap-1">
              <span class="text-xs font-medium text-gray-600 shrink-0">店舗:</span>
              <button x-show="isHqStoreViewWarehouse()" x-cloak @click="toggleAllStores()"
                class="px-2 py-1 rounded-md text-xs font-medium border transition-colors whitespace-nowrap shrink-0"
                :class="selectedStores.length === getStoreDisplayDestinationKeys().length ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50'"
                x-text="selectedStores.length === getStoreDisplayDestinationKeys().length ? '全解除' : '全選択'"></button>
              <template x-for="store in getStoreDisplayDestinations()" :key="store.key">
                <button @click="toggleStore(store.key)"
                  :class="selectedStores.includes(store.key)
                    ? 'bg-blue-600 text-white ring-1 ring-blue-600'
                    : (getStoreProductCount(store.key) > 0
                      ? 'bg-green-50 text-green-700 ring-1 ring-green-300 hover:bg-green-100'
                      : 'bg-gray-50 text-gray-400 ring-1 ring-gray-200 hover:bg-gray-100')"
                  class="inline-flex items-center gap-0.5 px-2 py-1 rounded-md text-xs font-medium transition-all whitespace-nowrap shrink-0">
                  <span x-text="store.name"></span>
                  <template x-if="getStoreProductCount(store.key) > 0">
                    <span class="opacity-70 text-[10px]" x-text="getStoreProductCount(store.key)"></span>
                  </template>
                </button>
              </template>
            </div>
          </div>
        </div>
        </div>
      </div>

      <!-- Individual View -->
      <div x-show="storeViewMode === 'individual'" class="space-y-1.5">
        <template x-if="selectedStores.length > 0">
          <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="overflow-auto" style="max-height: calc(100vh - 285px);">
              <table class="store-distribution-detail-table w-full border-collapse text-xs">
                <thead class="bg-gray-50 border-b border-gray-200">
                  <tr class="divide-x divide-gray-200">
                    <th class="px-2 py-1 text-center font-semibold text-gray-700 whitespace-nowrap">選択</th>
                    <th class="px-2 py-1 text-center font-semibold text-gray-700 whitespace-nowrap">区分</th>
                    <th class="px-2 py-1 text-left font-semibold text-gray-700 whitespace-nowrap">商品コード</th>
                    <th class="px-2 py-1 text-left font-semibold text-gray-700 whitespace-nowrap">商品名</th>
                    <th class="px-2 py-1 text-center font-semibold text-gray-700 whitespace-nowrap">入数</th>
                    <th class="px-2 py-1 text-right font-semibold text-gray-700 whitespace-nowrap">希望</th>
                    <th class="px-2 py-1 text-right font-semibold text-gray-700 whitespace-nowrap">分配</th>
                    <th class="px-2 py-1 text-center font-semibold text-gray-700 whitespace-nowrap">状態</th>
                  </tr>
                </thead>
                <template x-for="storeKey in selectedStores" :key="storeKey">
                  <tbody>
                    <tr class="bg-blue-50 border-t border-blue-100">
                      <td class="px-2 py-1 text-center">
                        <input type="checkbox"
                          :checked="areAllStoreSlipRowsSelected(storeKey)"
                          :disabled="!hasStoreSlipSelectableRows(storeKey)"
                          x-effect="$el.indeterminate = isSomeStoreSlipRowsSelected(storeKey) && !areAllStoreSlipRowsSelected(storeKey)"
                          @change.stop="toggleStoreSlipRows(storeKey, $event.target.checked)"
                          class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                          title="この店舗の表示中の伝票出力対象を全選択" />
                      </td>
                      <td colspan="7" class="px-2 py-1">
                        <div class="flex flex-wrap items-center justify-between gap-1 text-blue-900">
                          <h2 class="text-sm font-bold" x-text="getStoreLabel(storeKey)"></h2>
                          <div class="flex items-center gap-3 text-blue-800 text-xs">
                            <span>商品数: <span class="font-bold" x-text="getStoreFilteredProducts(storeKey).length"></span></span>
                            <span>配分合計: <span class="font-bold" x-text="getStoreFilteredProducts(storeKey).reduce((s,p) => s + p.alloc, 0)"></span></span>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <template x-for="(product, idx) in getStoreFilteredProducts(storeKey)" :key="product.rowId + '-' + storeKey">
                      <tr :class="product.checked ? 'bg-emerald-50 hover:bg-emerald-100' : (product.alloc <= 0 ? 'bg-amber-50 hover:bg-amber-100' : 'hover:bg-gray-50')" class="divide-x divide-gray-200 border-t border-slate-400">
                        <td class="px-2 py-1 text-center">
                          <input type="checkbox"
                            data-store-slip-row-checkbox="1"
                            :data-row-key="product.rowKey"
                            :data-store-key="storeKey"
                            :checked="isStoreSlipRowSelected(product.rowKey, storeKey)"
                            @change.stop="setStoreSlipRowSelected(product.rowKey, storeKey, $event.target.checked)"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                            title="伝票出力対象" />
                        </td>
                        <td class="px-2 py-1 text-center whitespace-nowrap">
                          <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                            :class="product.sourceType === 'direct' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700'"
                            x-text="product.sourceLabel"></span>
                        </td>
                        <td class="px-2 py-1 font-mono text-xs whitespace-nowrap" x-text="product.productCode"></td>
                        <td class="px-2 py-1" x-text="product.name"></td>
                        <td class="px-2 py-1 text-center" x-text="product.unitsPerCase"></td>
                        <td class="px-2 py-1 text-right text-red-700 font-semibold" x-text="product.wish"></td>
                        <td class="px-2 py-1 text-right font-semibold" :class="product.checked ? 'bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-200' : (product.alloc <= 0 ? 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-200' : 'text-green-700')" x-text="product.alloc"></td>
                        <td class="px-2 py-1 text-center">
                          <span x-show="product.checked" class="inline-block px-2 py-0.5 bg-green-100 text-green-800 text-[10px] font-semibold rounded-full">確定済</span>
                          <span x-show="!product.checked" class="inline-block px-2 py-0.5 bg-yellow-100 text-yellow-800 text-[10px] font-semibold rounded-full">未確定</span>
                        </td>
                      </tr>
                    </template>
                    <template x-if="getStoreFilteredProducts(storeKey).length === 0">
                      <tr>
                        <td colspan="8" class="px-4 py-4 text-center text-gray-500 text-xs">配分商品がありません</td>
                      </tr>
                    </template>
                  </tbody>
                </template>
              </table>
            </div>
          </div>
        </template>
        <template x-if="selectedStores.length === 0">
          <div class="bg-white rounded-lg shadow-md p-6 text-center text-gray-500 text-sm">店舗を選択してください</div>
        </template>
      </div>

      <!-- Comparison View -->
      <div x-show="storeViewMode === 'comparison' && selectedStores.length > 0">
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
          <div class="overflow-auto" style="max-height: calc(100vh - 285px);">
            <table class="store-distribution-detail-table w-full border-collapse text-xs">
              <thead class="bg-gray-50 border-b-2 border-gray-200 sticky top-0 z-20">
                <tr>
                  <th class="border-r border-gray-200 px-2 py-1.5 sm:py-2 text-center font-semibold text-gray-700 sticky bg-gray-50 z-30" style="left: 0; width: 4.5rem; min-width: 4.5rem;">区分</th>
                  <th class="border-r border-gray-200 px-2 py-1.5 sm:py-2 text-left font-semibold text-gray-700 sticky bg-gray-50 z-30" style="left: 4.5rem; width: 7rem; min-width: 7rem;">商品コード</th>
                  <th class="border-r border-gray-200 px-2 sm:px-3 py-1.5 sm:py-2 text-left font-semibold text-gray-700 sticky bg-gray-50 z-30" style="left: 11.5rem; min-width: 14rem;">商品名</th>
                  <template x-for="storeKey in selectedStores" :key="storeKey">
                    <th class="store-comparison-store-col border-l border-gray-200 px-0 py-0 text-center font-semibold text-gray-700">
                      <span class="block whitespace-nowrap px-1 text-[10px] leading-tight sm:text-[11px]" :title="getStoreLabel(storeKey)" x-text="getStoreLabel(storeKey)"></span>
                      <div class="mt-1 grid grid-cols-2 border-t border-gray-200 text-[10px] sm:text-xs font-normal">
                        <span class="bg-red-50 px-0.5 py-0.5 text-red-700">希望</span>
                        <span class="border-l border-gray-200 bg-green-50 px-0.5 py-0.5 text-green-700">分配</span>
                      </div>
                    </th>
                  </template>
                </tr>
              </thead>
              <tbody>
                <template x-for="productCode in getComparisonProductCodes()" :key="productCode">
                  <tr :class="isComparisonProductConfirmed(productCode) ? 'bg-emerald-50 hover:bg-emerald-100' : 'hover:bg-gray-50'" class="border-t border-slate-400">
                    <td class="border-r border-gray-200 bg-white px-2 py-1.5 sm:py-2 sticky z-10 text-center align-middle" style="left: 0; width: 4.5rem; min-width: 4.5rem;">
                        <span class="inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                          :class="getComparisonProductSourceType(productCode) === 'direct' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700'"
                          x-text="getComparisonProductSourceLabel(productCode)"></span>
                    </td>
                    <td class="border-r border-gray-200 bg-white px-2 py-1.5 sm:py-2 sticky z-10 align-middle" style="left: 4.5rem; width: 7rem; min-width: 7rem;">
                      <span class="font-mono text-[11px] font-semibold text-gray-600 whitespace-nowrap" x-text="getComparisonProductDisplayCode(productCode) || '-'"></span>
                    </td>
                    <td class="border-r border-gray-200 bg-white px-2 sm:px-3 py-1.5 sm:py-2 sticky z-10 align-middle" style="left: 11.5rem; min-width: 14rem;">
                      <div class="whitespace-nowrap" :title="getComparisonProductName(productCode)" x-text="getComparisonProductName(productCode)"></div>
                    </td>
                    <template x-for="storeKey in selectedStores" :key="storeKey">
                      <td :class="getComparisonCellStateClass(productCode, storeKey)" class="border-l border-gray-200 p-0 text-center align-middle">
                        <template x-if="getComparisonCell(productCode, storeKey)">
                          <div class="grid min-h-[2.25rem] grid-cols-2 text-center font-semibold">
                            <div class="flex items-center justify-end px-1 py-1 text-red-700" x-text="getComparisonCell(productCode, storeKey).wish"></div>
                            <div class="flex items-center justify-end border-l border-gray-200 px-1 py-1" :class="getComparisonCell(productCode, storeKey).checked ? 'bg-emerald-100 text-emerald-800' : (getComparisonCell(productCode, storeKey).alloc <= 0 ? 'bg-amber-100 text-amber-800' : 'text-green-700')" x-text="getComparisonCell(productCode, storeKey).alloc"></div>
                          </div>
                        </template>
                        <template x-if="!getComparisonCell(productCode, storeKey)">
                          <span class="text-gray-300">-</span>
                        </template>
                      </td>
                    </template>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div x-show="storeViewMode === 'comparison' && selectedStores.length === 0">
        <div class="bg-white rounded-lg shadow-md p-12 text-center text-gray-500">店舗を選択してください</div>
      </div>
    </div>

  <!-- ==================== 明細備考入力ポップアップ ==================== -->
  <div x-show="memoPopupRowId !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/30" @click="memoPopupRowId = null"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-sm sm:max-w-[384px] flex flex-col" @click.stop>
      <div class="px-4 py-3 border-b bg-indigo-600 rounded-t-lg flex items-center justify-between">
        <span class="text-white font-semibold text-sm">明細備考入力</span>
        <button @click="memoPopupRowId = null" class="text-white/70 hover:text-white text-lg leading-none">&times;</button>
      </div>
      <div class="p-4">
        <textarea x-model="memoPopupText" rows="4" class="w-full rounded-md border-gray-300 border px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="明細備考を入力..."></textarea>
      </div>
      <div class="px-4 pb-4 flex justify-end gap-2">
        <button @click="memoPopupRowId = null" class="px-4 py-1.5 rounded-md border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">キャンセル</button>
        <button @click="saveMemoPopup()" class="px-4 py-1.5 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">保存</button>
      </div>
    </div>
  </div>

  <!-- ==================== ロット条件ポップアップ（本部分配） ==================== -->
  <template x-if="lotPopoverRowId !== null">
    <div class="fixed inset-0 z-50" @click="lotPopoverRowId = null">
      <div class="fixed bg-white border border-gray-300 rounded-xl shadow-2xl p-4 sm:p-5 w-64 sm:w-72 text-sm max-w-[90vw]" :style="(lotPopoverPos.anchor === 'above' ? 'bottom:' + (window.innerHeight - lotPopoverPos.top) + 'px;' : 'top:' + lotPopoverPos.top + 'px;') + ' left:' + lotPopoverPos.left + 'px; transform: translateX(-50%);'" @click.stop>
        <template x-for="row in rows.filter(r => r.id === lotPopoverRowId)" :key="row.id">
          <div>
            <div class="font-bold text-gray-800 mb-3 pb-2 border-b text-base">ロット条件</div>
            <div class="space-y-2">
              <div class="flex justify-between"><span class="text-gray-600">ロット数:</span><span class="font-mono font-semibold" x-text="row.lot || 0"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">入数:</span><span class="font-mono font-semibold" x-text="row.unitsPerCase || 1"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">ロット入数:</span><span class="font-mono font-semibold" x-text="(row.lot || 0) * (row.unitsPerCase || 1)"></span></div>
              <div class="flex justify-between mt-2 pt-2 border-t"><span class="text-gray-600">希望合計:</span><span class="font-mono font-semibold" :class="calcWishTotal(row) > (row.lot || 0) * (row.unitsPerCase || 1) ? 'text-red-600' : 'text-green-600'" x-text="calcWishTotal(row)"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">分配合計:</span><span class="font-mono font-semibold" x-text="calcAllocTotal(row)"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">残:</span><span class="font-mono font-semibold" :class="((row.lot || 0) * (row.unitsPerCase || 1) - calcAllocTotal(row)) < 0 ? 'text-red-600' : ''" x-text="(row.lot || 0) * (row.unitsPerCase || 1) - calcAllocTotal(row)"></span></div>
              <div class="mt-3 pt-3 border-t border-amber-200">
                <div class="text-xs font-semibold text-gray-600 mb-1">備考</div>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-gray-700 whitespace-pre-wrap max-h-28 overflow-y-auto" x-text="getItemContractorNote(row) || '備考は登録されていません'"></div>
              </div>
            </div>
            <button @click="lotPopoverRowId = null" class="mt-3 w-full py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium">閉じる</button>
          </div>
        </template>
      </div>
    </div>
  </template>

  <!-- ==================== ロット条件ポップアップ（直送分配） ==================== -->
  <template x-if="directLotPopoverRowId !== null">
    <div class="fixed inset-0 z-50" @click="directLotPopoverRowId = null">
      <div class="fixed bg-white border border-gray-300 rounded-xl shadow-2xl p-4 sm:p-5 w-64 sm:w-72 text-sm max-w-[90vw]" :style="(directLotPopoverPos.anchor === 'above' ? 'bottom:' + (window.innerHeight - directLotPopoverPos.top) + 'px;' : 'top:' + directLotPopoverPos.top + 'px;') + ' left:' + directLotPopoverPos.left + 'px; transform: translateX(-50%);'" @click.stop>
        <template x-for="row in directRows.filter(r => r.id === directLotPopoverRowId)" :key="row.id">
          <div>
            <div class="font-bold text-gray-800 mb-3 pb-2 border-b text-base">ロット条件</div>
            <div class="space-y-2">
              <div class="flex justify-between"><span class="text-gray-600">ロット数:</span><span class="font-mono font-semibold" x-text="row.lot || 0"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">入数:</span><span class="font-mono font-semibold" x-text="row.unitsPerCase || 1"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">ロット入数:</span><span class="font-mono font-semibold" x-text="(row.lot || 0) * (row.unitsPerCase || 1)"></span></div>
              <div class="flex justify-between mt-2 pt-2 border-t"><span class="text-gray-600">希望合計:</span><span class="font-mono font-semibold" :class="calcDirectWishTotal(row) > (row.lot || 0) * (row.unitsPerCase || 1) ? 'text-red-600' : 'text-green-600'" x-text="calcDirectWishTotal(row)"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">分配合計:</span><span class="font-mono font-semibold" x-text="calcDirectAllocTotal(row)"></span></div>
              <div class="flex justify-between"><span class="text-gray-600">残:</span><span class="font-mono font-semibold" :class="((row.lot || 0) * (row.unitsPerCase || 1) - calcDirectAllocTotal(row)) < 0 ? 'text-red-600' : ''" x-text="(row.lot || 0) * (row.unitsPerCase || 1) - calcDirectAllocTotal(row)"></span></div>
              <div class="mt-3 pt-3 border-t border-amber-200">
                <div class="text-xs font-semibold text-gray-600 mb-1">備考</div>
                <div class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-gray-700 whitespace-pre-wrap max-h-28 overflow-y-auto" x-text="getItemContractorNote(row) || '備考は登録されていません'"></div>
              </div>
            </div>
            <button @click="directLotPopoverRowId = null" class="mt-3 w-full py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium">閉じる</button>
          </div>
        </template>
      </div>
    </div>
  </template>

  <!-- ==================== 伝票備考入力ポップアップ ==================== -->
  <!-- ==================== 伝票再出力対象選択 ==================== -->
  <div x-show="reprintSelectionVisible" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/40" @click="reprintSelectionVisible = false"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col overflow-hidden" @click.stop>
      <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
        <div>
          <div class="text-sm font-semibold" x-text="reprintSelectionTitle || '伝票再出力対象を選択'"></div>
          <div x-show="reprintSelectionDescription" x-cloak class="mt-0.5 text-xs text-white/70" x-text="reprintSelectionDescription"></div>
        </div>
        <button @click="reprintSelectionVisible = false" class="rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white" title="閉じる">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs text-gray-600">
        <div class="flex flex-wrap items-center gap-2">
          <span>対象候補: <span class="font-semibold text-gray-900" x-text="getFilteredReprintRows().length"></span> 行</span>
          <span>選択中: <span class="font-semibold text-indigo-700" x-text="getSelectedReprintRows().length"></span> 行</span>
          <span>店舗: <span class="font-semibold text-emerald-700" x-text="reprintSelectedStoreKeys.length"></span> 店舗</span>
          <button @click="toggleAllReprintRows(true)" class="ml-auto rounded-md border border-gray-300 bg-white px-2 py-1 text-xs hover:bg-gray-100">全選択</button>
          <button @click="toggleAllReprintRows(false)" class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs hover:bg-gray-100">全解除</button>
        </div>
      </div>
      <div class="border-b border-gray-200 bg-white px-4 py-2">
        <div class="flex items-center gap-2">
          <label class="whitespace-nowrap text-xs font-semibold text-gray-700">商品検索</label>
          <input
            type="search"
            x-model.debounce.150ms="reprintSearch"
            @input.debounce.180ms="normalizeReprintSelection()"
            class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
            placeholder="商品CD / JANコード"
          />
          <button
            x-show="reprintSearch"
            @click="reprintSearch = ''; normalizeReprintSelection();"
            type="button"
            class="rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-gray-100"
          >クリア</button>
        </div>
      </div>
      <div class="border-b border-gray-200 bg-white px-3 py-1.5">
        <div class="mb-1 flex items-center gap-1.5 text-[11px] text-gray-600">
          <span class="font-semibold text-gray-800">出力店舗</span>
          <button @click="toggleAllReprintStores(true)" class="ml-auto rounded border border-gray-300 bg-white px-1.5 py-0.5 text-[11px] hover:bg-gray-100">全選択</button>
          <button @click="toggleAllReprintStores(false)" class="rounded border border-gray-300 bg-white px-1.5 py-0.5 text-[11px] hover:bg-gray-100">全解除</button>
        </div>
        <div class="grid max-h-20 grid-cols-3 gap-0.5 overflow-auto text-[11px] sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
          <template x-for="dest in getReprintCandidateStores()" :key="dest.key">
            <label class="flex min-w-0 items-center gap-1 rounded border border-gray-200 px-1.5 py-0.5 leading-tight hover:bg-indigo-50">
              <input type="checkbox" class="h-3 w-3 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                :checked="isReprintStoreSelected(dest)"
                @change="setReprintStoreSelected(dest, $event.target.checked)" />
              <span class="truncate" :title="dest.code + ' ' + dest.name" x-text="'[' + dest.code + ']' + dest.name"></span>
            </label>
          </template>
        </div>
      </div>
      <div class="flex-1 overflow-auto">
        <table class="w-full min-w-[900px] text-xs">
          <thead class="sticky top-0 z-10 bg-neutral-50 text-neutral-500">
            <tr class="divide-x divide-gray-200 border-b border-gray-200">
              <th class="w-12 p-2 text-center">選択</th>
              <th class="w-24 p-2 text-left">発注日</th>
              <th class="w-28 p-2 text-left">店舗</th>
              <th class="w-20 p-2 text-left">区分</th>
              <th class="w-28 p-2 text-left">商品CD</th>
              <th class="p-2 text-left">商品名</th>
              <th class="w-20 p-2 text-right">分計</th>
              <th class="w-40 p-2 text-left">生成日時</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template x-for="row in getFilteredReprintRows()" :key="row.id">
              <tr class="hover:bg-indigo-50/40">
                <td class="p-2 text-center">
                  <input type="checkbox"
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                    :checked="isReprintRowSelected(row)"
                    :disabled="!isReprintRowOutputSelectable(row)"
                    :title="!isReprintRowOutputSelectable(row) ? '商品情報がないため出力対象外です' : ''"
                    @change="setReprintRowSelected(row, $event.target.checked)" />
                </td>
                <td class="p-2 whitespace-nowrap" x-text="row.orderDate || '-'"></td>
                <td class="p-2 whitespace-nowrap" x-text="getReprintRowStoreLabel(row)"></td>
                <td class="p-2 whitespace-nowrap">
                  <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="getReprintRowConfirmStatusClass(row)" x-text="getReprintRowConfirmStatusLabel(row)"></span>
                </td>
                <td class="p-2 font-mono whitespace-nowrap" x-text="row.productCode || '-'"></td>
                <td class="p-2">
                  <div class="truncate" :title="row.name || '-'" x-text="row.name || '-'"></div>
                </td>
                <td class="p-2 text-right font-semibold" x-text="calcAllocTotal(row)"></td>
                <td class="p-2 whitespace-nowrap text-gray-500" x-text="row.warehouseTransferCreatedAt || row.transferSlipCreatedAt || '-'"></td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end gap-2 border-t border-gray-200 bg-white px-4 py-3">
        <button @click="reprintSelectionVisible = false" class="rounded-md border border-gray-300 px-4 py-1.5 text-sm text-gray-600 hover:bg-gray-50">キャンセル</button>
        <button type="button" data-slip-action="reprint-confirm" @click.prevent.stop="openSlipRemarkForSelectedReprint()" :class="(getSelectedReprintRows().length === 0 || reprintSelectedStoreKeys.length === 0) ? 'bg-gray-300 text-gray-500 hover:bg-gray-400 hover:text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white'" class="rounded-md px-4 py-1.5 text-sm font-medium" x-text="reprintSelectionConfirmLabel || '再出力へ'"></button>
      </div>
    </div>
  </div>

  <div x-show="slipLayoutSelectionVisible" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/40" @click="closeSlipLayoutSelection()"></div>
    <div class="relative w-full max-w-xl overflow-hidden rounded-lg bg-white shadow-2xl" @click.stop>
      <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
        <div>
          <div class="text-sm font-semibold">伝票レイアウトを選択</div>
          <div class="mt-0.5 text-xs text-white/70">出力する移動伝票の形式を選択してください。</div>
        </div>
        <button @click="closeSlipLayoutSelection()" class="rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white" title="閉じる">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="grid gap-3 p-4 sm:grid-cols-2">
        <button type="button" @click="printSelectedStoreSlipLayout('checklist')" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-4 text-left shadow-sm hover:border-emerald-400 hover:bg-emerald-100">
          <div class="text-sm font-semibold text-emerald-900">2枚の移動伝票</div>
          <div class="mt-1 text-xs leading-5 text-emerald-700">入庫用・出庫用をそれぞれ出力します。</div>
        </button>
        <button type="button" @click="printSelectedStoreSlipLayout('legacy')" class="rounded-lg border border-slate-200 bg-white px-4 py-4 text-left shadow-sm hover:border-slate-400 hover:bg-slate-50">
          <div class="text-sm font-semibold text-slate-900">1枚の移動伝票</div>
          <div class="mt-1 text-xs leading-5 text-slate-600">以前の1枚レイアウトで出力します。</div>
        </button>
      </div>
      <div class="flex justify-end border-t border-gray-200 px-4 py-3">
        <button @click="closeSlipLayoutSelection()" class="rounded-md border border-gray-300 px-4 py-1.5 text-sm text-gray-600 hover:bg-gray-50">キャンセル</button>
      </div>
    </div>
  </div>

  <div x-show="slipRemarkVisible" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/30" @click="closeSlipRemarkPopup()"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-[620px] max-h-[88vh] flex flex-col overflow-hidden" @click.stop>
      <div class="px-4 py-3 border-b bg-teal-600 rounded-t-lg flex items-center justify-between">
        <span class="text-white font-semibold text-sm">出力設定</span>
        <button @click="closeSlipRemarkPopup()" class="text-white/70 hover:text-white text-lg leading-none">&times;</button>
      </div>
      <div class="p-4 space-y-3 overflow-auto">
        <div x-show="slipRemarkMode === 'slip'">
          <label class="block text-xs font-medium text-gray-600 mb-1">伝票備考（任意）</label>
          <textarea x-model="slipRemarkText" rows="3" class="w-full rounded-md border-gray-300 border px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="伝票備考を入力..."></textarea>
        </div>
        <div x-show="slipRemarkMode === 'slip' && slipRemarkAllowDetailMemo" x-cloak>
          <label class="block text-xs font-medium text-gray-600 mb-1">明細備考（任意）</label>
          <div class="max-h-72 overflow-auto rounded-md bg-gray-50 px-2">
            <template x-for="detail in slipRemarkDetailRows" :key="detail.id">
              <div class="border-b border-gray-200 py-2 last:border-b-0">
                <div class="mb-1 flex min-w-0 items-center gap-2 text-xs text-gray-600">
                  <span class="shrink-0 font-mono font-semibold text-gray-900" x-text="detail.productCode || '-'"></span>
                  <span class="truncate" :title="detail.name || ''" x-text="detail.name || '-'"></span>
                </div>
                <textarea x-model="detail.memo" rows="2" class="w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs shadow-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-500" placeholder="明細備考を入力..."></textarea>
              </div>
            </template>
            <div x-show="slipRemarkDetailRows.length === 0" x-cloak class="py-3 text-center text-xs text-gray-500">明細備考を入力できる明細がありません。</div>
          </div>
        </div>
        <div x-show="slipRemarkMode === 'request'">
          <label class="block text-xs font-medium text-gray-600 mb-1">コメント（任意）</label>
          <textarea x-model="slipManagementComment" rows="3" class="w-full rounded-md border-gray-300 border px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500" placeholder="コメントを入力..."></textarea>
        </div>
      </div>
      <div class="px-4 pb-4 flex justify-end gap-2">
        <button @click="closeSlipRemarkPopup()" class="px-4 py-1.5 rounded-md border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">キャンセル</button>
        <button type="button" data-slip-action="slip-remark-submit" @click.prevent.stop="submitSlipRemark()" class="px-4 py-1.5 rounded-md bg-teal-600 text-white text-sm font-medium hover:bg-teal-700">出力</button>
      </div>
    </div>
  </div>

  <!-- ==================== Product Search Modal ==================== -->
  <div x-show="searchOpenForRow !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/50" @click="closeProductSearch()"></div>
    <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-7xl max-h-[90vh] flex flex-col overflow-hidden">
      <div class="flex items-center gap-3 bg-slate-800 px-4 py-3">
        <div class="flex h-8 w-8 items-center justify-center rounded-md bg-white/10 text-white">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
        </div>
        <div class="text-base sm:text-lg font-semibold text-white">商品追加</div>
        <button class="ml-auto rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white transition"
          @click="closeProductSearch()" title="閉じる">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="flex-1 overflow-auto p-3 sm:p-4 space-y-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-2">
          <div class="text-xs font-semibold text-gray-700">商品検索フィルタ</div>
          <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <label class="block">
              <span class="mb-1 block text-xs text-gray-500">商品</span>
              <input x-ref="productSearchInput" type="text" x-model="productSearchQuery"
                @input.debounce.400ms="loadProductMaster({ autoSelectJan: true })"
                @keydown.enter.prevent="loadProductMaster({ autoSelectJan: true, allowShortJan: true })"
                placeholder="商品コード・商品名・JAN・単品コード・自社コード"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-gray-500">発注先</span>
              <input type="text" x-model="productOrderToQuery"
                @input.debounce.400ms="loadProductMaster()"
                @keydown.enter.prevent="loadProductMaster()"
                placeholder="発注先CD・発注先名"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-gray-500">仕入先</span>
              <input type="text" x-model="productSupplierQuery"
                @input.debounce.400ms="loadProductMaster()"
                @keydown.enter.prevent="loadProductMaster()"
                placeholder="仕入先CD・仕入先名"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" />
            </label>
          </div>
          <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <label class="block">
              <span class="mb-1 block text-xs text-gray-500">大分類</span>
              <select x-model="productLargeCategoryId"
                @change="productMiddleCategoryId=''; productSmallCategoryId=''; loadProductMaster()"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">全て</option>
                <template x-for="category in getProductCategoryOptions(1)" :key="'product-category-1-' + category.id">
                  <option :value="String(category.id)" x-text="formatProductCategoryLabel(category)"></option>
                </template>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-gray-500">中分類</span>
              <select x-model="productMiddleCategoryId"
                @change="productSmallCategoryId=''; loadProductMaster()"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">全て</option>
                <template x-for="category in getProductCategoryOptions(2, productLargeCategoryId)" :key="'product-category-2-' + category.id">
                  <option :value="String(category.id)" x-text="formatProductCategoryLabel(category)"></option>
                </template>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-gray-500">小分類</span>
              <select x-model="productSmallCategoryId"
                @change="loadProductMaster()"
                class="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">全て</option>
                <template x-for="category in getProductCategoryOptions(3, productMiddleCategoryId)" :key="'product-category-3-' + category.id">
                  <option :value="String(category.id)" x-text="formatProductCategoryLabel(category)"></option>
                </template>
              </select>
            </label>
          </div>
          <div class="flex justify-end gap-1">
            <button type="button" @click="loadProductMaster({ autoSelectJan: true, allowShortJan: true })"
              class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">検索</button>
            <button type="button"
              @click="if(productSearchAbortController){ productSearchAbortController.abort(); productSearchAbortController = null; } productSearchQuery=''; productOrderToQuery=''; productSupplierQuery=''; productLargeCategoryId=''; productMiddleCategoryId=''; productSmallCategoryId=''; productMaster=[]; productSelectedCodes=[]; productSearchLoaded=false; productSearchLoading=false; productSearchError='';"
              class="rounded bg-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-300">リセット</button>
          </div>
        </div>

        <div class="flex items-center justify-between">
          <div class="text-xs text-gray-500">
            検索結果: <span class="font-bold text-gray-700" x-text="productMaster.length"></span>件
            <span x-show="productSelectedCodes.length > 0" class="ml-2 font-bold text-blue-600">
              （<span x-text="productSelectedCodes.length"></span>件選択中）
            </span>
          </div>
        </div>

        <div x-show="productSearchLoading" class="flex items-center justify-center py-4">
          <svg class="mr-2 h-5 w-5 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          <span class="text-sm text-gray-500">検索中...</span>
        </div>

        <div x-show="!productSearchLoading" class="product-search-scroll overflow-auto rounded-lg border border-gray-200" style="max-height: 420px">
          <table class="product-search-table w-full min-w-[1900px] table-fixed text-sm">
            <colgroup>
              <col style="width: 54px" />
              <col style="width: 120px" />
              <col style="width: 260px" />
              <col style="width: 110px" />
              <col style="width: 88px" />
              <col style="width: 220px" />
              <col style="width: 88px" />
              <col style="width: 220px" />
              <col style="width: 60px" />
              <col style="width: 70px" />
              <col style="width: 70px" />
              <col style="width: 90px" />
              <col style="width: 95px" />
              <col style="width: 70px" />
              <col style="width: 70px" />
              <col style="width: 70px" />
            </colgroup>
            <thead class="sticky top-0 z-10 bg-gray-100">
              <tr class="divide-x divide-gray-200">
                <th class="product-search-sticky product-search-sticky-select px-1.5 py-1 text-center text-xs font-medium text-gray-500">選択</th>
                <th class="product-search-sticky product-search-sticky-code px-1.5 py-1 text-left text-xs font-medium text-gray-500">商品コード</th>
                <th class="product-search-sticky product-search-sticky-name px-1.5 py-1 text-left text-xs font-medium text-gray-500">商品名</th>
                <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500">棚番</th>
                <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500">発注先CD</th>
                <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500">発注先</th>
                <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500">仕入先CD</th>
                <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500">仕入先</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">入数</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">発注点</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">理論</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">入荷予定</th>
                <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500">最終発注日</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">1週</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">2週</th>
                <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500">3週</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="(p, index) in productMaster" :key="p.candidateKey || p.code">
                <tr :class="shouldBlockDirectHqOrder(p) ? 'bg-amber-50' : (productSelectedCodes.includes(String(p.candidateKey || p.code)) ? 'bg-green-50' : (index % 2 === 0 ? 'bg-white' : 'bg-blue-50/30'))"
                  class="divide-x divide-gray-200 border-t border-gray-200 hover:bg-blue-50">
                  <td class="product-search-sticky product-search-sticky-select px-1.5 py-1 text-center">
                    <input type="checkbox" :value="p.candidateKey || p.code" x-model="productSelectedCodes"
                      :disabled="shouldBlockDirectHqOrder(p)"
                      :title="shouldBlockDirectHqOrder(p) ? getDirectHqOrderBlockedMessage() : ''"
                      class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-300" />
                    <div x-show="shouldBlockDirectHqOrder(p)" x-cloak class="mt-0.5 text-[10px] font-semibold text-amber-700">対象外</div>
                  </td>
                  <td class="product-search-sticky product-search-sticky-code px-1.5 py-1"><span class="font-mono text-xs text-gray-900" x-text="p.code"></span></td>
                  <td class="product-search-sticky product-search-sticky-name px-1.5 py-1"><span class="block truncate text-xs text-gray-700" :title="p.name" x-text="p.name"></span></td>
                  <td class="px-1.5 py-1"><span class="block truncate font-mono text-xs text-gray-600" :title="p.shelfLocation || '-'" x-text="p.shelfLocation || '-'"></span></td>
                  <td class="px-1.5 py-1"><span class="font-mono text-xs text-gray-600" x-text="p.orderToCode || '-'"></span></td>
                  <td class="px-1.5 py-1">
                    <span class="block whitespace-normal break-words text-xs leading-tight text-gray-600" :title="p.orderTo || '-'" x-text="p.orderTo || '-'"></span>
                    <span x-show="_isHqOrderCodeOrName(p.orderToCode, p.orderTo) && _searchIsDirectTab" x-cloak class="mt-0.5 inline-block rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-amber-700">直送分配では本部発注不可</span>
                  </td>
                  <td class="px-1.5 py-1"><span class="font-mono text-xs text-gray-500" x-text="p.supplierCode || '-'"></span></td>
                  <td class="px-1.5 py-1">
                    <span class="block whitespace-normal break-words text-xs leading-tight text-gray-500" :title="p.supplier || '-'" x-text="p.supplier || '-'"></span>
                    <span x-show="_isHqOrderCodeOrName(p.supplierCode, p.supplier) && _searchIsDirectTab" x-cloak class="mt-0.5 inline-block rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-amber-700">直送分配では本部発注不可</span>
                  </td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs text-gray-700" x-text="p.unitsPerCase"></span></td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs text-gray-700" x-text="p.orderPoint"></span></td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs font-semibold text-gray-900" :title="getProductStockSummaryTitle(p)" x-text="p.stock.theoreticalQuantity"></span></td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs text-gray-700" :title="getProductIncomingTitle(p)" x-text="p.stock.incomingQuantity"></span></td>
                  <td class="px-1.5 py-1"><span class="font-mono text-xs text-gray-600" x-text="p.lastOrderDate || '-'"></span></td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs text-gray-700" x-text="p.salesWeek1"></span></td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs text-gray-700" x-text="p.salesWeek2"></span></td>
                  <td class="px-1.5 py-1 text-right"><span class="font-mono text-xs text-gray-700" x-text="p.salesWeek3"></span></td>
                </tr>
              </template>
            </tbody>
          </table>
          <div x-show="!productSearchLoaded && !productSearchLoading" x-cloak class="product-search-state px-4 py-8 text-center text-sm text-gray-400">
            <svg class="mb-2 h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
            </svg>
            <span>商品・発注先・仕入先・分類のいずれかを入力してください</span>
          </div>
          <div x-show="productSearchError" x-cloak class="product-search-state px-4 py-6 text-center text-sm text-red-600" x-text="productSearchError"></div>
          <div x-show="productSearchLoaded && !productSearchLoading && !productSearchError && productMaster.length === 0" x-cloak class="product-search-state px-4 py-6 text-center text-sm text-gray-400">該当する商品が見つかりませんでした</div>
        </div>

        <div class="flex items-center justify-end text-xs text-gray-500">
          <span x-text="productSelectedCodes.length"></span>件の商品を追加予定
        </div>
      </div>
      <div class="flex items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3">
        <button class="rounded-md border border-gray-300 bg-white px-4 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
          @click="closeProductSearch()">追加せず閉じる</button>
        <button class="rounded-md bg-red-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-red-700 disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed"
          :disabled="productSelectedCodes.length === 0"
          @click="selectCheckedProducts()"
          x-text="productSelectedCodes.length > 0 ? '商品を追加 (' + productSelectedCodes.length + ')' : '商品を追加'"></button>
      </div>
    </div>
  </div>

  <!-- ==================== Arrival Schedule Modal ==================== -->
  <div x-show="arrivalScheduleProduct !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/50" @click="arrivalScheduleProduct = null"></div>
    <div class="relative bg-white rounded-lg shadow-2xl w-full max-w-[880px] max-h-[85vh] sm:max-h-[600px] flex flex-col">
      <div class="px-3 py-1.5 border-b flex items-center gap-2 bg-gradient-to-r from-green-600 to-green-500">
        <div class="text-sm sm:text-base font-bold text-white">入荷予定</div>
        <div class="ml-auto"></div>
        <button class="rounded-md bg-white/20 hover:bg-white/30 px-2 py-0.5 text-xs text-white font-medium transition-colors"
          @click="arrivalScheduleProduct = null">閉じる</button>
      </div>
      <div class="px-3 py-2 border-b bg-gray-50">
        <div class="flex flex-wrap items-start gap-3 sm:gap-5">
          <div>
            <div class="text-xs text-gray-600 mb-0.5">商品コード</div>
            <div class="font-mono text-sm font-semibold text-gray-900" x-text="arrivalScheduleProduct"></div>
          </div>
          <div>
            <div class="text-xs text-gray-600 mb-0.5">JANコード</div>
            <div class="font-mono text-sm font-semibold text-gray-900" x-text="getProductJan(arrivalScheduleProduct) || '-'"></div>
          </div>
        </div>
        <div class="text-xs text-gray-600 mt-1.5 mb-0.5">商品名</div>
        <div class="text-sm font-semibold text-gray-900" x-text="getProductName(arrivalScheduleProduct)"></div>
      </div>
      <div class="flex-1 overflow-auto">
        <table class="w-full min-w-[960px] text-sm">
          <thead class="sticky top-0 bg-gray-100 border-b-2 border-gray-300">
            <tr>
              <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold text-gray-700">入荷予定日</th>
              <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold text-gray-700">残入荷予定数</th>
              <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold text-gray-700">発注先</th>
              <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold text-gray-700">仕入先</th>
              <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold text-gray-700">倉庫</th>
              <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold text-gray-700">状態</th>
              <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold text-gray-700">備考</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="(item, idx) in getArrivalSchedule(arrivalScheduleProduct)" :key="item.id || idx">
              <tr class="hover:bg-green-50 transition-colors">
                <td class="whitespace-nowrap px-2 py-1.5 font-mono" x-text="item.arrivalDate"></td>
                <td class="px-2 py-1.5 text-right font-semibold" x-text="formatArrivalQuantity(item)"></td>
                <td class="px-2 py-1.5" x-text="formatArrivalOrderTo(item)"></td>
                <td class="px-2 py-1.5" x-text="formatArrivalSupplier(item)"></td>
                <td class="px-2 py-1.5 whitespace-nowrap" x-text="formatArrivalWarehouse(item)"></td>
                <td class="px-2 py-1.5 whitespace-nowrap" x-text="formatArrivalStatus(item)"></td>
                <td class="px-2 py-1.5 text-gray-600" x-text="formatArrivalDetail(item)"></td>
              </tr>
            </template>
            <template x-if="arrivalScheduleLoading">
              <tr><td class="p-4 text-center text-gray-500 text-sm" colspan="7">読み込み中...</td></tr>
            </template>
            <template x-if="arrivalScheduleError">
              <tr><td class="p-4 text-center text-red-600 text-sm" colspan="7" x-text="arrivalScheduleError"></td></tr>
            </template>
            <template x-if="arrivalScheduleLoaded && !arrivalScheduleLoading && !arrivalScheduleError && getArrivalSchedule(arrivalScheduleProduct).length === 0">
              <tr><td class="p-4 text-center text-gray-500 text-sm" colspan="7">入荷予定がありません</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

    @push('scripts')
        @once
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
        @endonce

        @php
            $distributionProductCategories = \App\Models\Sakemaru\ItemCategory::query()
                ->where('client_id', (int) config('app.client_id'))
                ->where('is_active', true)
                ->whereIn('depth', [1, 2, 3])
                ->orderBy('depth')
                ->orderBy('code')
                ->get(['id', 'parent_id', 'depth', 'code', 'name'])
                ->map(fn ($category): array => [
                    'id' => (int) $category->id,
                    'parentId' => $category->parent_id ? (int) $category->parent_id : null,
                    'depth' => (int) $category->depth,
                    'code' => (string) $category->code,
                    'name' => (string) $category->name,
                ])
                ->values();
        @endphp

        <script>
            window.__distributionInitialTab = @js($this->getDistributionInitialTab());
            window.__distributionProductCategories = @js($distributionProductCategories);
        </script>

<script>
if (!window.__distributionDatePickerListenerRegistered) {
  window.__distributionDatePickerListenerRegistered = true;
  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

    const picker = event.target.closest('.date-entry-picker');
    if (!picker) return;

    const nativeInput = picker.querySelector('.date-entry-native');
    if (!nativeInput || nativeInput.disabled) return;
    if (typeof nativeInput.showPicker !== 'function') return;

    event.preventDefault();
    event.stopPropagation();
    nativeInput.focus({ preventScroll: true });

    try {
      nativeInput.showPicker();
    } catch (error) {
      // The native input remains clickable if the browser rejects showPicker().
    }
  });
}

if (!window.__distributionStoreSlipListenerRegistered) {
  window.__distributionStoreSlipListenerRegistered = true;

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

    const button = event.target.closest('[data-store-slip-output-button]');
    if (!button || button.disabled) return;

    if (typeof window.__distributionOpenStoreSlipOutput === 'function') {
      event.__distributionSlipHandled = true;
      window.__distributionOpenStoreSlipOutput(button);
    }
  });

  document.addEventListener('change', (event) => {
    if (!(event.target instanceof Element)) return;

    const input = event.target.closest('[data-store-slip-row-checkbox]');
    if (!input || input.disabled) return;

    if (typeof window.__distributionSetStoreSlipRowSelected === 'function') {
      window.__distributionSetStoreSlipRowSelected(input);
    }
  });
}

if (!window.__distributionReprintListenerRegistered) {
  window.__distributionReprintListenerRegistered = true;

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

    const button = event.target.closest('[data-reprint-selection-button]');
    if (!button) return;

    if (typeof window.__distributionOpenReprintSelection === 'function') {
      event.__distributionSlipHandled = true;
      window.__distributionOpenReprintSelection(button);
    }
  });
}

if (!window.__distributionSlipActionListenerRegistered) {
  window.__distributionSlipActionListenerRegistered = true;

  document.addEventListener('click', (event) => {
    if (event.__distributionSlipHandled) return;
    if (!(event.target instanceof Element)) return;

    const button = event.target.closest('[data-slip-action]');
    if (!button || button.disabled) return;

    if (typeof window.__distributionInvokeSlipAction === 'function') {
      window.__distributionInvokeSlipAction(button);
    }
  });
}

window.distributionApp = function distributionApp() {
  // ---------- Constants ----------
  const STORAGE_KEY = 'alloc-ui-v4';
  const DIRECT_STORAGE_KEY = 'direct-alloc-ui-v2';
  const DELETED_ALLOCATION_SOURCE_KEY = 'alloc-ui-deleted-source-keys-v1';
  const FILTER_STORAGE_KEY = 'distribution-filters-v1';
  const STORE_FILTER_VERSION = 2;

  let DESTS = [
    { key: '01', name: '本店' },
    { key: '02', name: '二の宮店' },
    { key: '03', name: '坂井店' },
    { key: '04', name: 'サンドーム前店' },
    { key: '07', name: '光陽店' },
    { key: '08', name: 'プラザ店' },
    { key: '09', name: 'ヴィオ店' },
    { key: '10', name: '敦賀店' },
    { key: '11', name: '越前店' },
    { key: '21', name: '江守店' },
    { key: '22', name: '小浜店' },
  ];

  const requestedTab = window.__distributionInitialTab || new URLSearchParams(window.location.search).get('tab') || window.location.hash.replace('#', '');
  const initialSubTab = ['allocation', 'direct', 'store-view'].includes(requestedTab) ? requestedTab : 'allocation';
  const DEFAULT_STORE_SELECTION_CODES = new Set(['01', '02', '03', '04', '07', '08', '09', '10', '11', '21', '22']);
  const HQ_ORDER_CONTRACTOR_CODE = '9012';
  const HQ_ORDER_PARTNER_NAME = 'LW華本部 物流担当';
  const HQ_WAREHOUSE_CODE = '91';
  const HQ_WAREHOUSE_NAME = '華むすびの蔵センター';
  const SHOW_ALLOCATION_DETAIL_MEMO_BUTTON = false;
  const PRODUCT_CATEGORIES = Array.isArray(window.__distributionProductCategories)
    ? window.__distributionProductCategories
    : [];

  // ---------- Helpers ----------
  const uuid = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : 'id-' + Math.random().toString(36).slice(2));

  const toInt = (v) => {
    const s = String(v ?? '');
    let out = '';
    for (let i = 0; i < s.length; i++) {
      const c = s[i];
      if ((c >= '0' && c <= '9') || c === '-') out += c;
    }
    const n = Number(out);
    return Number.isFinite(n) ? n : 0;
  };

  const getTodayString = () => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  };

  const getCookie = (name) => {
    const value = '; ' + document.cookie;
    const parts = value.split('; ' + name + '=');
    if (parts.length !== 2) return '';

    return decodeURIComponent(parts.pop().split(';').shift() || '');
  };

  // ---------- Build initial wish/alloc keys ----------
  function buildDestKeys(overrides) {
    const keys = {};
    DESTS.forEach(d => {
      keys['wish_' + d.key] = 0;
      keys['alloc_' + d.key] = 0;
    });
    if (overrides) {
      Object.keys(overrides).forEach(k => { keys[k] = overrides[k]; });
    }
    return keys;
  }

  function makeInitialRows() {
    return [];
  }

  return {
    // ---------- State ----------
    DESTS: DESTS,
    showAllocationDetailMemoButton: SHOW_ALLOCATION_DETAIL_MEMO_BUTTON,
    destinationLoading: false,
    destinationError: '',
    activeSubTab: initialSubTab,
    rows: [],

    // Filters
    filterApplying: false,
    filterApplyingLabel: 'フィルタ適用中',
    allocationKeywordSearch: '',
    orderDateFrom: '',
    orderDateTo: '',
    productSearch: '',
    orderToSearch: '',
    supplierSearch: '',
    deliveryDateFrom: '',
    deliveryDateTo: '',
    allocationStatusFilter: 'hide',
    lockedVisibilityFilter: 'hide',
    printedFilter: 'all',
    bulkInputDate: getTodayString(),
    bulkDeliveryCourseSearch: '',
    bulkDeliveryCourseSelected: null,
    bulkDeliveryCourseOptions: [],
    bulkDeliveryCourseLoading: false,
    bulkDeliveryCourseLoaded: false,
    bulkDeliveryCourseError: '',
    bulkDeliveryCourseAbortController: null,
    bulkDateApplying: false,
    allocationFilterDraft: {
      keywordSearch: '',
      orderDateFrom: '',
      orderDateTo: '',
      productSearch: '',
      orderToSearch: '',
      supplierSearch: '',
      deliveryDateFrom: '',
      deliveryDateTo: '',
      statusFilter: 'hide',
      lockedVisibilityFilter: 'hide',
      printedFilter: 'all',
    },

    // Modals
    searchOpenForRow: null,
    productSearchQuery: '',
    productOrderToQuery: '',
    productSupplierQuery: '',
    productLargeCategoryId: '',
    productMiddleCategoryId: '',
    productSmallCategoryId: '',
    productCategoryOptions: PRODUCT_CATEGORIES,
    productMaster: [],
    productSelectedCodes: [],
    productSearchLoading: false,
    productSearchLoaded: false,
    productSearchError: '',
    productSearchAbortController: null,
    productCodeEditBefore: {},
    transferSlipCreating: false,
    warehouseTransferCreating: false,
    orderCandidateCreating: false,
    reprintSelectionVisible: false,
    reprintSelectionMode: 'reprint',
    reprintSelectionTitle: '伝票再出力対象を選択',
    reprintSelectionDescription: '表示中の確定済みデータから、再出力する明細を選択します。',
    reprintSelectionConfirmLabel: '再出力へ',
    reprintSelectionRows: [],
    reprintSelectionAllowedStoreKeys: [],
    reprintSearch: '',
    reprintSelectedRowIds: [],
    reprintSelectedStoreKeys: [],
    deleteSelectedRowIds: [],
    directDeleteSelectedRowIds: [],
    deletedAllocationSourceKeys: [],
    csvImporting: false,
    lastCsvImportParseSummary: { totalRows: 0, importedRows: 0, skippedRows: 0 },
    csvImportProgress: {
      visible: false,
      running: false,
      fileName: '',
      status: '',
      progressPercent: 0,
      progressLabel: '',
      totalRows: 0,
      importedRows: 0,
      skippedRows: 0,
      resolvedRows: 0,
      unresolvedRows: 0,
      ambiguousRows: 0,
      failedRows: 0,
      failureDetails: [],
      error: '',
    },
    productMetaByCode: {},
    productCandidatesByCode: {},
    productCandidatesByJan: {},
    productCodeResolveTokens: {},
    orderDateResolveTokens: {},
    allocationFilterRetainedRowIds: [],
    directFilterRetainedRowIds: [],
    arrivalScheduleProduct: null,
    arrivalScheduleItemId: null,
    arrivalScheduleItems: [],
    arrivalScheduleLoading: false,
    arrivalScheduleLoaded: false,
    arrivalScheduleError: '',
    lotPopoverRowId: null,
    lotPopoverPos: { top: 0, left: 0, anchor: 'below' },
    memoPopupRowId: null,
    memoPopupText: '',

    // 直送分配
    directRows: [],
    directFilterOpen: false,
    directKeywordSearch: '',
    directDateFrom: getTodayString(),
    directDateTo: getTodayString(),
    directProductSearch: '',
    directOrderToSearch: '',
    directSupplierSearch: '',
    directDeliveryFrom: '',
    directDeliveryTo: '',
    directCheckedOnly: false,
    directPrintedFilter: 'all',
    directFilterDraft: {
      keywordSearch: '',
      dateFrom: getTodayString(),
      dateTo: getTodayString(),
      productSearch: '',
      orderToSearch: '',
      supplierSearch: '',
      deliveryFrom: '',
      deliveryTo: '',
      checkedOnly: false,
      printedFilter: 'all',
    },
    directLotPopoverRowId: null,
    directLotPopoverPos: { top: 0, left: 0, anchor: 'below' },
    directMemoPopupRowId: null,
    directMemoPopupText: '',
    directSearchOpenForRow: null,

    // Store view
    storeViewMode: 'individual',
    selectedStores: [],
    selectedStoreSlipKeys: [],
    storeSelectionInitialized: false,
    storeProductSearch: '',
    storeConfirmStatus: 'unconfirmed',
    storeDistributionType: 'all',
    storeRowsVersion: 0,
    storeFilteredRowsCacheKey: '',
    storeFilteredRowsCache: [],
    storeProductsCache: {},
    storeFilterDraft: {
      productSearch: '',
      confirmStatus: 'unconfirmed',
      distributionType: 'all',
    },

    // 伝票備考ポップアップ
    slipRemarkVisible: false,
    slipRemarkMode: 'slip',
    slipRemarkText: '',
    slipTantouName: '',
    slipManagementComment: '',
    slipRemarkCallback: null,
    slipRemarkAllowDetailMemo: false,
    slipRemarkDetailRows: [],
    slipLayoutSelectionVisible: false,
    slipLayoutSelectionDataset: [],
    slipLayoutSelectionStores: [],
    slipLayoutSelectionOptions: { includeZeroAllocRows: true },

    // Toast
    toastMessage: '',
    toastVisible: false,

    // Modal (alert replacement)
    modalVisible: false,
    modalTitle: '',
    modalMessage: '',
    resultModalVisible: false,
    resultModalTitle: '',
    resultModalMessage: '',
    resultModalVariant: 'success',
    hqTransferLoading: false,
    hqTransferError: '',

    // Confirm dialog
    confirmVisible: false,
    confirmMessage: '',
    confirmCallback: null,
    confirmModalType: 'confirm',

    // ---------- Init ----------
    init() {
      window.__distributionOpenStoreSlipOutput = (button) => {
        const root = button && typeof button.closest === 'function' ? button.closest('[x-data]') : null;
        const data = root && root._x_dataStack && root._x_dataStack[0] ? root._x_dataStack[0] : this;

        if (data && typeof data.openStoreSlipOutput === 'function') {
          data.openStoreSlipOutput();
        }
      };

      window.__distributionSetStoreSlipRowSelected = (input) => {
        const root = input && typeof input.closest === 'function' ? input.closest('[x-data]') : null;
        const data = root && root._x_dataStack && root._x_dataStack[0] ? root._x_dataStack[0] : this;

        if (data && typeof data.setStoreSlipRowSelected === 'function') {
          data.setStoreSlipRowSelected(input?.dataset?.rowKey || '', input?.dataset?.storeKey || '', !!input?.checked);
        }
      };

      window.__distributionOpenReprintSelection = (button) => {
        const root = button && typeof button.closest === 'function' ? button.closest('[x-data]') : null;
        const data = root && root._x_dataStack && root._x_dataStack[0] ? root._x_dataStack[0] : this;

        if (data && typeof data.openReprintSelection === 'function') {
          data.openReprintSelection();
        }
      };

      window.__distributionInvokeSlipAction = (button) => {
        const root = button && typeof button.closest === 'function' ? button.closest('[x-data]') : null;
        const data = root && root._x_dataStack && root._x_dataStack[0] ? root._x_dataStack[0] : this;
        const action = String(button?.dataset?.slipAction || '');
        const methodByAction = {
          'reprint-selection': 'openReprintSelection',
          'warehouse-transfer': 'generateWarehouseTransfers',
          'direct-request': 'openDirectRequestOutput',
          'store-slip': 'openStoreSlipOutput',
          'reprint-confirm': 'openSlipRemarkForSelectedReprint',
          'slip-remark-submit': 'submitSlipRemark',
        };
        const methodName = methodByAction[action];

        if (data && methodName && typeof data[methodName] === 'function') {
          data[methodName]();
        }
      };

      this.restorePersistedFilters();
      this.restoreDeletedAllocationSourceKeys();

      try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
          const parsed = JSON.parse(saved);
          if (Array.isArray(parsed) && parsed.length > 0) {
            // Ensure all dest keys exist on each row
            this.rows = parsed
              .map(r => this.normalizeAllocationRow(r))
              .filter(row => !this.isDeletedAllocationRow(row));
          } else {
            this.rows = makeInitialRows();
          }
        } else {
          this.rows = makeInitialRows();
        }
      } catch (e) {
        console.error('Failed to load from localStorage:', e);
        this.rows = makeInitialRows();
      }

      // Listen for external updates
      window.addEventListener('alloc-data-updated', () => {
        try {
          const saved = localStorage.getItem(STORAGE_KEY);
          if (saved) {
            const newData = JSON.parse(saved);
            this.rows = newData
              .map(r => this.normalizeAllocationRow(r))
              .filter(row => !this.isDeletedAllocationRow(row));
            this.touchStoreRows();
            this.hydrateProductMetaFromRows();
          }
        } catch (e) {
          console.error('Failed to reload data:', e);
        }
      });

      // Load direct rows
      try {
        const directSaved = localStorage.getItem(DIRECT_STORAGE_KEY);
        if (directSaved) {
          const parsed = JSON.parse(directSaved);
          if (Array.isArray(parsed) && parsed.length > 0) {
            this.directRows = parsed.map(r => {
              const base = buildDestKeys();
              const normalized = this.normalizeRowDateFields({
                ...base,
                checked: false,
                printed: false,
                locked: false,
                warehouseTransferGenerated: false,
                warehouseTransferQueueIds: [],
                warehouseTransferCreatedAt: '',
                orderCandidateGenerated: false,
                orderCandidateIds: [],
                orderCandidateCreatedAt: '',
                memo: '',
                itemContractorNote: '',
                deliveryDate: '',
                ...r,
              }, true);

              if (this.isRowLocked(normalized)) {
                normalized.checked = true;
                normalized.locked = true;
              } else {
                if (!this.hasRowProductInfo(normalized)) {
                  normalized.checked = false;
                }
                normalized.locked = false;
              }

              return normalized;
            });
          }
        }
      } catch (e) {
        console.error('Failed to load direct data:', e);
      }

      this.touchStoreRows();
      this.hydrateProductMetaFromRows();
      this.loadDestinations().then(() => {
        if (this.activeSubTab === 'allocation') {
          this.loadHqTransferRequests();
        }
      });
    },

    // ---------- Utility ----------
    toInt: toInt,

    orderQuantityInputValue(value) {
      const quantity = toInt(value);
      return quantity > 0 ? quantity : '';
    },

    setOrderQuantity(row, key, value) {
      if (this.isQuantityLocked(row)) return;

      const raw = String(value ?? '').trim();
      row[key] = raw === '' ? '' : Math.max(0, toInt(raw));
    },

    normalizeFlexibleDate(value) {
      const raw = String(value ?? '').trim();
      if (!raw) return '';

      const normalized = raw
        .replace(/[\uFF10-\uFF19]/g, ch => String.fromCharCode(ch.charCodeAt(0) - 0xFEE0))
        .replace(/[／]/g, '/')
        .replace(/[．。]/g, '.')
        .replace(/[－ー―]/g, '-')
        .replace(/[年月]/g, '/')
        .replace(/日/g, '');
      const today = new Date();
      let year = today.getFullYear();
      let month = today.getMonth() + 1;
      let day = today.getDate();
      const separated = normalized.split(/[\/.\-\s]+/).filter(Boolean);

      if (separated.length === 3) {
        year = Number(separated[0]);
        if (separated[0].length === 2) year += 2000;
        month = Number(separated[1]);
        day = Number(separated[2]);
      } else if (separated.length === 2) {
        month = Number(separated[0]);
        day = Number(separated[1]);
      } else if (separated.length === 1) {
        const digits = normalized.replace(/[^\d]/g, '');
        if (digits.length === 8) {
          year = Number(digits.slice(0, 4));
          month = Number(digits.slice(4, 6));
          day = Number(digits.slice(6, 8));
        } else if (digits.length === 6) {
          year = 2000 + Number(digits.slice(0, 2));
          month = Number(digits.slice(2, 4));
          day = Number(digits.slice(4, 6));
        } else if (digits.length === 3) {
          month = Number(digits.slice(0, 1));
          day = Number(digits.slice(1, 3));
        } else if (digits.length === 4) {
          month = Number(digits.slice(0, 2));
          day = Number(digits.slice(2, 4));
        } else if (digits.length >= 1 && digits.length <= 2) {
          day = Number(digits);
        } else {
          return '';
        }
      } else {
        return '';
      }

      return this.formatDateParts(year, month, day);
    },

    isDeliveryDateBeforeOrderDate(row) {
      const orderDate = this.normalizeFlexibleDate(row?.orderDate || '') || '';
      const deliveryDate = this.normalizeFlexibleDate(row?.deliveryDate || '') || '';

      return orderDate !== '' && deliveryDate !== '' && deliveryDate < orderDate;
    },

    formatDateParts(year, month, day) {
      const y = Number(year);
      const m = Number(month);
      const d = Number(day);
      const date = new Date(y, m - 1, d);

      if (
        !Number.isInteger(y) ||
        !Number.isInteger(m) ||
        !Number.isInteger(d) ||
        y < 2000 ||
        y > 2099 ||
        date.getFullYear() !== y ||
        date.getMonth() !== m - 1 ||
        date.getDate() !== d
      ) {
        return '';
      }

      return [
        String(y).padStart(4, '0'),
        String(m).padStart(2, '0'),
        String(d).padStart(2, '0'),
      ].join('-');
    },

    normalizeRowDateFields(row, useTodayForEmptyOrderDate = false) {
      const rawOrderDate = String(row.orderDate ?? '').trim();
      const rawDeliveryDate = String(row.deliveryDate ?? '').trim();
      row.orderDate = this.normalizeFlexibleDate(rawOrderDate) || (rawOrderDate ? '' : (useTodayForEmptyOrderDate ? getTodayString() : ''));
      row.deliveryDate = this.normalizeFlexibleDate(rawDeliveryDate) || '';

      return row;
    },

    normalizeRowsDateFields(rows, useTodayForEmptyOrderDate = false) {
      rows.forEach(row => this.normalizeRowDateFields(row, useTodayForEmptyOrderDate));

      return rows;
    },

    normalizeDateFilters() {
      this.orderDateFrom = this.normalizeFlexibleDate(this.orderDateFrom) || '';
      this.orderDateTo = this.normalizeFlexibleDate(this.orderDateTo) || '';
      this.deliveryDateFrom = this.normalizeFlexibleDate(this.deliveryDateFrom) || '';
      this.deliveryDateTo = this.normalizeFlexibleDate(this.deliveryDateTo) || '';
      this.directDateFrom = this.normalizeFlexibleDate(this.directDateFrom) || '';
      this.directDateTo = this.normalizeFlexibleDate(this.directDateTo) || '';
      this.directDeliveryFrom = this.normalizeFlexibleDate(this.directDeliveryFrom) || '';
      this.directDeliveryTo = this.normalizeFlexibleDate(this.directDeliveryTo) || '';
    },

    normalizeAllDateInputs() {
      this.normalizeDateFilters();
      this.normalizeRowsDateFields(this.rows, true);
      this.normalizeRowsDateFields(this.directRows, true);
    },

    normalizeAllocationRow(row) {
      const base = buildDestKeys();
      const normalized = {
        ...base,
        checked: false,
        printed: false,
        locked: false,
        transferSlipQueueIds: [],
        transferSlipCreatedAt: '',
        warehouseTransferGenerated: false,
        warehouseTransferQueueIds: [],
        warehouseTransferCreatedAt: '',
        orderCandidateGenerated: false,
        orderCandidateIds: [],
        orderCandidateCreatedAt: '',
        memo: '',
        itemContractorNote: '',
        destinationsExpanded: false,
        deliveryDate: '',
        suggestedDeliveryDate: '',
        deliveryDateCalculation: null,
        visibleDestinationKeys: [],
        ...row,
      };

      normalized.visibleDestinationKeys = this.getRowVisibleDestinationKeys(normalized);

      if (!normalized.id) {
        normalized.id = uuid();
      }

      if (this.isRowLocked(normalized)) {
        normalized.checked = true;
        normalized.locked = true;
      } else {
        if (!this.hasRowProductInfo(normalized)) {
          normalized.checked = false;
        }
        normalized.locked = false;
      }

      return this.normalizeRowDateFields(normalized, true);
    },

    isRowLocked(row) {
      return this.isWarehouseTransferCreated(row);
    },

    isQuantityLocked(row) {
      return !!(
        row?.checked ||
        this.isOrderCandidateCreated(row) ||
        this.isRowLocked(row)
      );
    },

    isRowEditLocked(row) {
      return !!(
        this.isRowLocked(row) ||
        (row?.checked && !this.isOrderCandidateCreated(row))
      );
    },

    isProductEditLocked(row) {
      return !!(
        this.isRowEditLocked(row) ||
        this.isOrderCandidateCreated(row)
      );
    },

    isRowDeleteLocked(row) {
      return !!(
        row?.checked ||
        this.isOrderCandidateCreated(row) ||
        this.isRowLocked(row)
      );
    },

    isWarehouseTransferCreated(row) {
      return !!(
        row?.warehouseTransferGenerated ||
        (Array.isArray(row?.warehouseTransferQueueIds) && row.warehouseTransferQueueIds.length > 0)
      );
    },

    isOrderCandidateCreated(row) {
      return !!(
        row?.orderCandidateGenerated ||
        (Array.isArray(row?.orderCandidateIds) && row.orderCandidateIds.length > 0)
      );
    },

    hasRowProductInfo(row) {
      if (!row) return false;

      const meta = this.getProductMeta(row.productCode);
      const itemId = toInt(row.itemId || meta?.id || 0);
      const productCode = String(row.productCode || meta?.code || '').trim();
      const productName = String(row.name || meta?.name || '').trim();

      return itemId > 0 && productCode !== '' && productName !== '';
    },

    getMissingProductInfoMessage() {
      return '商品情報がない行は確定できません。商品検索または商品コード入力で商品マスタを反映してください。';
    },

    normalizeDestination(destination) {
      const rawCode = String(destination.code || destination.key || destination.id || '').trim();
      const key = String(destination.key || rawCode || destination.id || '').trim();

      if (!key) return null;

      return {
        id: destination.id || null,
        key: key,
        code: rawCode || key,
        name: String(destination.name || key),
        isHidden: !!(destination.isHidden || destination.is_hidden),
        isSelectedWarehouse: !!(destination.isSelectedWarehouse || destination.is_selected_warehouse),
      };
    },

    ensureDestinationKeys() {
      this.rows = this.rows.map(row => ({ ...buildDestKeys(), ...row }));
      this.directRows = this.directRows.map(row => ({ ...buildDestKeys(), ...row }));
      this.touchStoreRows();

      const validKeys = new Set(this.getStoreDisplayDestinationKeys());
      this.selectedStores = this.selectedStores.map(key => String(key)).filter(key => validKeys.has(key));

      if (!this.isHqStoreViewWarehouse()) {
        this.selectedStores = this.getStoreDisplayDestinationKeys();
        return;
      }

      if (this.selectedStores.length === 0 && validKeys.size > 0) {
        this.selectedStores = Array.from(validKeys).slice(0, 1);
      }
    },

    getStoreSelectionCode(destination) {
      const code = String(destination?.code || destination?.key || '').replace(/^0+/, '');
      return code.padStart(2, '0');
    },

    getSelectedWarehouseDestination() {
      const destinations = Array.isArray(this.DESTS) ? this.DESTS : DESTS;

      return destinations.find(destination => !!destination?.isSelectedWarehouse) || null;
    },

    isHqStoreViewWarehouse() {
      const selected = this.getSelectedWarehouseDestination();

      return !selected || this.getStoreSelectionCode(selected) === HQ_WAREHOUSE_CODE;
    },

    getStoreDisplayDestinations() {
      const destinations = Array.isArray(this.DESTS) ? this.DESTS : DESTS;
      const selected = this.getSelectedWarehouseDestination();

      if (selected && this.getStoreSelectionCode(selected) !== HQ_WAREHOUSE_CODE) {
        return selected.isHidden ? [] : [selected];
      }

      return destinations.filter(destination =>
        !destination.isHidden &&
        this.getStoreSelectionCode(destination) !== HQ_WAREHOUSE_CODE
      );
    },

    getStoreDisplayDestinationKeys() {
      return this.getStoreDisplayDestinations().map(destination => String(destination.key));
    },

    applyDefaultStoreSelection() {
      if (this.storeSelectionInitialized || this.activeSubTab !== 'store-view' || this.DESTS.length === 0) {
        return;
      }

      this.storeSelectionInitialized = true;
      if (!this.isHqStoreViewWarehouse()) {
        this.selectedStores = this.getStoreDisplayDestinationKeys();
        return;
      }

      const defaultStores = this.getStoreDisplayDestinations().filter(destination => DEFAULT_STORE_SELECTION_CODES.has(this.getStoreSelectionCode(destination)));

      if (defaultStores.length === 0) {
        this.ensureDestinationKeys();
        return;
      }

      this.selectedStores = defaultStores.map(destination => destination.key);
    },

    isHqTransferRequestRow(row) {
      const source = String(row?.source || '');
      const sourceKey = String(row?.sourceKey || row?.source_key || '');

      return source === 'hq_transfer_request'
        || source === 'internal_demand_request'
        || sourceKey.startsWith('hq_transfer_request:')
        || sourceKey.startsWith('hq-transfer:')
        || sourceKey.startsWith('internal_demand_request:')
        || sourceKey.startsWith('internal-demand:')
        || Array.isArray(row?.sourceCandidateIds)
        || Array.isArray(row?.source_candidate_ids);
    },

    getAllocationRowDeleteKey(row) {
      return String(
        row?.sourceKey
        || row?.source_key
        || row?.id
        || ''
      ).trim();
    },

    normalizeDeletedAllocationSourceKeys(keys = []) {
      return Array.from(new Set(
        (Array.isArray(keys) ? keys : [])
          .map(key => String(key || '').trim())
          .filter(key => key !== '')
      ));
    },

    restoreDeletedAllocationSourceKeys() {
      try {
        const saved = localStorage.getItem(DELETED_ALLOCATION_SOURCE_KEY);
        this.deletedAllocationSourceKeys = this.normalizeDeletedAllocationSourceKeys(JSON.parse(saved || '[]'));
      } catch (e) {
        console.warn('Failed to restore deleted distribution rows:', e);
        this.deletedAllocationSourceKeys = [];
      }
    },

    persistDeletedAllocationSourceKeys() {
      try {
        this.deletedAllocationSourceKeys = this.normalizeDeletedAllocationSourceKeys(this.deletedAllocationSourceKeys);
        localStorage.setItem(DELETED_ALLOCATION_SOURCE_KEY, JSON.stringify(this.deletedAllocationSourceKeys));
      } catch (e) {
        console.warn('Failed to persist deleted distribution rows:', e);
      }
    },

    rememberDeletedAllocationRows(rows = []) {
      const keys = Array.isArray(rows)
        ? rows
          .filter(row => this.isHqTransferRequestRow(row))
          .map(row => this.getAllocationRowDeleteKey(row))
          .filter(key => key !== '')
        : [];

      if (keys.length === 0) return;

      this.deletedAllocationSourceKeys = this.normalizeDeletedAllocationSourceKeys([
        ...this.deletedAllocationSourceKeys,
        ...keys,
      ]);
      this.persistDeletedAllocationSourceKeys();
    },

    isDeletedAllocationRow(row) {
      const key = this.getAllocationRowDeleteKey(row);
      if (!key) return false;

      return this.deletedAllocationSourceKeys.includes(key);
    },

    shouldPreserveLocalAllocationRow(row) {
      return !this.isBlankAllocationRow(row) && !this.isHqTransferRequestRow(row);
    },

    async loadDestinations() {
      this.destinationLoading = true;
      this.destinationError = '';

      try {
        const params = new URLSearchParams();
        if (this.activeSubTab === 'store-view') {
          params.set('include_selected', '1');
        }
        const url = '/api/distribution/destinations' + (params.toString() ? '?' + params.toString() : '');
        const response = await fetch(url, {
          headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        const payload = await response.json();
        const data = Array.isArray(payload?.result?.data)
          ? payload.result.data
          : (Array.isArray(payload) ? payload : []);
        const destinations = data
          .map(destination => this.normalizeDestination(destination))
          .filter(destination => destination !== null);

        if (destinations.length > 0) {
          DESTS = destinations;
          this.DESTS = destinations;
          this.ensureDestinationKeys();
          this.applyDefaultStoreSelection();
        }
      } catch (e) {
        console.error('Failed to load distribution destinations:', e);
        this.destinationError = '分配先マスタの取得に失敗しました';
      } finally {
        this.destinationLoading = false;
      }
    },

    normalizeHqTransferRequestRow(request) {
      const wishes = request?.wishes || {};
      const row = this.makeAllocationRow({
        id: request.id || request.sourceKey || uuid(),
        source: request.source || 'hq_transfer_request',
        sourceKey: request.sourceKey || request.source_key || '',
        sourceCandidateIds: request.sourceCandidateIds || request.source_candidate_ids || [],
        sourceCandidateIdsByDestination: request.sourceCandidateIdsByDestination || request.source_candidate_ids_by_destination || {},
        itemId: request.itemId || request.item_id || null,
        candidateKey: request.candidateKey || request.candidate_key || request.sourceKey || '',
        itemContractorId: request.itemContractorId || request.item_contractor_id || null,
        contractorId: request.contractorId || request.contractor_id || null,
        supplierId: request.supplierId || request.supplier_id || null,
        contractorWarehouseId: request.contractorWarehouseId || request.contractor_warehouse_id || null,
        productCode: request.productCode || request.product_code || '',
        jan: request.jan || '',
        name: request.name || '',
        volume: request.volume || '',
        unitsPerCase: toInt(request.unitsPerCase || request.units_per_case || 1) || 1,
        purchaseUnit: toInt(request.purchaseUnit || request.purchase_unit || 1) || 1,
        lot: toInt(request.lot || request.purchaseUnit || request.purchase_unit || 1) || 1,
        orderPoint: toInt(request.orderPoint || request.order_point || 0),
        currentStock: toInt(request.currentStock || request.current_stock || 0),
        reserved: toInt(request.reserved || 0),
        reservedStock: toInt(request.reservedStock || request.reserved_stock || 0),
        incomingQuantity: toInt(request.incomingQuantity || request.incoming_quantity || 0),
        incomingCount: toInt(request.incomingCount || request.incoming_count || 0),
        selectedWarehouseId: request.selectedWarehouseId || request.selected_warehouse_id || null,
        realWarehouseId: request.realWarehouseId || request.real_warehouse_id || null,
        orderDate: request.orderDate || request.order_date || getTodayString(),
        deliveryDate: request.deliveryDate || request.delivery_date || '',
        deliveryCourseId: request.deliveryCourseId || request.delivery_course_id || null,
        deliveryCourseCode: request.deliveryCourseCode || request.delivery_course_code || '',
        deliveryCourseName: request.deliveryCourseName || request.delivery_course_name || '',
        orderTo: request.orderTo || request.order_to || '',
        orderToCode: request.orderToCode || request.order_to_code || '',
        supplier: request.supplier || '',
        supplierCode: request.supplierCode || request.supplier_code || '',
        memo: request.memo || '',
        poCase: '',
        poEach: '',
      });

      DESTS.forEach(dest => {
        row['wish_' + dest.key] = toInt(wishes[dest.key] || 0);
        row['alloc_' + dest.key] = toInt(row['alloc_' + dest.key] || 0);
      });

      return row;
    },

    applyExistingAllocationEdits(target, existing) {
      if (!target || !existing) return target;

      DESTS.forEach(dest => {
        target['alloc_' + dest.key] = toInt(existing['alloc_' + dest.key] || 0);
      });

      [
        'poCase',
        'poEach',
        'checked',
        'printed',
        'locked',
        'transferSlipQueueIds',
        'transferSlipCreatedAt',
        'transferSlipQueues',
        'warehouseTransferGenerated',
        'warehouseTransferQueueIds',
        'warehouseTransferCreatedAt',
        'orderCandidateGenerated',
        'orderCandidateIds',
        'orderCandidateCreatedAt',
        'deliveryCourseId',
        'deliveryCourseCode',
        'deliveryCourseName',
        'visibleDestinationKeys',
        'memo',
      ].forEach(key => {
        if (existing[key] !== undefined) {
          target[key] = existing[key];
        }
      });

      if (existing.orderDate) target.orderDate = existing.orderDate;
      if (existing.deliveryDate) target.deliveryDate = existing.deliveryDate;

      if (this.isRowLocked(target)) {
        target.checked = true;
        target.locked = true;
      }

      return target;
    },

    async loadHqTransferRequests() {
      this.hqTransferLoading = true;
      this.hqTransferError = '';

      try {
        const params = new URLSearchParams();
        const pageParams = new URLSearchParams(window.location.search || '');
        const demandRequestId = pageParams.get('demand_request_id') || pageParams.get('internal_demand_request_id') || '';
        if (demandRequestId) {
          params.set('demand_request_id', demandRequestId);
        }
        const response = await fetch('/api/distribution/hq-transfer-requests' + (params.toString() ? '?' + params.toString() : ''), {
          headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        const payload = await response.json();
        const data = Array.isArray(payload?.result?.data)
          ? payload.result.data
          : (Array.isArray(payload) ? payload : []);
        const existingByKey = new Map(this.rows.map(row => [row.sourceKey || row.id, row]));
        const localRows = this.rows
          .filter(row => this.shouldPreserveLocalAllocationRow(row) && !this.isDeletedAllocationRow(row))
          .map(row => this.normalizeAllocationRow(row));

        this.rows = data.map(request => {
          const row = this.normalizeHqTransferRequestRow(request);
          const existing = existingByKey.get(row.sourceKey || row.id);
          return this.applyExistingAllocationEdits(row, existing);
        }).filter(row => !this.isDeletedAllocationRow(row)).concat(localRows);

        this.hydrateProductMetaFromRows();
        this.saveData();
      } catch (e) {
        console.error('Failed to load HQ transfer requests:', e);
        this.hqTransferError = '本部向け発注データの取得に失敗しました';
        this.showToast(this.hqTransferError);
      } finally {
        this.hqTransferLoading = false;
      }
    },

    showToast(msg) {
      this.toastMessage = msg;
      this.toastVisible = true;
      setTimeout(() => { this.toastVisible = false; }, 1600);
    },

    openModal(title, message) {
      this.modalTitle = title;
      this.modalMessage = message;
      this.modalVisible = true;
    },

    openResultModal(title, message, variant = 'success') {
      this.resultModalTitle = title;
      this.resultModalMessage = message;
      this.resultModalVariant = variant;
      this.resultModalVisible = true;
    },

    openConfirm(message, callback, type = 'confirm') {
      this.confirmMessage = message;
      this.confirmCallback = callback;
      this.confirmModalType = type;
      this.confirmVisible = true;
    },

    doConfirm() {
      this.confirmVisible = false;
      if (this.confirmCallback) this.confirmCallback();
      this.confirmCallback = null;
    },

    // ---------- Calculations ----------
    calcWishTotal(row) {
      let total = 0;
      DESTS.forEach(d => { total += toInt(row['wish_' + d.key] || 0); });
      return total;
    },

    calcAllocTotal(row) {
      let total = 0;
      DESTS.forEach(d => { total += toInt(row['alloc_' + d.key] || 0); });
      return total;
    },

    calcOrderTotalPieces(row, allocTotalResolver = row => this.calcAllocTotal(row)) {
      const hasOrderQuantity = toInt(row?.poCase) > 0 || toInt(row?.poEach) > 0;
      if (hasOrderQuantity) {
        return toInt(row?.poCase) * toInt(row?.unitsPerCase) + toInt(row?.poEach);
      }

      return toInt(allocTotalResolver(row));
    },

    normalizeDestinationKeyList(keys) {
      const validKeys = new Set(DESTS.map(dest => String(dest.key)));
      const seen = new Set();
      const normalized = [];

      (Array.isArray(keys) ? keys : []).forEach(key => {
        const value = String(key || '');
        if (!value || !validKeys.has(value) || seen.has(value)) return;
        seen.add(value);
        normalized.push(value);
      });

      return normalized;
    },

    getRowVisibleDestinationKeys(row) {
      return this.normalizeDestinationKeyList(row?.visibleDestinationKeys || row?.visible_destination_keys || []);
    },

    getAllocationDisplayDestinations(rows = this.getVisibleRows()) {
      const sourceRows = Array.isArray(rows) ? rows : [];
      if (sourceRows.length === 0) return [];

      const mergedKeys = new Set();
      for (const row of sourceRows) {
        const keys = this.getRowVisibleDestinationKeys(row);
        if (keys.length === 0) return DESTS;
        keys.forEach(key => mergedKeys.add(key));
      }

      if (mergedKeys.size === 0) return DESTS;

      return DESTS.filter(dest => mergedKeys.has(String(dest.key)));
    },

    getAllocationTableMinWidth() {
      const destinationCount = this.getAllocationDisplayDestinations().length;
      return 1020 + destinationCount * 80;
    },

    toggleAllocationDestinations(row) {
      row.destinationsExpanded = !row.destinationsExpanded;
    },

    handleAllocationRowClick(event, row) {
      if (event.target instanceof Element && event.target.closest('button,input,select,textarea,label,a')) {
        return;
      }

      this.toggleAllocationDestinations(row);
    },

    focusNextAllocationDestinationInput(event) {
      const target = event?.target;
      if (!(target instanceof HTMLInputElement)) return;

      const scope = target.closest('table') || target.closest('.distribution-app-shell') || document;

      const inputs = Array.from(scope.querySelectorAll('input.allocation-destination-input:not(:disabled)'))
        .filter(input => input.offsetParent !== null);
      const currentIndex = inputs.indexOf(target);
      if (currentIndex < 0) return;

      const nextIndex = currentIndex + (event.shiftKey ? -1 : 1);
      const nextInput = inputs[nextIndex];
      if (!nextInput) return;

      nextInput.focus();
      nextInput.select();
    },

    visibleAllocationDestinationsExpanded() {
      const rows = this.getVisibleRows();
      return rows.length > 0 && rows.every(row => row.destinationsExpanded);
    },

    toggleAllAllocationDestinations() {
      const shouldExpand = !this.visibleAllocationDestinationsExpanded();
      this.getVisibleRows().forEach(row => {
        row.destinationsExpanded = shouldExpand;
      });
    },

    normalizeSearchText(value) {
      return String(value ?? '')
        .trim()
        .replace(/[！-～]/g, ch => String.fromCharCode(ch.charCodeAt(0) - 0xFEE0))
        .toLowerCase();
    },

    matchesSearchText(value, keyword) {
      const field = this.normalizeSearchText(value);
      const q = this.normalizeSearchText(keyword);
      if (!q) return true;
      if (!field) return false;
      if (field.includes(q)) return true;

      const compactField = field.replace(/[\s\[\]（）(){}｛｝\-_/／\\.:：]/g, '');
      const compactQ = q.replace(/[\s\[\]（）(){}｛｝\-_/／\\.:：]/g, '');
      if (compactQ && compactField.includes(compactQ)) return true;

      const fieldDigits = field.replace(/\D/g, '').replace(/^0+/, '') || (field.replace(/\D/g, '') ? '0' : '');
      const qDigits = q.replace(/\D/g, '').replace(/^0+/, '') || (q.replace(/\D/g, '') ? '0' : '');

      return !!(fieldDigits && qDigits && fieldDigits.includes(qDigits));
    },

    splitSearchKeywords(value) {
      return this.normalizeSearchText(value)
        .split(/[\s,、]+/)
        .map(keyword => keyword.trim())
        .filter(Boolean);
    },

    matchesAllSearchKeywords(fields, keyword) {
      const keywords = this.splitSearchKeywords(keyword);
      if (keywords.length === 0) return true;

      return keywords.every(searchWord => fields.some(value => this.matchesSearchText(value, searchWord)));
    },

    getOrderToSearchFields(row) {
      const meta = this.getProductMeta(row.productCode);
      return [row.orderTo, row.orderToCode, meta.orderTo, meta.orderToCode];
    },

    getSupplierSearchFields(row) {
      const meta = this.getProductMeta(row.productCode);
      return [row.supplier, row.supplierCode, meta.supplier, meta.supplierCode];
    },

    formatFilterCount(count) {
      const circledNumbers = ['', '①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨', '⑩', '⑪', '⑫', '⑬', '⑭', '⑮', '⑯', '⑰', '⑱', '⑲', '⑳'];
      return circledNumbers[count] || String(count);
    },

    runFilterApply(label, callback) {
      if (this.filterApplying) return;

      this.filterApplying = true;
      this.filterApplyingLabel = label || 'フィルタ適用中';

      window.setTimeout(() => {
        callback();
        window.setTimeout(() => {
          this.filterApplying = false;
        }, 220);
      }, 30);
    },

    getAllocationFilterState() {
      return {
        keywordSearch: this.allocationKeywordSearch || '',
        statusFilter: this.allocationStatusFilter || 'hide',
      };
    },

    setAllocationFilterState(state = {}) {
      const statusFilter = state.statusFilter
        || this.getAllocationStatusFilterFromLegacy(state.lockedVisibilityFilter, state.printedFilter);

      this.allocationKeywordSearch = state.keywordSearch || '';
      this.orderDateFrom = '';
      this.orderDateTo = '';
      this.productSearch = '';
      this.orderToSearch = '';
      this.supplierSearch = '';
      this.deliveryDateFrom = '';
      this.deliveryDateTo = '';
      this.syncAllocationStatusFilter(statusFilter);

      Object.assign(this.allocationFilterDraft, {
        keywordSearch: this.allocationKeywordSearch,
        orderDateFrom: this.orderDateFrom,
        orderDateTo: this.orderDateTo,
        productSearch: '',
        orderToSearch: '',
        supplierSearch: '',
        deliveryDateFrom: this.deliveryDateFrom,
        deliveryDateTo: this.deliveryDateTo,
        statusFilter: this.allocationStatusFilter,
        lockedVisibilityFilter: this.lockedVisibilityFilter,
        printedFilter: this.printedFilter,
      });
    },

    getDirectFilterState() {
      return {
        keywordSearch: this.directKeywordSearch || '',
        dateFrom: this.directDateFrom || '',
        dateTo: this.directDateTo || '',
        productSearch: this.directProductSearch || '',
        orderToSearch: this.directOrderToSearch || '',
        supplierSearch: this.directSupplierSearch || '',
        deliveryFrom: this.directDeliveryFrom || '',
        deliveryTo: this.directDeliveryTo || '',
        checkedOnly: !!this.directCheckedOnly,
        printedFilter: this.directPrintedFilter || 'all',
      };
    },

    setDirectFilterState(state = {}) {
      this.directKeywordSearch = state.keywordSearch || '';
      this.directDateFrom = this.normalizeFlexibleDate(state.dateFrom || '') || '';
      this.directDateTo = this.normalizeFlexibleDate(state.dateTo || '') || '';
      this.directProductSearch = state.productSearch || '';
      this.directOrderToSearch = state.orderToSearch || '';
      this.directSupplierSearch = state.supplierSearch || '';
      this.directDeliveryFrom = this.normalizeFlexibleDate(state.deliveryFrom || '') || '';
      this.directDeliveryTo = this.normalizeFlexibleDate(state.deliveryTo || '') || '';
      this.directCheckedOnly = !!state.checkedOnly;
      this.directPrintedFilter = ['all', 'printed', 'unprinted'].includes(state.printedFilter)
        ? state.printedFilter
        : 'all';

      Object.assign(this.directFilterDraft, this.getDirectFilterState());
    },

    getStoreFilterState() {
      return {
        version: STORE_FILTER_VERSION,
        productSearch: this.storeProductSearch || '',
        confirmStatus: this.storeConfirmStatus || 'unconfirmed',
        distributionType: this.storeDistributionType || 'all',
      };
    },

    setStoreFilterState(state = {}) {
      const isCurrentStoreFilter = Number(state.version || 0) >= STORE_FILTER_VERSION;
      this.storeProductSearch = state.productSearch || '';
      this.storeConfirmStatus = ['confirmed', 'unconfirmed', 'all'].includes(state.confirmStatus)
        ? (isCurrentStoreFilter ? state.confirmStatus : 'unconfirmed')
        : 'unconfirmed';
      this.storeDistributionType = ['all', 'allocation', 'direct'].includes(state.distributionType)
        ? state.distributionType
        : 'all';

      Object.assign(this.storeFilterDraft, this.getStoreFilterState());
    },

    getPersistedFilterState() {
      return {
        allocation: this.getAllocationFilterState(),
        direct: this.getDirectFilterState(),
        store: this.getStoreFilterState(),
      };
    },

    persistFilters() {
      try {
        localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(this.getPersistedFilterState()));
      } catch (e) {
        console.warn('Failed to persist distribution filters:', e);
      }
    },

    restorePersistedFilters() {
      try {
        const saved = localStorage.getItem(FILTER_STORAGE_KEY);
        if (!saved) return;

        const parsed = JSON.parse(saved);
        if (!parsed || typeof parsed !== 'object') return;

        if (parsed.allocation && typeof parsed.allocation === 'object') {
          this.setAllocationFilterState(parsed.allocation);
        }
        if (parsed.direct && typeof parsed.direct === 'object') {
          this.setDirectFilterState(parsed.direct);
        }
        if (parsed.store && typeof parsed.store === 'object') {
          this.setStoreFilterState(parsed.store);
        }
      } catch (e) {
        console.warn('Failed to restore distribution filters:', e);
      }
    },

    normalizeAllocationFilterDraft() {
      this.allocationFilterDraft.orderDateFrom = '';
      this.allocationFilterDraft.orderDateTo = '';
      this.allocationFilterDraft.orderToSearch = '';
      this.allocationFilterDraft.supplierSearch = '';
      this.allocationFilterDraft.deliveryDateFrom = '';
      this.allocationFilterDraft.deliveryDateTo = '';
    },

    getAllocationStatusFilterFromLegacy(lockedVisibilityFilter = 'hide', printedFilter = 'all') {
      if (['warehouse_transfer_creatable', 'unprinted'].includes(printedFilter)) return 'warehouse_transfer_creatable';
      if (printedFilter === 'printed') return 'printed';
      if (lockedVisibilityFilter === 'locked') return 'locked';
      if (lockedVisibilityFilter === 'all') return 'all';

      return 'hide';
    },

    resolveAllocationStatusFilter(statusFilter = 'hide') {
      const normalized = statusFilter || 'hide';

      if (['warehouse_transfer_creatable', 'unprinted'].includes(normalized)) {
        return { lockedVisibilityFilter: 'all', printedFilter: 'warehouse_transfer_creatable' };
      }

      if (normalized === 'printed') {
        return { lockedVisibilityFilter: 'all', printedFilter: 'printed' };
      }

      if (normalized === 'locked') {
        return { lockedVisibilityFilter: 'locked', printedFilter: 'all' };
      }

      if (normalized === 'all') {
        return { lockedVisibilityFilter: 'all', printedFilter: 'all' };
      }

      return { lockedVisibilityFilter: 'hide', printedFilter: 'all' };
    },

    syncAllocationStatusFilter(statusFilter = 'hide') {
      const normalized = statusFilter || 'hide';
      const resolved = this.resolveAllocationStatusFilter(normalized);

      this.allocationStatusFilter = normalized;
      this.lockedVisibilityFilter = resolved.lockedVisibilityFilter;
      this.printedFilter = resolved.printedFilter;

      return resolved;
    },

    applyAllocationFilters() {
      this.normalizeAllocationFilterDraft();
      this.runFilterApply('フィルタ適用中', () => {
        const statusFilter = this.allocationFilterDraft.statusFilter
          || this.getAllocationStatusFilterFromLegacy(
            this.allocationFilterDraft.lockedVisibilityFilter,
            this.allocationFilterDraft.printedFilter
          );

        this.allocationKeywordSearch = this.allocationFilterDraft.keywordSearch || '';
        this.orderDateFrom = '';
        this.orderDateTo = '';
        this.productSearch = '';
        this.orderToSearch = '';
        this.supplierSearch = '';
        this.allocationFilterDraft.productSearch = '';
        this.deliveryDateFrom = '';
        this.deliveryDateTo = '';
        this.allocationFilterDraft.statusFilter = statusFilter;
        this.syncAllocationStatusFilter(statusFilter);
        this.clearFilterRetainedRows(false);
        this.persistFilters();
      });
    },

    normalizeDirectFilterDraft() {
      this.directFilterDraft.dateFrom = this.normalizeFlexibleDate(this.directFilterDraft.dateFrom || '') || '';
      this.directFilterDraft.dateTo = this.normalizeFlexibleDate(this.directFilterDraft.dateTo || '') || '';
      this.directFilterDraft.deliveryFrom = this.normalizeFlexibleDate(this.directFilterDraft.deliveryFrom || '') || '';
      this.directFilterDraft.deliveryTo = this.normalizeFlexibleDate(this.directFilterDraft.deliveryTo || '') || '';
    },

    applyDirectFilters() {
      this.normalizeDirectFilterDraft();
      this.runFilterApply('フィルタ適用中', () => {
        this.directKeywordSearch = this.directFilterDraft.keywordSearch || '';
        this.directDateFrom = this.directFilterDraft.dateFrom || '';
        this.directDateTo = this.directFilterDraft.dateTo || '';
        this.directProductSearch = this.directFilterDraft.productSearch || '';
        this.directOrderToSearch = this.directFilterDraft.orderToSearch || '';
        this.directSupplierSearch = this.directFilterDraft.supplierSearch || '';
        this.directDeliveryFrom = this.directFilterDraft.deliveryFrom || '';
        this.directDeliveryTo = this.directFilterDraft.deliveryTo || '';
        this.directCheckedOnly = Boolean(this.directFilterDraft.checkedOnly);
        this.directPrintedFilter = this.directFilterDraft.printedFilter || 'all';
        this.clearFilterRetainedRows(true);
        this.persistFilters();
      });
    },

    normalizeStoreFilterDraft() {
      if (!['confirmed', 'unconfirmed', 'all'].includes(this.storeFilterDraft.confirmStatus)) {
        this.storeFilterDraft.confirmStatus = 'unconfirmed';
      }
      if (!['all', 'allocation', 'direct'].includes(this.storeFilterDraft.distributionType)) {
        this.storeFilterDraft.distributionType = 'all';
      }
    },

    applyStoreFilters() {
      this.normalizeStoreFilterDraft();
      this.runFilterApply('フィルタ適用中', () => {
        this.storeProductSearch = this.storeFilterDraft.productSearch || '';
        this.storeConfirmStatus = this.storeFilterDraft.confirmStatus || 'unconfirmed';
        this.storeDistributionType = this.storeFilterDraft.distributionType || 'all';
        this.clearStoreRowsCache();
        this.persistFilters();
      });
    },

    isDefaultOrderDateFilter(from, to) {
      const today = getTodayString();
      return String(from || '') === today && String(to || '') === today;
    },

    hasCustomOrderDateFilter(from, to) {
      if (!from && !to) return false;

      return !this.isDefaultOrderDateFilter(from, to);
    },

    getAllocationFilterCount() {
      let count = 0;
      if (this.allocationKeywordSearch.trim()) count++;
      if (this.allocationStatusFilter !== 'hide') count++;

      return count;
    },

    hasAllocationAdvancedFilters() {
      return this.getAllocationFilterCount() > 0;
    },

    getDirectFilterCount() {
      let count = 0;
      if (this.directKeywordSearch.trim()) count++;
      if (this.hasCustomOrderDateFilter(this.directDateFrom, this.directDateTo)) count++;
      if (this.directDeliveryFrom || this.directDeliveryTo) count++;
      if (this.directProductSearch.trim()) count++;
      if (this.directOrderToSearch.trim()) count++;
      if (this.directSupplierSearch.trim()) count++;
      if (this.directCheckedOnly) count++;
      if (this.directPrintedFilter !== 'all') count++;

      return count;
    },

    hasDirectAdvancedFilters() {
      return this.getDirectFilterCount() > 0;
    },

    matchesAllocationKeyword(row, keyword) {
      const q = this.normalizeSearchText(keyword);
      if (!q) return true;

      const meta = this.getProductMeta(row.productCode);
      const fields = [
        row.productCode,
        row.name,
        row.jan,
        meta.code,
        meta.name,
        meta.jan,
      ];

      return this.matchesAllSearchKeywords(fields, q);
    },

    // ---------- Filtering ----------
    sortRowsByOrderDateDesc(rows) {
      return Array.from(rows).sort((left, right) => {
        const leftDate = String(left?.orderDate || '');
        const rightDate = String(right?.orderDate || '');

        if (leftDate === rightDate) return 0;
        if (!leftDate) return 1;
        if (!rightDate) return -1;

        return rightDate.localeCompare(leftDate);
      });
    },

    getFilterRetainedRowList(isDirect = false) {
      return isDirect ? this.directFilterRetainedRowIds : this.allocationFilterRetainedRowIds;
    },

    getRowFilterRetentionKey(row) {
      if (!row) return '';
      return String(row.id || row.rowId || row.sourceRowId || row.productCode || '');
    },

    rememberFilterRetainedRow(row, isDirect = false) {
      const key = this.getRowFilterRetentionKey(row);
      if (!key) return;

      const retained = this.getFilterRetainedRowList(isDirect);
      if (!retained.map(String).includes(key)) {
        retained.push(key);
      }
    },

    rememberFilterRetainedRows(rows = [], isDirect = false) {
      (Array.isArray(rows) ? rows : []).forEach(row => this.rememberFilterRetainedRow(row, isDirect));
    },

    clearFilterRetainedRows(isDirect = null) {
      if (isDirect === true) {
        this.directFilterRetainedRowIds = [];
        return;
      }

      if (isDirect === false) {
        this.allocationFilterRetainedRowIds = [];
        return;
      }

      this.allocationFilterRetainedRowIds = [];
      this.directFilterRetainedRowIds = [];
    },

    mergeFilterRetainedRows(filteredRows, sourceRows, isDirect = false) {
      const retained = new Set(this.getFilterRetainedRowList(isDirect).map(String));
      if (retained.size === 0) return filteredRows;

      const existing = new Set(filteredRows.map(row => this.getRowFilterRetentionKey(row)));
      const merged = Array.from(filteredRows);

      sourceRows.forEach(row => {
        const key = this.getRowFilterRetentionKey(row);
        if (key && retained.has(key) && !existing.has(key)) {
          merged.push(row);
          existing.add(key);
        }
      });

      return merged;
    },

    isKeptVisibleByPendingFilter(row, isDirect = false) {
      const key = this.getRowFilterRetentionKey(row);
      if (!key || !this.getFilterRetainedRowList(isDirect).map(String).includes(key)) {
        return false;
      }

      return isDirect
        ? !this.matchesDirectActiveFilters(row)
        : !this.matchesAllocationActiveFilters(row, { applyLockedFilter: true });
    },

    matchesAllocationActiveFilters(r, options = {}) {
      const applyLockedFilter = options.applyLockedFilter !== false;
      if (!r || this.isDeletedAllocationRow(r)) return false;

      // Quick keyword search across product/JAN.
      if (this.allocationKeywordSearch.trim()) {
        if (!this.matchesAllocationKeyword(r, this.allocationKeywordSearch)) return false;
      }

      const statusFilter = this.allocationStatusFilter
        || this.getAllocationStatusFilterFromLegacy(this.lockedVisibilityFilter, this.printedFilter);
      const showingWarehouseTransferPending = ['warehouse_transfer_creatable', 'unprinted'].includes(statusFilter);

      // Status filters that target output rows must include locked rows.
      if (applyLockedFilter) {
        if (statusFilter === 'hide') {
          if (this.isRowLocked(r)) return false;
        } else if (statusFilter === 'locked') {
          if (!this.isRowLocked(r)) return false;
        }
      }

      if (showingWarehouseTransferPending) {
        if (!this.isWarehouseTransferWorkflowPendingRow(r)) return false;
      } else if (statusFilter === 'printed') {
        if (!this.isAllocationOutputCreated(r)) return false;
      }

      return true;
    },

    getAllocationFilteredRows(options = {}) {
      const sourceRows = this.rows.filter(row => !this.isDeletedAllocationRow(row));
      const filtered = sourceRows.filter(row => this.matchesAllocationActiveFilters(row, options));

      return this.sortRowsByOrderDateDesc(this.mergeFilterRetainedRows(filtered, sourceRows, false));
    },

    getVisibleRows() {
      return this.getAllocationFilteredRows({ applyLockedFilter: true });
    },

    matchesDirectActiveFilters(r) {
      if (!r) return false;

      if (this.directKeywordSearch.trim()) {
        if (!this.matchesAllocationKeyword(r, this.directKeywordSearch)) return false;
      }

      if (this.directDateFrom || this.directDateTo) {
        const d = r.orderDate || '';
        if (!(d >= (this.directDateFrom || '0000-00-00') && d <= (this.directDateTo || '9999-12-31'))) return false;
      }

      if (this.directProductSearch.trim()) {
        const q = this.directProductSearch;
        const meta = this.getProductMeta(r.productCode);
        if (!this.matchesAllSearchKeywords([r.productCode, r.name, r.jan, meta.code, meta.name, meta.jan], q)) return false;
      }

      if (this.directOrderToSearch.trim()) {
        const q = this.directOrderToSearch;
        if (!this.matchesAllSearchKeywords(this.getOrderToSearchFields(r), q)) return false;
      }

      if (this.directSupplierSearch.trim()) {
        const q = this.directSupplierSearch;
        if (!this.matchesAllSearchKeywords(this.getSupplierSearchFields(r), q)) return false;
      }

      if (this.directDeliveryFrom || this.directDeliveryTo) {
        const d = r.deliveryDate || '';
        if (!d) return false;
        if (!(d >= (this.directDeliveryFrom || '0000-00-00') && d <= (this.directDeliveryTo || '9999-12-31'))) return false;
      }

      if (this.directCheckedOnly && !r.checked) return false;
      if (this.directPrintedFilter === 'printed') {
        if (!this.isTransferSlipCreated(r)) return false;
      } else if (this.directPrintedFilter === 'unprinted') {
        if (this.isTransferSlipCreated(r)) return false;
      }

      return true;
    },

    getVisibleDirectRows() {
      const sourceRows = this.directRows;
      const filtered = sourceRows.filter(row => this.matchesDirectActiveFilters(row));

      return this.sortRowsByOrderDateDesc(this.mergeFilterRetainedRows(filtered, sourceRows, true));
    },

    getAllocationTotalRowCount() {
      return this.rows.filter(row => !this.isDeletedAllocationRow(row)).length;
    },

    getDirectTotalRowCount() {
      return this.directRows.length;
    },

    clearFilters() {
      this.allocationKeywordSearch = '';
      this.orderDateFrom = '';
      this.orderDateTo = '';
      this.productSearch = '';
      this.orderToSearch = '';
      this.supplierSearch = '';
      this.deliveryDateFrom = '';
      this.deliveryDateTo = '';
      this.syncAllocationStatusFilter('hide');
      this.clearFilterRetainedRows(false);
      Object.assign(this.allocationFilterDraft, {
        keywordSearch: '',
        orderDateFrom: '',
        orderDateTo: '',
        productSearch: '',
        orderToSearch: '',
        supplierSearch: '',
        deliveryDateFrom: '',
        deliveryDateTo: '',
        statusFilter: 'hide',
        lockedVisibilityFilter: 'hide',
        printedFilter: 'all',
      });
      this.persistFilters();
    },

    clearDirectFilters() {
      this.directKeywordSearch = '';
      this.directDateFrom = getTodayString();
      this.directDateTo = getTodayString();
      this.directProductSearch = '';
      this.directOrderToSearch = '';
      this.directSupplierSearch = '';
      this.directDeliveryFrom = '';
      this.directDeliveryTo = '';
      this.directCheckedOnly = false;
      this.directPrintedFilter = 'all';
      this.clearFilterRetainedRows(true);
      Object.assign(this.directFilterDraft, {
        keywordSearch: '',
        dateFrom: getTodayString(),
        dateTo: getTodayString(),
        productSearch: '',
        orderToSearch: '',
        supplierSearch: '',
        deliveryFrom: '',
        deliveryTo: '',
        checkedOnly: false,
        printedFilter: 'all',
      });
      this.persistFilters();
    },

    clearStoreFilters() {
      this.storeProductSearch = '';
      this.storeConfirmStatus = 'unconfirmed';
      this.storeDistributionType = 'all';
      Object.assign(this.storeFilterDraft, {
        productSearch: '',
        confirmStatus: 'unconfirmed',
        distributionType: 'all',
      });
      this.clearStoreRowsCache();
      this.persistFilters();
    },

    // ---------- Delete selection helpers ----------
    getDeleteSelectionIds(isDirect = false) {
      return isDirect ? this.directDeleteSelectedRowIds : this.deleteSelectedRowIds;
    },

    setDeleteSelectionIds(isDirect = false, ids = []) {
      const normalized = Array.from(new Set(ids.map(id => String(id || '')).filter(id => id !== '')));
      if (isDirect) {
        this.directDeleteSelectedRowIds = normalized;
      } else {
        this.deleteSelectedRowIds = normalized;
      }
    },

    getVisibleRowsForDeleteMode(isDirect = false) {
      return isDirect ? this.getVisibleDirectRows() : this.getVisibleRows();
    },

    getRowsForDeleteMode(isDirect = false) {
      return isDirect ? this.directRows : this.rows;
    },

    getDeleteSelectableRows(isDirect = false) {
      return this.getVisibleRowsForDeleteMode(isDirect)
        .filter(row => row && !this.isRowDeleteLocked(row));
    },

    getSelectedDeleteRows(isDirect = false) {
      const selectedIds = new Set(this.getDeleteSelectionIds(isDirect));
      return this.getDeleteSelectableRows(isDirect)
        .filter(row => selectedIds.has(String(row.id || '')));
    },

    getSelectedDeleteRowCount(isDirect = false) {
      return this.getSelectedDeleteRows(isDirect).length;
    },

    isRowDeleteSelected(row, isDirect = false) {
      if (!row || this.isRowDeleteLocked(row)) return false;
      return this.getDeleteSelectionIds(isDirect).includes(String(row.id || ''));
    },

    setRowDeleteSelected(row, isDirect = false, selected = false) {
      if (!row || this.isRowDeleteLocked(row)) return;

      const rowId = String(row.id || '');
      if (!rowId) return;

      const selectedIds = new Set(this.getDeleteSelectionIds(isDirect));
      if (selected) {
        selectedIds.add(rowId);
      } else {
        selectedIds.delete(rowId);
      }

      this.setDeleteSelectionIds(isDirect, Array.from(selectedIds));
    },

    allVisibleDeleteSelected(isDirect = false) {
      const rows = this.getDeleteSelectableRows(isDirect);
      if (rows.length === 0) return false;

      const selectedIds = new Set(this.getDeleteSelectionIds(isDirect));
      return rows.every(row => selectedIds.has(String(row.id || '')));
    },

    toggleVisibleDeleteSelection(isDirect = false, selected = false) {
      const selectedIds = new Set(this.getDeleteSelectionIds(isDirect));
      this.getDeleteSelectableRows(isDirect).forEach(row => {
        const rowId = String(row.id || '');
        if (!rowId) return;

        if (selected) {
          selectedIds.add(rowId);
        } else {
          selectedIds.delete(rowId);
        }
      });

      this.setDeleteSelectionIds(isDirect, Array.from(selectedIds));
    },

    pruneDeleteSelection(isDirect = false) {
      const rowIds = new Set(
        this.getRowsForDeleteMode(isDirect)
          .filter(row => row && !this.isRowDeleteLocked(row))
          .map(row => String(row.id || ''))
      );

      this.setDeleteSelectionIds(
        isDirect,
        this.getDeleteSelectionIds(isDirect).filter(id => rowIds.has(String(id)))
      );
    },

    deleteSelectedRows(isDirect = false) {
      const targetRows = this.getSelectedDeleteRows(isDirect);
      if (targetRows.length === 0) {
        this.openModal('エラー', '削除できる選択行がありません。');
        return;
      }

      this.openConfirm(targetRows.length + '件のデータを削除しますか？', () => {
        const targetIds = new Set(targetRows.map(row => String(row.id || '')));
        const targetKeys = new Set(targetRows.map(row => this.getAllocationRowDeleteKey(row)).filter(key => key !== ''));

        if (isDirect) {
          this.directRows = this.directRows.filter(row => !targetIds.has(String(row.id || '')));
          this.directDeleteSelectedRowIds = [];
          this.saveDirectData();
        } else {
          this.rememberDeletedAllocationRows(targetRows);
          this.rows = this.rows.filter(row => !targetKeys.has(this.getAllocationRowDeleteKey(row)));
          this.deleteSelectedRowIds = [];
          this.saveData();
        }

        this.showToast(targetRows.length + '件のデータを削除しました');
      }, 'delete');
    },

    // ---------- Check helpers ----------
    allVisibleChecked() {
      const visible = this.getVisibleRows().filter(r => !this.isRowLocked(r) && this.hasRowProductInfo(r));
      if (visible.length === 0) {
        const allVisible = this.getVisibleRows().filter(r => this.hasRowProductInfo(r));
        return allVisible.length > 0 && allVisible.every(r => r.checked);
      }

      return visible.length > 0 && visible.every(r => r.checked);
    },

    hasCheckedRows() {
      return this.hasReprintCandidateRows();
    },

    isActionableCheckedRow(row) {
      return !!(row?.checked && this.hasRowProductInfo(row) && !this.isRowLocked(row));
    },

    isOrderCandidateCreatableCheckedRow(row) {
      return !!(
        row?.checked &&
        this.hasRowProductInfo(row) &&
        !this.isWarehouseTransferCreated(row)
      );
    },

    hasOrderCandidateQuantity(row, allocTotalResolver = row => this.calcAllocTotal(row)) {
      const allocTotal = toInt(allocTotalResolver(row));
      const hasOrderQuantity = toInt(row?.poCase) > 0 || toInt(row?.poEach) > 0;

      return allocTotal > 0 || hasOrderQuantity;
    },

    getOrderCandidateGeneratableRows(rows = this.getVisibleRows(), allocTotalResolver = row => this.calcAllocTotal(row)) {
      return rows.filter(row =>
        this.isOrderCandidateCreatableCheckedRow(row) && this.hasOrderCandidateQuantity(row, allocTotalResolver)
      );
    },

    getOrderCandidatePastDateRows(rows = []) {
      const today = getTodayString();

      return rows.filter(row => {
        const orderDate = this.normalizeFlexibleDate(row?.orderDate || '') || '';
        const deliveryDate = this.normalizeFlexibleDate(row?.deliveryDate || '') || '';

        return (orderDate !== '' && orderDate < today) || (deliveryDate !== '' && deliveryDate < today);
      });
    },

    hasOrderCandidatePastDateRows(rows = []) {
      return this.getOrderCandidatePastDateRows(rows).length > 0;
    },

    getOrderCandidateDateRefreshConfirmMessage() {
      return '過去日付のデータがあります。\n発注日と納品希望日を本日基準に更新して発注候補を生成します。\nよろしいですか？';
    },

    getCheckedAlreadyGeneratedOrderCandidateRows(rows = this.getVisibleRows()) {
      return rows.filter(row =>
        row?.checked &&
        this.hasRowProductInfo(row) &&
        this.isOrderCandidateCreated(row) &&
        !this.isWarehouseTransferCreated(row)
      );
    },

    hasOrderCandidateGeneratableRows(rows = this.getVisibleRows(), allocTotalResolver = row => this.calcAllocTotal(row)) {
      return this.getOrderCandidateGeneratableRows(rows, allocTotalResolver).length > 0;
    },

    getDirectOrderCandidateGeneratableRows(rows = this.getVisibleDirectRows()) {
      return this.getOrderCandidateGeneratableRows(rows, row => this.calcDirectAllocTotal(row));
    },

    hasDirectOrderCandidateGeneratableRows(rows = this.getVisibleDirectRows()) {
      return this.getDirectOrderCandidateGeneratableRows(rows).length > 0;
    },

    getDirectRequestPrintableRows(rows = this.getVisibleDirectRows()) {
      return rows.filter(row =>
        this.isActionableCheckedRow(row) &&
        this.calcDirectAllocTotal(row) > 0
      );
    },

    hasDirectRequestPrintableRows(rows = this.getVisibleDirectRows()) {
      return this.getDirectRequestPrintableRows(rows).length > 0;
    },

    openDirectRequestOutput() {
      if (!this.hasDirectRequestPrintableRows()) {
        this.openModal('エラー', '依頼書を出力するデータがありません。確定チェックと分配数を確認してください。');
        return;
      }

      this.slipRemarkText = '';
      this.slipTantouName = '';
      this.slipManagementComment = '';
      this.slipRemarkAllowDetailMemo = false;
      this.slipRemarkDetailRows = [];
      this.slipRemarkMode = 'request';
      this.slipRemarkCallback = opts => this.printDirectRequestForm(opts);
      this.slipRemarkVisible = true;
    },

    isTransferSlipCreated(row) {
      return !!(
        row?.printed ||
        row?.transferSlipCreatedAt ||
        (Array.isArray(row?.transferSlipQueueIds) && row.transferSlipQueueIds.length > 0)
      );
    },

    isAllocationOutputCreated(row) {
      return this.isTransferSlipCreated(row) || this.isWarehouseTransferCreated(row);
    },

    hasPrintableAllocation(row) {
      return this.hasRowProductInfo(row);
    },

    getReprintCandidateRows(rows = this.getVisibleRows()) {
      return rows.filter(row =>
        this.isReprintCandidateRow(row) &&
        this.hasPrintableAllocation(row)
      );
    },

    isReprintCandidateRow(row) {
      return this.isTransferSlipCreated(row) || this.isWarehouseTransferCreated(row);
    },

    hasReprintCandidateRows(rows = this.getVisibleRows()) {
      return this.getReprintCandidateRows(rows).length > 0;
    },

    matchesReprintSearch(row) {
      const keyword = this.normalizeSearchText(this.reprintSearch);
      if (!keyword) return true;

      const meta = this.getProductMeta(row.productCode);
      return this.matchesAllSearchKeywords([
        row.productCode,
        row.jan,
        meta.code,
        meta.jan,
      ], keyword);
    },

    getActiveReprintSelectionRows() {
      if (Array.isArray(this.reprintSelectionRows) && this.reprintSelectionRows.length > 0) {
        return this.reprintSelectionRows;
      }

      return this.getReprintCandidateRows();
    },

    isReprintRowOutputSelectable(row) {
      if (this.reprintSelectionMode === 'store-output' && row?.storeSlipStoreKey) {
        return this.hasRowProductInfo(row) && String(row.storeSlipStoreKey || '') !== '';
      }

      return this.hasPrintableAllocation(row);
    },

    getReprintRowConfirmStatusLabel(row) {
      return row?.checked ? '確定' : '未確定';
    },

    getReprintRowConfirmStatusClass(row) {
      return row?.checked
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-amber-100 text-amber-700';
    },

    getReprintAllowedStoreKeySet() {
      const keys = Array.isArray(this.reprintSelectionAllowedStoreKeys)
        ? this.reprintSelectionAllowedStoreKeys.map(key => String(key)).filter(key => key !== '')
        : [];

      return keys.length > 0 ? new Set(keys) : null;
    },

    openReprintSelectionDialog({
      mode = 'reprint',
      title = '伝票再出力対象を選択',
      description = '表示中の確定済みデータから、再出力する明細を選択します。',
      confirmLabel = '再出力へ',
      rows = [],
      storeKeys = [],
    } = {}) {
      const candidates = Array.isArray(rows) ? rows : [];

      this.reprintSelectionMode = mode;
      this.reprintSelectionTitle = title;
      this.reprintSelectionDescription = description;
      this.reprintSelectionConfirmLabel = confirmLabel;
      this.reprintSelectionRows = candidates;
      this.reprintSelectionAllowedStoreKeys = Array.isArray(storeKeys)
        ? storeKeys.map(key => String(key)).filter(key => key !== '')
        : [];
      this.reprintSearch = '';
      this.reprintSelectedRowIds = candidates
        .filter(row => this.isReprintRowOutputSelectable(row))
        .map(row => String(row.id));
      this.reprintSelectedStoreKeys = this.getReprintCandidateStores(candidates).map(dest => String(dest.key));
      this.reprintSelectionVisible = true;
    },

    getFilteredReprintRows(rows = this.getActiveReprintSelectionRows()) {
      return rows.filter(row => this.matchesReprintSearch(row));
    },

    getPrintableRows(rows = this.getVisibleRows()) {
      return this.getFilteredReprintRows(this.getReprintCandidateRows(rows));
    },

    openReprintSelection() {
      const candidates = this.getReprintCandidateRows();
      if (candidates.length === 0) {
        this.openModal('エラー', '再出力できる伝票データがありません。');
        return;
      }

      this.openReprintSelectionDialog({
        mode: 'reprint',
        title: '伝票再出力対象を選択',
        description: '表示中の確定済みデータから、再出力する明細を選択します。',
        confirmLabel: '再出力へ',
        rows: candidates,
        storeKeys: [],
      });
    },

    isReprintRowSelected(row) {
      return this.reprintSelectedRowIds.map(id => String(id)).includes(String(row?.id || ''));
    },

    setReprintRowSelected(row, checked) {
      const rowId = String(row?.id || '');
      if (!rowId) return;
      if (checked && !this.isReprintRowOutputSelectable(row)) return;

      const selected = new Set(this.reprintSelectedRowIds.map(id => String(id)));
      if (checked) {
        selected.add(rowId);
      } else {
        selected.delete(rowId);
      }
      this.reprintSelectedRowIds = Array.from(selected);
      this.normalizeReprintStoreSelection();
    },

    toggleAllReprintRows(checked) {
      this.reprintSelectedRowIds = checked
        ? this.getFilteredReprintRows()
          .filter(row => this.isReprintRowOutputSelectable(row))
          .map(row => String(row.id))
        : [];
      this.normalizeReprintStoreSelection();
    },

    getSelectedReprintRows() {
      const selected = new Set(this.reprintSelectedRowIds.map(id => String(id)));
      return this.getFilteredReprintRows().filter(row =>
        selected.has(String(row.id)) &&
        this.isReprintRowOutputSelectable(row)
      );
    },

    getReprintRowStoreLabel(row) {
      if (row?.storeSlipStoreKey) {
        return this.getStoreLabel(String(row.storeSlipStoreKey));
      }

      const stores = this.getReprintCandidateStores([row]);
      if (stores.length === 0) return '-';

      return stores.map(dest => '[' + dest.key + '] ' + dest.name).join(' / ');
    },

    getReprintCandidateStores(rows = this.getSelectedReprintRows()) {
      const allowedStoreKeys = this.getReprintAllowedStoreKeySet();
      const hasPrintableRows = rows.some(row => this.isReprintRowOutputSelectable(row));

      return DESTS.filter(dest =>
        (!allowedStoreKeys || allowedStoreKeys.has(String(dest.key))) &&
        hasPrintableRows
      );
    },

    normalizeReprintStoreSelection() {
      const validRowIds = new Set(
        this.getFilteredReprintRows()
          .filter(row => this.isReprintRowOutputSelectable(row))
          .map(row => String(row.id))
      );
      this.reprintSelectedRowIds = this.reprintSelectedRowIds.filter(id => validRowIds.has(String(id)));

      const validKeys = new Set(this.getReprintCandidateStores().map(dest => String(dest.key)));
      this.reprintSelectedStoreKeys = this.reprintSelectedStoreKeys.filter(key => validKeys.has(String(key)));

      if (this.reprintSelectedStoreKeys.length === 0 && validKeys.size > 0) {
        this.reprintSelectedStoreKeys = Array.from(validKeys);
      }
    },

    isReprintStoreSelected(dest) {
      return this.reprintSelectedStoreKeys.map(key => String(key)).includes(String(dest?.key || ''));
    },

    setReprintStoreSelected(dest, checked) {
      const key = String(dest?.key || '');
      if (!key) return;

      const selected = new Set(this.reprintSelectedStoreKeys.map(value => String(value)));
      if (checked) {
        selected.add(key);
      } else {
        selected.delete(key);
      }
      this.reprintSelectedStoreKeys = Array.from(selected);
    },

    toggleAllReprintStores(checked) {
      this.reprintSelectedStoreKeys = checked
        ? this.getReprintCandidateStores().map(dest => String(dest.key))
        : [];
    },

    getSelectedReprintStores() {
      const selected = new Set(this.reprintSelectedStoreKeys.map(key => String(key)));
      return this.getReprintCandidateStores().filter(dest => selected.has(String(dest.key)));
    },

    openSlipRemarkForSelectedReprint() {
      const dataset = this.getSelectedReprintRows();
      if (dataset.length === 0) {
        this.openModal('エラー', '再出力するデータを選択してください。');
        return;
      }
      const stores = this.getSelectedReprintStores();
      if (stores.length === 0) {
        this.openModal('エラー', '伝票出力する店舗を選択してください。');
        return;
      }

      this.reprintSelectionVisible = false;
      const includeZeroAllocRows = true;
      if (this.reprintSelectionMode === 'store-output') {
        this.openStoreSlipLayoutSelection(dataset, stores, { includeZeroAllocRows });
        return;
      }

      this.printSlips({}, dataset, stores, { includeZeroAllocRows });
    },

    openStoreSlipLayoutSelection(dataset, stores, options = {}) {
      this.slipRemarkVisible = false;
      this.slipRemarkCallback = null;
      this.slipRemarkText = '';
      this.slipRemarkAllowDetailMemo = false;
      this.slipRemarkDetailRows = [];
      this.slipLayoutSelectionDataset = Array.isArray(dataset) ? dataset : [];
      this.slipLayoutSelectionStores = Array.isArray(stores) ? stores : [];
      this.slipLayoutSelectionOptions = {
        includeZeroAllocRows: options.includeZeroAllocRows !== false,
      };
      this.slipLayoutSelectionVisible = true;
    },

    closeSlipLayoutSelection() {
      this.slipLayoutSelectionVisible = false;
      this.slipLayoutSelectionDataset = [];
      this.slipLayoutSelectionStores = [];
      this.slipLayoutSelectionOptions = { includeZeroAllocRows: true };
    },

    printSelectedStoreSlipLayout(layout) {
      const dataset = this.slipLayoutSelectionDataset;
      const stores = this.slipLayoutSelectionStores;
      const options = {
        ...this.slipLayoutSelectionOptions,
        layout: layout === 'legacy' ? 'legacy' : 'checklist',
      };

      if (!Array.isArray(dataset) || dataset.length === 0 || !Array.isArray(stores) || stores.length === 0) {
        this.closeSlipLayoutSelection();
        this.openModal('エラー', '伝票出力の対象が取得できません。もう一度、伝票出力ボタンからやり直してください。');
        return;
      }

      this.closeSlipLayoutSelection();
      if (options.layout === 'legacy') {
        this.openLegacySlipRemark(dataset, stores, options);
        return;
      }

      this.printSlips({}, dataset, stores, options);
    },

    openLegacySlipRemark(dataset, stores, options = {}) {
      this.slipRemarkText = '';
      this.slipTantouName = '';
      this.slipManagementComment = '';
      this.slipRemarkMode = 'slip';
      this.slipRemarkAllowDetailMemo = true;
      this.slipRemarkDetailRows = this.buildSlipRemarkDetailRows(dataset);
      this.slipRemarkCallback = opts => {
        this.applySlipRemarkDetailRows(dataset);
        this.printSlips(opts, dataset, stores, options);
      };
      this.slipRemarkVisible = true;
    },

    buildSlipRemarkDetailRows(dataset) {
      const seen = new Set();
      return (Array.isArray(dataset) ? dataset : [])
        .filter(row => {
          const id = String(row?.id || '');
          if (id === '' || seen.has(id)) return false;
          seen.add(id);
          return true;
        })
        .map(row => ({
          id: String(row.id),
          productCode: row.productCode || '',
          name: row.name || '',
          memo: row.memo || '',
        }));
    },

    applySlipRemarkDetailRows(dataset = []) {
      const rowsById = new Map();
      [this.rows, this.directRows, Array.isArray(dataset) ? dataset : []].forEach(rows => {
        if (!Array.isArray(rows)) return;
        rows.forEach(row => {
          const id = String(row?.id || '');
          if (id !== '') rowsById.set(id, row);
        });
      });

      this.slipRemarkDetailRows.forEach(detail => {
        const row = rowsById.get(String(detail?.id || ''));
        if (row) row.memo = detail.memo || '';
      });
    },

    closeSlipRemarkPopup() {
      this.slipRemarkVisible = false;
      this.slipRemarkCallback = null;
      this.slipRemarkAllowDetailMemo = false;
      this.slipRemarkDetailRows = [];
    },

    submitSlipRemark() {
      const callback = this.slipRemarkCallback;
      const opts = {
        remark: this.slipRemarkText,
        tantou: this.slipTantouName,
        mgmtComment: this.slipManagementComment,
      };

      if (typeof callback !== 'function') {
        this.closeSlipRemarkPopup();
        this.openModal('エラー', '出力処理の準備ができていません。もう一度、出力ボタンからやり直してください。');
        return;
      }

      callback(opts);
      this.closeSlipRemarkPopup();
    },

    getWarehouseTransferSourceRows() {
      return this.getAllocationFilteredRows({ applyLockedFilter: false });
    },

    isWarehouseTransferPendingRow(row) {
      return !!(
        (row?.checked || this.isOrderCandidateCreated(row)) &&
        this.hasRowProductInfo(row) &&
        !this.isWarehouseTransferCreated(row)
      );
    },

    hasWarehouseTransferTargetDestination(row) {
      return DESTS.some(dest =>
        toInt(row?.['alloc_' + dest.key] || 0) > 0
      );
    },

    getWarehouseTransferPendingRows(rows = null) {
      const sourceRows = Array.isArray(rows) ? rows : this.getWarehouseTransferSourceRows();

      return sourceRows.filter(row => this.isWarehouseTransferPendingRow(row));
    },

    hasWarehouseTransferPendingRows(rows = null) {
      return this.getWarehouseTransferPendingRows(rows).length > 0;
    },

    getWarehouseTransferCreatableRows(rows = null) {
      const sourceRows = Array.isArray(rows) ? rows : this.getWarehouseTransferPendingRows();

      return sourceRows.filter(row => this.isWarehouseTransferCreatableRow(row));
    },

    isWarehouseTransferCreatableRow(row) {
      return this.isWarehouseTransferPendingRow(row) && this.hasWarehouseTransferTargetDestination(row);
    },

    isWarehouseTransferSlipPendingRow(row) {
      return !!(
        this.hasRowProductInfo(row) &&
        this.isWarehouseTransferCreated(row) &&
        !this.isTransferSlipCreated(row)
      );
    },

    isWarehouseTransferWorkflowPendingRow(row) {
      return this.isWarehouseTransferCreatableRow(row) || this.isWarehouseTransferSlipPendingRow(row);
    },

    getWarehouseTransferSlipPendingRows(rows = null) {
      const sourceRows = Array.isArray(rows) ? rows : this.getWarehouseTransferSourceRows();

      return sourceRows.filter(row => this.isWarehouseTransferSlipPendingRow(row));
    },

    getWarehouseTransferWorkflowPendingRows(rows = null) {
      const sourceRows = Array.isArray(rows) ? rows : this.getWarehouseTransferSourceRows();

      return sourceRows.filter(row => this.isWarehouseTransferWorkflowPendingRow(row));
    },

    hasWarehouseTransferCreatableRows(rows = null) {
      return this.getWarehouseTransferWorkflowPendingRows(rows).length > 0;
    },

    getWarehouseTransferButtonTitle() {
      if (this.warehouseTransferCreating) return '倉庫移動生成中です';
      if (this.transferSlipCreating) return '伝票出力中です';
      if (this.hasWarehouseTransferCreatableRows()) return '倉庫移動生成または伝票出力できます';

      return '倉庫移動・伝票待ちのデータがありません';
    },

    async refreshWarehouseTransferCandidateStocks(rows = this.getWarehouseTransferPendingRows()) {
      const targets = rows
        .map(row => {
          const query = String(row.productCode || row.jan || '').trim();
          if (!query) return null;

          return {
            row,
            query,
            orderDate: this.normalizeFlexibleDate(row.orderDate || '') || getTodayString(),
            orderToSearch: row.orderToCode || row.orderTo || '',
            supplierSearch: row.supplierCode || row.supplier || '',
          };
        })
        .filter(target => target !== null);

      if (targets.length === 0) return;

      const productsByRowId = await this.fetchProductCandidatesBatchForRows(targets);
      targets.forEach(({ row }) => {
        const product = this.pickProductForExistingRow(row, productsByRowId.get(String(row.id)) || []);
        if (product) {
          this.applyProductStockToRow(row, product);
        }
      });
    },

    toggleAllCheckedVisible() {
      const flag = !this.allVisibleChecked();
      const visibleRows = this.getVisibleRows();
      const confirmableRows = visibleRows.filter(r => !this.isRowLocked(r) && this.hasRowProductInfo(r));
      const visibleIds = new Set(confirmableRows.map(r => r.id));

      if (flag && visibleRows.length > 0 && confirmableRows.length === 0) {
        this.openModal('注意', this.getMissingProductInfoMessage());
        return;
      }

      this.rows.forEach(r => {
        if (visibleIds.has(r.id)) {
          r.checked = flag;
        }
      });
    },

    getActiveRows() {
      return this.getVisibleRows().filter(r => r.checked);
    },

    clearStoreRowsCache() {
      this.storeFilteredRowsCacheKey = '';
      this.storeFilteredRowsCache = [];
      this.storeProductsCache = {};
    },

    touchStoreRows() {
      this.storeRowsVersion = (toInt(this.storeRowsVersion || 0) + 1);
      this.clearStoreRowsCache();
    },

    getStoreRowsCacheKey() {
      return [
        this.storeRowsVersion || 0,
        this.rows.length,
        this.directRows.length,
        this.storeProductSearch || '',
        this.storeConfirmStatus || 'confirmed',
        this.storeDistributionType || 'all',
      ].join('|');
    },

    markStoreSourceType(row, type) {
      if (!row) return row;

      if (row.storeSourceType === type) {
        return row;
      }

      try {
        Object.defineProperty(row, 'storeSourceType', {
          value: type,
          enumerable: false,
          configurable: true,
          writable: true,
        });
      } catch (e) {
        row.storeSourceType = type;
      }

      return row;
    },

    getStoreSourceRows() {
      return [
        ...this.rows.map(row => this.markStoreSourceType(row, 'allocation')),
        ...this.directRows.map(row => this.markStoreSourceType(row, 'direct')),
      ];
    },

    getStoreDistributionTypeLabel(type) {
      return type === 'direct' ? '直送' : '通常';
    },

    matchesStoreDistributionType(row) {
      const type = this.storeDistributionType || 'all';
      if (type === 'all') return true;

      return (row?.storeSourceType || 'allocation') === type;
    },

    getStoreSortedRows(rows = this.getStoreSourceRows()) {
      return this.sortRowsByOrderDateDesc(rows);
    },

    matchesStoreProductSearch(row) {
      const keyword = this.normalizeSearchText(this.storeProductSearch);
      if (!keyword) return true;

      const meta = this.getProductMeta(row.productCode);
      return this.matchesAllSearchKeywords([
        row.productCode,
        row.name,
        meta.code,
        meta.name,
      ], keyword);
    },

    matchesStoreConfirmStatus(row) {
      const status = this.storeConfirmStatus || 'unconfirmed';
      if (status === 'all') return true;

      return status === 'confirmed' ? !!row?.checked : !row?.checked;
    },

    filterStoreRows(rows) {
      return this.getStoreSortedRows(rows).filter(row =>
        this.matchesStoreProductSearch(row) &&
        this.matchesStoreConfirmStatus(row) &&
        this.matchesStoreDistributionType(row)
      );
    },

    getStoreFilteredRows(rows) {
      if (Array.isArray(rows)) {
        return this.filterStoreRows(rows);
      }

      const cacheKey = this.getStoreRowsCacheKey();
      if (this.storeFilteredRowsCacheKey !== cacheKey) {
        this.storeFilteredRowsCache = this.filterStoreRows(this.getStoreSourceRows());
        this.storeFilteredRowsCacheKey = cacheKey;
        this.storeProductsCache = {};
      }

      return this.storeFilteredRowsCache;
    },

    getStoreSlipRowKey(row) {
      if (typeof row === 'string' || typeof row === 'number') {
        return String(row || '');
      }

      const baseKey = String(
        row?.id
        || row?.sourceKey
        || row?.source_key
        || row?.candidateKey
        || row?.candidate_key
        || [row?.productCode || row?.product_code || '', row?.orderDate || row?.order_date || '', row?.deliveryDate || row?.delivery_date || ''].join('|')
      );

      return row?.storeSourceType ? row.storeSourceType + '::' + baseKey : baseKey;
    },

    makeStoreSlipSelectionKey(rowRef, storeKey) {
      return String(storeKey || '') + '::' + this.getStoreSlipRowKey(rowRef);
    },

    ensureStoreSlipSelectionState() {
      if (!Array.isArray(this.selectedStoreSlipKeys)) {
        this.selectedStoreSlipKeys = [];
      }
    },

    isStoreSlipRowSelected(rowRef, storeKey) {
      this.ensureStoreSlipSelectionState();

      return this.selectedStoreSlipKeys.includes(this.makeStoreSlipSelectionKey(rowRef, storeKey));
    },

    setStoreSlipRowSelected(rowRef, storeKey, checked) {
      this.ensureStoreSlipSelectionState();

      const rowKey = this.getStoreSlipRowKey(rowRef);
      if (!rowKey || !storeKey) return;

      const key = this.makeStoreSlipSelectionKey(rowKey, storeKey);
      const selected = new Set(this.selectedStoreSlipKeys);

      if (checked) {
        selected.add(key);
      } else {
        selected.delete(key);
      }

      this.selectedStoreSlipKeys = Array.from(selected);
    },

    getStoreSlipSelectableProducts(storeKey) {
      return this.getStoreFilteredProducts(storeKey);
    },

    hasStoreSlipSelectableRows(storeKey) {
      return this.getStoreSlipSelectableProducts(storeKey).length > 0;
    },

    isSomeStoreSlipRowsSelected(storeKey) {
      const products = this.getStoreSlipSelectableProducts(storeKey);
      if (products.length === 0) return false;

      return products.some(product => this.isStoreSlipRowSelected(product.rowKey, storeKey));
    },

    areAllStoreSlipRowsSelected(storeKey) {
      const products = this.getStoreSlipSelectableProducts(storeKey);
      if (products.length === 0) return false;

      return products.every(product => this.isStoreSlipRowSelected(product.rowKey, storeKey));
    },

    toggleStoreSlipRows(storeKey, checked) {
      this.getStoreSlipSelectableProducts(storeKey).forEach(product => {
        this.setStoreSlipRowSelected(product.rowKey, storeKey, checked);
      });
    },

    getSelectedStoreSlipEntries(rows = this.getStoreFilteredRows()) {
      this.ensureStoreSlipSelectionState();

      if (this.selectedStores.length === 0 || this.selectedStoreSlipKeys.length === 0) return [];

      const selected = new Set(this.selectedStoreSlipKeys.map(key => String(key)));
      const entries = [];

      rows.forEach(row => {
        this.selectedStores.forEach(storeKey => {
          const key = this.makeStoreSlipSelectionKey(row, storeKey);
          if (selected.has(key)) {
            entries.push({ row, storeKey });
          }
        });
      });

      return entries;
    },

    getStoreSlipOutputButtonLabel() {
      if (this.storeViewMode === 'individual') {
        return '伝票出力 (' + this.getSelectedStoreSlipEntries().length + '件)';
      }

      return '伝票出力 (' + this.selectedStores.length + '店舗)';
    },

    buildRowsForSelectedStoreSlipEntries(entries = this.getSelectedStoreSlipEntries()) {
      const rowsById = new Map();

      entries.forEach(({ row, storeKey }) => {
        const rowKey = this.getStoreSlipRowKey(row);
        if (!rowsById.has(rowKey)) {
          const cloned = { ...row };
          DESTS.forEach(dest => {
            cloned['alloc_' + dest.key] = 0;
            cloned['wish_' + dest.key] = 0;
          });
          rowsById.set(rowKey, cloned);
        }

        const clonedRow = rowsById.get(rowKey);
        clonedRow['alloc_' + storeKey] = toInt(row['alloc_' + storeKey] || 0);
        clonedRow['wish_' + storeKey] = toInt(row['wish_' + storeKey] || 0);
      });

      return Array.from(rowsById.values());
    },

    buildRowsForStoreSlipSelectionEntries(entries = []) {
      const rows = [];

      entries.forEach(({ row, storeKey }) => {
        const rowKey = this.getStoreSlipRowKey(row);
        const targetStoreKey = String(storeKey || '');
        if (!rowKey || !targetStoreKey) return;

        const cloned = { ...row };
        DESTS.forEach(dest => {
          cloned['alloc_' + dest.key] = 0;
          cloned['wish_' + dest.key] = 0;
        });
        cloned.id = rowKey + '::' + targetStoreKey;
        cloned.sourceRowId = row.id || rowKey;
        cloned.storeSlipStoreKey = targetStoreKey;
        cloned['alloc_' + targetStoreKey] = toInt(row['alloc_' + targetStoreKey] || 0);
        cloned['wish_' + targetStoreKey] = toInt(row['wish_' + targetStoreKey] || 0);
        rows.push(cloned);
      });

      return rows;
    },

    buildRowsForSelectedStoreKeys(rows = this.getStoreFilteredRows(), storeKeys = this.selectedStores) {
      const targetStoreKeys = Array.isArray(storeKeys)
        ? storeKeys.map(key => String(key)).filter(key => key !== '')
        : [];
      const entries = [];

      rows.forEach(row => {
        targetStoreKeys.forEach(storeKey => {
          entries.push({ row, storeKey });
        });
      });

      return this.buildRowsForSelectedStoreSlipEntries(entries);
    },

    buildSelectionRowsForSelectedStoreKeys(rows = this.getStoreFilteredRows(), storeKeys = this.selectedStores) {
      const targetStoreKeys = Array.isArray(storeKeys)
        ? storeKeys.map(key => String(key)).filter(key => key !== '')
        : [];
      const entries = [];

      rows.forEach(row => {
        targetStoreKeys.forEach(storeKey => {
          entries.push({ row, storeKey });
        });
      });

      return this.buildRowsForStoreSlipSelectionEntries(entries);
    },

    getPrintableDestsForSelectedStores() {
      if (this.selectedStores.length === 0) return [];

      const displayDestinations = this.getStoreDisplayDestinations();
      if (this.storeViewMode === 'individual') {
        const selectedStoreKeys = new Set(this.getSelectedStoreSlipEntries().map(entry => String(entry.storeKey)));
        return displayDestinations.filter(dest => selectedStoreKeys.has(String(dest.key)));
      }

      return this.selectedStores.map(key => displayDestinations.find(d => d.key === key)).filter(d => d);
    },

    getPrintableRowsForSelectedStores() {
      if (this.selectedStores.length === 0) return [];

      if (this.storeViewMode === 'individual') {
        return this.buildRowsForSelectedStoreSlipEntries();
      }

      return this.buildRowsForSelectedStoreKeys();
    },

    hasPrintableRowsForSelectedStores() {
      return this.getPrintableRowsForSelectedStores().length > 0;
    },

    hasStoreSlipOutputTarget() {
      if (this.storeViewMode === 'individual') {
        return this.getSelectedStoreSlipEntries().length > 0;
      }

      return this.buildSelectionRowsForSelectedStoreKeys().length > 0;
    },

    openStoreSlipOutput() {
      try {
        this.ensureStoreSlipSelectionState();

        if (!Array.isArray(this.selectedStores) || this.selectedStores.length === 0) {
          this.openModal('エラー', '伝票を出力する店舗を選択してください。');
          return;
        }

        this.normalizeDateFilters();
        this.normalizeRowsDateFields(this.rows, true);

        if (this.storeViewMode === 'individual' && this.getSelectedStoreSlipEntries().length === 0) {
          const hasSelectableRows = this.getStoreFilteredRows().length > 0;
          this.openModal(
            '注意',
            hasSelectableRows
              ? '伝票出力するレコードにチェックを入れてください。'
              : '選択店舗に希望または分配が入力されている行がありません。商品検索や条件も確認してください。'
          );
          return;
        }

        if (this.storeViewMode !== 'comparison' && this.getPrintableRowsForSelectedStores().length === 0) {
          this.openModal('注意', '選択店舗に希望または分配が入力されている行がありません。商品検索や条件も確認してください。');
          return;
        }

        const dataset = this.storeViewMode === 'comparison'
          ? this.buildSelectionRowsForSelectedStoreKeys()
          : this.getPrintableRowsForSelectedStores();
        const stores = this.getPrintableDestsForSelectedStores();

        if (dataset.length === 0) {
          this.openModal('注意', '表示できる分配データがありません。商品検索や条件も確認してください。');
          return;
        }

        if (stores.length === 0) {
          this.openModal('エラー', '伝票を出力する店舗を選択してください。');
          return;
        }

        if (this.storeViewMode === 'comparison') {
          this.openReprintSelectionDialog({
            mode: 'store-output',
            title: '伝票出力',
            description: '',
            confirmLabel: '出力へ',
            rows: dataset,
            storeKeys: stores.map(store => String(store.key)),
          });
          return;
        }

      this.openStoreSlipLayoutSelection(dataset, stores, { includeZeroAllocRows: true });
      } catch (error) {
        console.error('店舗別伝票出力エラー:', error);
        this.openModal('エラー', '伝票出力の準備でエラーが発生しました: ' + (error?.message || error));
      }
    },

    // ---------- Row actions ----------
    makeAllocationRow(overrides = {}) {
      return this.normalizeRowDateFields({
        id: uuid(),
        itemId: null,
        candidateKey: '',
        itemContractorId: null,
        contractorId: null,
        supplierId: null,
        contractorWarehouseId: null,
        productCode: '',
        jan: '',
        name: '',
        lot: 0,
        unitsPerCase: 0,
        purchaseUnit: 1,
        currentStock: 0,
        reserved: 0,
        reservedStock: 0,
        incomingQuantity: 0,
        incomingCount: 0,
        orderPoint: 0,
        shelfLocation: '',
        lastOrderDate: '',
        salesWeek1: 0,
        salesWeek2: 0,
        salesWeek3: 0,
        selectedWarehouseId: null,
        realWarehouseId: null,
        poCase: '',
        poEach: '',
        orderDate: getTodayString(),
        checked: false,
        printed: false,
        locked: false,
        transferSlipQueueIds: [],
        transferSlipCreatedAt: '',
        warehouseTransferGenerated: false,
        warehouseTransferQueueIds: [],
        warehouseTransferCreatedAt: '',
        orderCandidateGenerated: false,
        orderCandidateIds: [],
        orderCandidateCreatedAt: '',
        memo: '',
        itemContractorNote: '',
        destinationsExpanded: false,
        deliveryDate: '',
        suggestedDeliveryDate: '',
        deliveryDateCalculation: null,
        deliveryCourseId: null,
        deliveryCourseCode: '',
        deliveryCourseName: '',
        visibleDestinationKeys: [],
        ...buildDestKeys(),
        ...overrides,
      }, true);
    },

    addRow() {
      const row = this.makeAllocationRow();
      this.rows.unshift(row);
      this.showNewAllocationRow(row);

      this.$nextTick(() => {
        const scroller = this.$refs.allocationMainScroll;
        if (scroller && typeof scroller.scrollTo === 'function') {
          scroller.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
        }
      });
    },

    showNewAllocationRow(row) {
      this.allocationKeywordSearch = '';
      this.orderDateFrom = '';
      this.orderDateTo = '';
      this.productSearch = '';
      this.orderToSearch = '';
      this.supplierSearch = '';
      this.deliveryDateFrom = '';
      this.deliveryDateTo = '';
      this.syncAllocationStatusFilter('hide');

      Object.assign(this.allocationFilterDraft, {
        keywordSearch: '',
        orderDateFrom: '',
        orderDateTo: '',
        productSearch: '',
        orderToSearch: '',
        supplierSearch: '',
        deliveryDateFrom: '',
        deliveryDateTo: '',
        statusFilter: 'hide',
        lockedVisibilityFilter: 'hide',
        printedFilter: 'all',
      });
      this.persistFilters();
    },

    isBlankAllocationRow(row) {
      if (!row) return true;

      return !String(row.productCode || '').trim()
        && !String(row.jan || '').trim()
        && !String(row.name || '').trim()
        && !String(row.memo || '').trim()
        && toInt(row.poCase || 0) === 0
        && toInt(row.poEach || 0) === 0
        && this.calcWishTotal(row) === 0
        && this.calcAllocTotal(row) === 0
        && !row.checked
        && !this.isRowLocked(row);
    },

    getPersistableAllocationRows() {
      return this.rows
        .filter(row => !this.isBlankAllocationRow(row) && !this.isDeletedAllocationRow(row))
        .map(row => {
          const copy = { ...row };
          delete copy.storeSourceType;
          return copy;
        });
    },

    normalizeCsvText(value) {
      const text = String(value ?? '').replace(/^\uFEFF/, '').trim();
      return typeof text.normalize === 'function' ? text.normalize('NFKC') : text;
    },

    normalizeCsvKey(value) {
      return this.normalizeCsvText(value)
        .toLowerCase()
        .replace(/[\s　\[\]（）(){}｛｝\-_/／\\.:：・･]/g, '');
    },

    normalizeCsvCode(value) {
      return this.normalizeCsvText(value)
        .replace(/^'/, '')
        .replace(/\.0$/, '');
    },

    isBlankCsvRow(cells) {
      return !Array.isArray(cells) || cells.every(value => this.normalizeCsvText(value) === '');
    },

    parseCsvInteger(value) {
      const normalized = this.normalizeCsvText(value).replace(/,/g, '');
      if (!normalized || normalized === '　') return 0;

      const numeric = Number(normalized);
      if (Number.isFinite(numeric)) return Math.max(0, Math.trunc(numeric));

      const match = normalized.match(/-?\d+/);
      return match ? Math.max(0, Number(match[0])) : 0;
    },

    isCsvTruthy(value) {
      const normalized = this.normalizeCsvKey(value);
      return ['1', 'true', 'yes', 'y', 'on', 'checked', '済', '確定', '○', '〇', '✓', '✔'].includes(normalized);
    },

    detectCsvDelimiter(text) {
      const firstLine = String(text || '').split(/\r\n|\n|\r/).find(line => line.trim() !== '') || '';
      const commaCount = (firstLine.match(/,/g) || []).length;
      const tabCount = (firstLine.match(/\t/g) || []).length;
      const semicolonCount = (firstLine.match(/;/g) || []).length;

      if (tabCount > commaCount && tabCount >= semicolonCount) return '\t';
      if (semicolonCount > commaCount) return ';';
      return ',';
    },

    parseDelimitedText(text) {
      const delimiter = this.detectCsvDelimiter(text);
      const rows = [];
      let row = [];
      let field = '';
      let inQuotes = false;
      const input = String(text || '').replace(/^\uFEFF/, '');

      for (let i = 0; i < input.length; i++) {
        const char = input[i];

        if (inQuotes) {
          if (char === '"') {
            if (input[i + 1] === '"') {
              field += '"';
              i++;
            } else {
              inQuotes = false;
            }
          } else {
            field += char;
          }
          continue;
        }

        if (char === '"') {
          inQuotes = true;
        } else if (char === delimiter) {
          row.push(field);
          field = '';
        } else if (char === '\n') {
          row.push(field);
          rows.push(row);
          row = [];
          field = '';
        } else if (char === '\r') {
          row.push(field);
          rows.push(row);
          row = [];
          field = '';
          if (input[i + 1] === '\n') i++;
        } else {
          field += char;
        }
      }

      row.push(field);
      if (row.some(value => String(value || '').trim() !== '')) {
        rows.push(row);
      }

      return rows;
    },

    decodeCsvBuffer(buffer) {
      const bytes = new Uint8Array(buffer.slice(0, 4));
      const isOleExcel = bytes[0] === 0xD0 && bytes[1] === 0xCF && bytes[2] === 0x11 && bytes[3] === 0xE0;
      const isZipExcel = bytes[0] === 0x50 && bytes[1] === 0x4B;

      if (isOleExcel || isZipExcel) {
        throw new Error('Excelファイルはそのまま取り込めません。サンプルと同じレイアウトでCSV形式に保存してからアップロードしてください。');
      }

      const utf8 = new TextDecoder('utf-8', { fatal: false }).decode(buffer);
      const replacementCount = (utf8.match(/\uFFFD/g) || []).length;

      if (replacementCount > 0) {
        try {
          return new TextDecoder('shift-jis', { fatal: false }).decode(buffer);
        } catch (e) {
          return utf8;
        }
      }

      return utf8;
    },

    getCsvValue(lookup, aliases) {
      for (const alias of aliases) {
        const key = this.normalizeCsvKey(alias);
        if (Object.prototype.hasOwnProperty.call(lookup, key)) {
          return lookup[key];
        }
      }

      return '';
    },

    normalizeStoreCode(value) {
      const digits = this.normalizeCsvText(value).replace(/\D/g, '');
      if (!digits) return '';

      const numeric = Number(digits);
      return Number.isFinite(numeric) ? String(numeric).padStart(2, '0') : digits;
    },

    matchesCsvDestinationHeader(header, dest, mode) {
      const key = header.key;
      if (!key) return false;

      const hasAllocWord = /分配|配分|割当|割り当て|alloc|allocation/.test(key);
      if (mode === 'alloc' && !hasAllocWord) return false;
      if (mode === 'wish' && hasAllocWord) return false;

      const destCode = this.normalizeStoreCode(dest.code || dest.key || '');
      const headerCode = this.normalizeStoreCode((key.match(/^\d+/) || [''])[0]);
      const name = this.normalizeCsvKey(dest.name || '');
      const destKey = this.normalizeCsvKey(dest.key || '');
      const rawCode = this.normalizeCsvKey(dest.code || dest.key || '');

      return !!(
        (destCode && headerCode && destCode === headerCode) ||
        (name && key.includes(name)) ||
        (destKey && key === destKey) ||
        (rawCode && key === rawCode)
      );
    },

    getCsvDestinationQuantity(headers, cells, dest, mode) {
      const header = this.getCsvDestinationHeader(headers, dest, mode);
      if (!header) return 0;

      return this.parseCsvInteger(cells[header.index] ?? '');
    },

    getCsvDestinationHeader(headers, dest, mode) {
      return headers.find(item => this.matchesCsvDestinationHeader(item, dest, mode)) || null;
    },

    getCsvVisibleDestinationKeys(headers) {
      return DESTS
        .filter(dest =>
          this.getCsvDestinationHeader(headers, dest, 'wish') ||
          this.getCsvDestinationHeader(headers, dest, 'alloc')
        )
        .map(dest => String(dest.key));
    },

    getCsvImportPostingDate(row) {
      return this.normalizeFlexibleDate(row?.csvPostingDate || row?.postingDate || row?.accountingDate || row?.orderDate || '') || this.getCsvImportBaseDate();
    },

    getCsvImportBaseDate() {
      return this.normalizeFlexibleDate(this.bulkInputDate || '') || getTodayString();
    },

    getCsvImportWishSignature(row) {
      const visibleKeys = this.getRowVisibleDestinationKeys(row);
      const visibleKeySet = new Set(visibleKeys);
      const destinations = visibleKeys.length > 0
        ? DESTS.filter(dest => visibleKeySet.has(String(dest.key)))
        : DESTS;

      return destinations
        .map(dest => dest.key + ':' + toInt(row?.['wish_' + dest.key] || 0))
        .join('|');
    },

    getCsvImportDuplicateKey(row) {
      const postingDate = this.getCsvImportPostingDate(row);
      const productCode = this.normalizeCsvCode(row?.productCode || row?.csvSearchCode || row?.jan || '');
      if (!postingDate || !productCode) return '';

      return [
        postingDate,
        productCode,
        this.getCsvImportWishSignature(row),
      ].join('::');
    },

    isCsvImportSourceRow(row, source) {
      const rowSource = String(row?.source || '');
      const sourceKey = String(row?.sourceKey || row?.source_key || '');

      return rowSource === source || sourceKey.startsWith(source + ':');
    },

    filterDuplicateCsvImportRows(importedRows, existingRows, source) {
      const existingRowsByKey = new Map();
      (Array.isArray(existingRows) ? existingRows : [])
        .filter(row => this.isCsvImportSourceRow(row, source))
        .forEach(row => {
          const duplicateKey = this.getCsvImportDuplicateKey(row);
          if (!duplicateKey) return;

          if (!existingRowsByKey.has(duplicateKey)) {
            existingRowsByKey.set(duplicateKey, []);
          }
          existingRowsByKey.get(duplicateKey).push(row);
        });
      const seenKeys = new Set();
      const rows = [];
      const failureDetails = [];
      const existingDuplicateRows = [];

      (Array.isArray(importedRows) ? importedRows : []).forEach(row => {
        const duplicateKey = this.getCsvImportDuplicateKey(row);
        const matchedExistingRows = duplicateKey ? (existingRowsByKey.get(duplicateKey) || []) : [];
        const isExistingDuplicate = matchedExistingRows.length > 0;
        const isFileDuplicate = duplicateKey && seenKeys.has(duplicateKey);

        if (isExistingDuplicate || isFileDuplicate) {
          if (isExistingDuplicate) {
            existingDuplicateRows.push(...matchedExistingRows);
          }
          failureDetails.push({
            rowNumber: row.csvRowNumber || '',
            code: row.productCode || row.csvSearchCode || row.jan || '',
            name: row.name || '',
            reason: isExistingDuplicate
              ? '同じ取込基準日・商品CD・CSV店舗別希望数のCSV取込済みデータが既に存在します'
              : 'CSVファイル内で同じ取込基準日・商品CD・CSV店舗別希望数の行が重複しています',
          });
          return;
        }

        if (duplicateKey) {
          seenKeys.add(duplicateKey);
        }
        rows.push(row);
      });

      return { rows, failureDetails, existingDuplicateRows: Array.from(new Set(existingDuplicateRows)) };
    },

    makeCsvLookup(headers, cells) {
      const lookup = {};
      headers.forEach(header => {
        if (!header.key || Object.prototype.hasOwnProperty.call(lookup, header.key)) return;
        lookup[header.key] = cells[header.index] ?? '';
      });

      return lookup;
    },

    resetCsvImportProgress(fileName = '') {
      this.csvImportProgress = {
        visible: true,
        running: true,
        fileName,
        status: 'CSVを読み込んでいます',
        progressPercent: 3,
        progressLabel: '準備中',
        totalRows: 0,
        importedRows: 0,
        skippedRows: 0,
        resolvedRows: 0,
        unresolvedRows: 0,
        ambiguousRows: 0,
        failedRows: 0,
        failureDetails: [],
        error: '',
      };
    },

    normalizeCsvImportProgressPercent(value) {
      const percent = Number(value);
      if (!Number.isFinite(percent)) return 0;
      return Math.max(0, Math.min(100, Math.round(percent)));
    },

    csvImportProgressPercent() {
      return this.normalizeCsvImportProgressPercent(this.csvImportProgress?.progressPercent || 0);
    },

    updateCsvImportProgress(updates = {}) {
      const nextProgress = {
        ...this.csvImportProgress,
        ...updates,
      };
      if (Object.prototype.hasOwnProperty.call(updates, 'progressPercent')) {
        nextProgress.progressPercent = this.normalizeCsvImportProgressPercent(updates.progressPercent);
      }
      this.csvImportProgress = nextProgress;
    },

    getCsvImportFailureDetails() {
      return Array.isArray(this.csvImportProgress?.failureDetails)
        ? this.csvImportProgress.failureDetails
        : [];
    },

    escapeCsvCell(value) {
      return '"' + String(value ?? '').replace(/"/g, '""') + '"';
    },

    downloadCsvImportFailureDetails() {
      const details = this.getCsvImportFailureDetails();
      if (details.length === 0) {
        this.openModal('エラー', '出力できるエラーリストがありません');
        return;
      }

      const headers = ['ファイル名', '行', '商品CD/JAN', '商品名', '理由'];
      const sourceFile = this.csvImportProgress?.fileName || '';
      const body = details.map(detail => [
        sourceFile,
        detail.rowNumber || '',
        detail.code || '',
        detail.name || '',
        detail.reason || '',
      ]);
      const csv = [headers, ...body]
        .map(row => row.map(value => this.escapeCsvCell(value)).join(','))
        .join('\r\n');
      const now = new Date();
      const timestamp = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
        String(now.getHours()).padStart(2, '0'),
        String(now.getMinutes()).padStart(2, '0'),
        String(now.getSeconds()).padStart(2, '0'),
      ].join('');
      const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'csv_import_errors_' + timestamp + '.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      this.showToast('エラーリストCSVを出力しました（' + details.length + '件）');
    },

    buildAllocationRowsFromCsv(text, fileName = '') {
      const table = this.parseDelimitedText(text);
      const productHeaderAliases = ['商品コード', '商品CD', '単品CD', '単品コード', '自社コード', '品番', 'JANコード', 'JAN']
        .map(alias => this.normalizeCsvKey(alias));
      const headerIndex = 0;
      const headerCells = table[headerIndex] || [];

      if (this.isBlankCsvRow(headerCells) || !headerCells.some(cell => productHeaderAliases.includes(this.normalizeCsvKey(cell)))) {
        throw new Error('CSVの1行目にヘッダー行が見つかりません。単品CDまたは商品コードの列を含めてください。');
      }

      const headers = headerCells.map((raw, index) => ({
        raw: String(raw ?? '').trim(),
        key: this.normalizeCsvKey(raw),
        index,
      }));
      const importBaseDate = this.getCsvImportBaseDate();
      const csvVisibleDestinationKeys = this.getCsvVisibleDestinationKeys(headers);
      const dataRows = table
        .slice(1)
        .map((cells, index) => ({ cells, rowNumber: index + 2 }))
        .filter(({ cells }) => !this.isBlankCsvRow(cells));
      let skippedRows = 0;
      const failureDetails = [];
      const rows = [];

      dataRows.forEach(({ cells, rowNumber }) => {
        const lookup = this.makeCsvLookup(headers, cells);
        const productCode = this.normalizeCsvCode(this.getCsvValue(lookup, ['商品コード', '商品CD', '単品CD', '単品コード', '自社コード', '品番']));
        const jan = this.normalizeCsvCode(this.getCsvValue(lookup, ['JANコード', 'JAN', 'バーコード']));
        const searchCode = productCode || jan;

        if (!searchCode) {
          skippedRows++;
          failureDetails.push({
            rowNumber,
            code: '',
            name: this.normalizeCsvText(this.getCsvValue(lookup, ['商品名', '品名', '表示正式名称', '正式名称'])),
            reason: '商品コード/JANが空のためスキップ',
          });
          return;
        }

        const csvPostingDate = this.normalizeFlexibleDate(this.getCsvValue(lookup, ['計上日'])) || importBaseDate;

        const orderTo = this.normalizeCsvText(this.getCsvValue(lookup, ['発注先', '発注先名', '発注先名称', '発注元']));
        const orderToCode = this.normalizeCsvCode(this.getCsvValue(lookup, ['発注先CD', '発注先コード', '発注先ＣＤ']));
        const supplier = this.normalizeCsvText(this.getCsvValue(lookup, ['仕入先', '仕入先名', '仕入先名称']));
        const supplierCode = this.normalizeCsvCode(this.getCsvValue(lookup, ['仕入先CD', '仕入先コード', '仕入先ＣＤ']));
        const poCaseRaw = this.getCsvValue(lookup, ['発注ケース', '発注CS', 'ケース']);
        const poEachRaw = this.getCsvValue(lookup, ['発注バラ', 'バラ']);
        const csvPoCase = String(poCaseRaw ?? '').trim() === '' ? 0 : this.parseCsvInteger(poCaseRaw);
        const csvPoEach = String(poEachRaw ?? '').trim() === '' ? 0 : this.parseCsvInteger(poEachRaw);
        const csvPoPieces = csvPoCase + csvPoEach;
        const row = this.makeAllocationRow({
          source: 'csv_import',
          sourceKey: ['csv_import', fileName || 'upload', rowNumber, searchCode, uuid()].join(':'),
          csvRowNumber: rowNumber,
          csvSearchCode: searchCode,
          productCode: searchCode,
          jan,
          name: this.normalizeCsvText(this.getCsvValue(lookup, ['商品名', '品名', '表示正式名称', '正式名称'])),
          volume: this.normalizeCsvText(this.getCsvValue(lookup, ['容量', '規格'])),
          unitsPerCase: this.parseCsvInteger(this.getCsvValue(lookup, ['入数', 'ケース入数'])) || 0,
          lot: this.parseCsvInteger(this.getCsvValue(lookup, ['ロット', '発注ロット'])) || 0,
          currentStock: this.parseCsvInteger(this.getCsvValue(lookup, ['実在庫', '現在庫'])),
          reserved: this.parseCsvInteger(this.getCsvValue(lookup, ['理論', '引当可能数', '理論在庫'])),
          csvPostingDate,
          orderDate: this.normalizeFlexibleDate(this.getCsvValue(lookup, ['発注日', '注文日'])) || csvPostingDate,
          deliveryDate: this.normalizeFlexibleDate(this.getCsvValue(lookup, ['納品希望日', '納品日', '希望納品日'])) || '',
          orderTo,
          orderToCode,
          supplier,
          supplierCode,
          poCase: '',
          poEach: csvPoPieces > 0 ? csvPoPieces : '',
          checked: this.isCsvTruthy(this.getCsvValue(lookup, ['確定', 'チェック'])),
          memo: this.normalizeCsvText(this.getCsvValue(lookup, ['明細備考', '備考', 'メモ'])),
          visibleDestinationKeys: csvVisibleDestinationKeys,
        });

        DESTS.forEach(dest => {
          const wishHeader = this.getCsvDestinationHeader(headers, dest, 'wish');
          const wishQuantity = wishHeader ? this.parseCsvInteger(cells[wishHeader.index] ?? '') : 0;

          row['wish_' + dest.key] = wishQuantity;
          row['alloc_' + dest.key] = wishHeader
            ? wishQuantity
            : this.getCsvDestinationQuantity(headers, cells, dest, 'alloc');
        });

        if (!this.isBlankAllocationRow(row)) {
          rows.push(row);
        } else {
          skippedRows++;
          failureDetails.push({
            rowNumber,
            code: searchCode,
            name: row.name || '',
            reason: '取込対象の数量・商品情報が空のためスキップ',
          });
        }
      });

      this.lastCsvImportParseSummary = {
        totalRows: dataRows.length,
        importedRows: rows.length,
        skippedRows,
        failureDetails,
      };

      return rows;
    },

    buildDirectRowsFromCsv(text, fileName = '') {
      const allocationRows = this.buildAllocationRowsFromCsv(text, fileName);
      const parseSummary = this.lastCsvImportParseSummary || {
        totalRows: allocationRows.length,
        importedRows: allocationRows.length,
        skippedRows: 0,
        failureDetails: [],
      };
      const directRows = [];
      const blockedFailureDetails = [];

      allocationRows.forEach(row => {
        const directRow = this.makeDirectRow({
          ...row,
          source: 'direct_csv_import',
          sourceKey: [
            'direct_csv_import',
            fileName || 'upload',
            row.csvRowNumber || '',
            row.csvSearchCode || row.productCode || row.jan || '',
            uuid(),
          ].join(':'),
        });

        if (this.isDirectCsvBlockedHqPartnerRow(directRow)) {
          blockedFailureDetails.push({
            rowNumber: directRow.csvRowNumber || '',
            code: directRow.csvSearchCode || directRow.productCode || directRow.jan || '',
            name: directRow.name || '',
            reason: '直送分配では本部・華むすびの蔵センター宛の発注先/仕入先は取込できません。本部分配で処理してください。',
          });
          return;
        }

        directRows.push(directRow);
      });

      this.lastCsvImportParseSummary = {
        ...parseSummary,
        importedRows: directRows.length,
        skippedRows: (parseSummary.skippedRows || 0) + blockedFailureDetails.length,
        failureDetails: [
          ...(Array.isArray(parseSummary.failureDetails) ? parseSummary.failureDetails : []),
          ...blockedFailureDetails,
        ],
      };

      return directRows;
    },

    isDirectCsvBlockedHqPartnerRow(row) {
      return this.isHqOrderOrWarehouse(row.orderToCode, row.orderTo)
        || this.isHqOrderOrWarehouse(row.supplierCode, row.supplier);
    },

    isHqOrderOrWarehouse(code, name) {
      return this._isHqOrderCodeOrName(code, name)
        || this._isHqWarehouseCodeOrName(code, name);
    },

    directCsvProductCandidates(products) {
      return products.filter(product => !this.isHqOrderProduct(product));
    },

    allocationCsvProductCandidates(products) {
      return products.filter(product => this.isHqOrderProduct(product));
    },

    getImportedProductPartnerSearch(row, options = {}) {
      let orderToSearch = row.orderToCode || row.orderTo || '';
      let supplierSearch = row.supplierCode || row.supplier || '';

      if (options.allocationHqOnly) {
        if (orderToSearch && !this.isHqOrderOrWarehouse(row.orderToCode, row.orderTo)) {
          orderToSearch = '';
        }

        if (supplierSearch && !this.isHqOrderOrWarehouse(row.supplierCode, row.supplier)) {
          supplierSearch = '';
        }
      }

      if (options.direct) {
        if (this.isHqOrderOrWarehouse(row.orderToCode, row.orderTo)) {
          orderToSearch = '';
        }

        if (this.isHqOrderOrWarehouse(row.supplierCode, row.supplier)) {
          supplierSearch = '';
        }
      }

      return { orderToSearch, supplierSearch };
    },

    applyImportedProductCandidates(row, products, query, orderDate, result, options = {}) {
      const allNormalizedProducts = products.map(product => this.normalizeProduct(product));
      const normalizedProducts = options.direct
        ? this.directCsvProductCandidates(allNormalizedProducts)
        : (options.allocationHqOnly ? this.allocationCsvProductCandidates(allNormalizedProducts) : allNormalizedProducts);
      normalizedProducts.forEach(product => this.rememberProduct(product));
      const exactProducts = normalizedProducts.filter(product => this.isExactProductCandidate(product, query));
      const candidates = exactProducts.length > 0 ? exactProducts : normalizedProducts;

      if (candidates.length === 1) {
        const importedDeliveryDate = row.deliveryDate || '';
        this.applyProductToRow(row, candidates[0]);
        row.orderDate = orderDate;
        if (importedDeliveryDate) {
          row.deliveryDate = importedDeliveryDate;
        }
        result.resolved++;
      } else if (candidates.length > 1) {
        result.ambiguous++;
        result.failureDetails = result.failureDetails || [];
        result.failureDetails.push({
          rowNumber: row.csvRowNumber || '',
          code: row.csvSearchCode || query || row.productCode || row.jan || '',
          name: row.name || '',
          reason: '商品候補が複数あるため自動確定できません',
        });
      } else {
        result.unresolved++;
        result.failureDetails = result.failureDetails || [];
        result.failureDetails.push({
          rowNumber: row.csvRowNumber || '',
          code: row.csvSearchCode || query || row.productCode || row.jan || '',
          name: row.name || '',
          reason: options.allocationHqOnly && allNormalizedProducts.length > 0
            ? '本部分配では本部発注先の商品候補のみ取込できます。商品マスタの発注先を確認してください。'
            : options.direct && allNormalizedProducts.length > 0
            ? '直送分配では本部発注先の商品候補は使用できません。商品マスタの発注先・仕入先を確認してください。'
            : '商品マスタが見つかりません',
        });
      }
    },

    async fetchImportedProductCandidatesBatch(targets) {
      const result = new Map();
      const chunkSize = 200;
      const groupedTargets = new Map();
      const batchTargets = [];
      let processed = 0;

      targets.forEach(target => {
        const cacheKey = [
          this.normalizeSearchText(target.query || ''),
          target.orderDate || '',
          this.normalizeSearchText(target.orderToSearch || ''),
          this.normalizeSearchText(target.supplierSearch || ''),
        ].join('\x1F');

        if (!groupedTargets.has(cacheKey)) {
          const batchTarget = {
            ...target,
            batchKey: 'csv-import-' + batchTargets.length,
            targets: [],
          };
          groupedTargets.set(cacheKey, batchTarget);
          batchTargets.push(batchTarget);
        }

        groupedTargets.get(cacheKey).targets.push(target);
      });

      for (let i = 0; i < batchTargets.length; i += chunkSize) {
        const chunk = batchTargets.slice(i, i + chunkSize);
        const chunkRowCount = chunk.reduce((sum, item) => sum + item.targets.length, 0);
        this.updateCsvImportProgress({
          status: '商品マスタを照合しています（' + Math.min(processed + chunkRowCount, targets.length) + '/' + targets.length + '）',
          progressLabel: '商品マスタ照合中',
          progressPercent: 35 + ((processed / targets.length) * 35),
        });
        const response = await fetch('/api/distribution/products/batch', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...this.csrfHeaders(),
          },
          body: JSON.stringify({
            queries: chunk.map(({ batchKey, query, orderDate, orderToSearch, supplierSearch }) => ({
              key: batchKey,
              search: query,
              order_date: orderDate,
              order_to_search: orderToSearch,
              supplier_search: supplierSearch,
            })),
          }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
          const message = payload?.result?.error_message
            || payload?.message
            || ('HTTP ' + response.status);
          throw new Error(message);
        }

        const items = Array.isArray(payload?.result?.data?.items)
          ? payload.result.data.items
          : (Array.isArray(payload?.items) ? payload.items : []);
        const productsByBatchKey = new Map();
        items.forEach(item => {
          productsByBatchKey.set(String(item.key), Array.isArray(item.products) ? item.products : []);
        });
        chunk.forEach(batchTarget => {
          const products = productsByBatchKey.get(batchTarget.batchKey) || [];
          batchTarget.targets.forEach(target => {
            result.set(String(target.row.id), products);
          });
        });
        processed += chunkRowCount;
        this.updateCsvImportProgress({
          status: '商品マスタを照合しています（' + processed + '/' + targets.length + '）',
          progressLabel: '商品マスタ照合中',
          progressPercent: 35 + ((processed / targets.length) * 35),
        });
      }

      return result;
    },

    async fetchProductCandidatesBatchForRows(targets) {
      const result = new Map();
      const chunkSize = 25;

      for (let i = 0; i < targets.length; i += chunkSize) {
        const chunk = targets.slice(i, i + chunkSize);
        const response = await fetch('/api/distribution/products/batch', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...this.csrfHeaders(),
          },
          body: JSON.stringify({
            queries: chunk.map(({ row, query, orderDate, orderToSearch, supplierSearch }) => ({
              key: row.id,
              search: query,
              order_date: orderDate,
              order_to_search: orderToSearch,
              supplier_search: supplierSearch,
            })),
          }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
          const message = payload?.result?.error_message
            || payload?.message
            || ('HTTP ' + response.status);
          throw new Error(message);
        }

        const items = Array.isArray(payload?.result?.data?.items)
          ? payload.result.data.items
          : (Array.isArray(payload?.items) ? payload.items : []);
        items.forEach(item => {
          result.set(String(item.key), Array.isArray(item.products) ? item.products : []);
        });
      }

      return result;
    },

    async hydrateImportedAllocationRowsIndividually(targets, options = {}) {
      const result = { resolved: 0, unresolved: 0, ambiguous: 0, failureDetails: [] };
      const cache = new Map();
      let cursor = 0;
      let processed = 0;

      const hydrateOne = async ({ row, query, orderDate, orderToSearch, supplierSearch }) => {
        const cacheKey = [query, orderDate, orderToSearch, supplierSearch].join('|');

        try {
          if (!cache.has(cacheKey)) {
            const products = await this.fetchProductCandidates(query, orderDate, 50, { orderToSearch, supplierSearch });
            cache.set(cacheKey, products);
          }

          this.applyImportedProductCandidates(row, cache.get(cacheKey) || [], query, orderDate, result, options);
        } catch (e) {
          console.error('Failed to hydrate imported row:', e);
          result.unresolved++;
          result.failureDetails.push({
            rowNumber: row.csvRowNumber || '',
            code: row.csvSearchCode || query || row.productCode || row.jan || '',
            name: row.name || '',
            reason: '商品マスタ照合でエラー: ' + (e.message || '不明なエラー'),
          });
        } finally {
          processed++;
          this.updateCsvImportProgress({
            status: '商品マスタを個別照合しています（' + processed + '/' + targets.length + '）',
            progressLabel: '商品マスタ個別照合中',
            progressPercent: 35 + ((processed / targets.length) * 35),
          });
        }
      };

      const workerCount = Math.min(4, targets.length);
      const workers = Array.from({ length: workerCount }, async () => {
        while (cursor < targets.length) {
          const target = targets[cursor++];
          await hydrateOne(target);
        }
      });

      await Promise.all(workers);
      return result;
    },

    async hydrateImportedAllocationRows(rows, options = {}) {
      const result = { resolved: 0, unresolved: 0, ambiguous: 0, failureDetails: [] };
      const targets = rows
        .map(row => {
          const query = String(row.productCode || row.jan || '').trim();
          if (!query) return null;
          const partnerSearch = this.getImportedProductPartnerSearch(row, options);

          return {
            row,
            query,
            orderDate: this.normalizeFlexibleDate(row.orderDate || '') || getTodayString(),
            orderToSearch: partnerSearch.orderToSearch,
            supplierSearch: partnerSearch.supplierSearch,
          };
        })
        .filter(target => target !== null);

      if (targets.length === 0) {
        return result;
      }

      try {
        this.updateCsvImportProgress({
          status: '商品マスタを照合しています',
          progressLabel: '商品マスタ照合中',
          progressPercent: 35,
        });
        const productsByRowId = await this.fetchImportedProductCandidatesBatch(targets);
        this.updateCsvImportProgress({
          status: '商品情報を反映しています',
          progressLabel: '商品情報反映中',
          progressPercent: 75,
        });
        targets.forEach(({ row, query, orderDate }) => {
          this.applyImportedProductCandidates(row, productsByRowId.get(String(row.id)) || [], query, orderDate, result, options);
        });
        this.updateCsvImportProgress({
          status: '商品情報を反映しました',
          progressLabel: '画面反映準備中',
          progressPercent: 82,
        });
        return result;
      } catch (e) {
        console.warn('Batch product hydration failed. Falling back to per-row search:', e);
        return this.hydrateImportedAllocationRowsIndividually(targets, options);
      }
    },

    async importAllocationCsv(event) {
      const input = event?.target;
      const file = input?.files?.[0];
      if (!file) return;

      this.csvImporting = true;
      this.resetCsvImportProgress(file.name);

      try {
        this.updateCsvImportProgress({ status: 'CSVを読み込んでいます', progressLabel: 'CSV読込中', progressPercent: 8 });
        const buffer = await file.arrayBuffer();
        const text = this.decodeCsvBuffer(buffer);
        this.updateCsvImportProgress({ status: 'CSVレイアウトを確認しています', progressLabel: 'レイアウト確認中', progressPercent: 18 });
        let importedRows = this.buildAllocationRowsFromCsv(text, file.name);
        const parseSummary = this.lastCsvImportParseSummary || { totalRows: importedRows.length, importedRows: importedRows.length, skippedRows: 0, failureDetails: [] };
        const parseFailureDetails = Array.isArray(parseSummary.failureDetails) ? parseSummary.failureDetails : [];
        this.updateCsvImportProgress({
          status: 'CSVを解析しました',
          progressLabel: 'CSV解析完了',
          progressPercent: 30,
          totalRows: parseSummary.totalRows,
          importedRows: importedRows.length,
          skippedRows: parseSummary.skippedRows,
          failedRows: parseSummary.skippedRows,
          failureDetails: parseFailureDetails,
        });

        if (importedRows.length === 0) {
          this.updateCsvImportProgress({
            running: false,
            status: '取込対象がありません',
            progressLabel: '取込対象なし',
            progressPercent: 100,
            error: '取り込める分配データがありませんでした。',
          });
          return;
        }

        const hydrateResult = await this.hydrateImportedAllocationRows(importedRows, { allocationHqOnly: true });
        const unresolvedCount = hydrateResult.unresolved + hydrateResult.ambiguous;
        const hydrateFailureDetails = Array.isArray(hydrateResult.failureDetails) ? hydrateResult.failureDetails : [];
        importedRows = importedRows.filter(row => row.itemId && this.isHqOrderProduct(row));
        const duplicateResult = this.filterDuplicateCsvImportRows(importedRows, this.rows, 'csv_import');
        importedRows = duplicateResult.rows;
        const duplicateFailureDetails = Array.isArray(duplicateResult.failureDetails) ? duplicateResult.failureDetails : [];
        const existingDuplicateRows = Array.isArray(duplicateResult.existingDuplicateRows) ? duplicateResult.existingDuplicateRows : [];
        const duplicateCount = duplicateFailureDetails.length;
        const failureDetails = [...parseFailureDetails, ...hydrateFailureDetails, ...duplicateFailureDetails];
        this.rememberFilterRetainedRows(existingDuplicateRows, false);
        this.updateCsvImportProgress({
          status: '画面へ反映しています',
          progressLabel: '画面反映中',
          progressPercent: 88,
          resolvedRows: hydrateResult.resolved,
          unresolvedRows: hydrateResult.unresolved,
          ambiguousRows: hydrateResult.ambiguous,
          failedRows: parseSummary.skippedRows + unresolvedCount + duplicateCount,
          failureDetails,
        });

        if (importedRows.length === 0) {
          this.updateCsvImportProgress({
            running: false,
            status: 'CSV取込できる新規データがありません',
            progressLabel: '重複',
            progressPercent: 100,
            importedRows: 0,
            skippedRows: parseSummary.skippedRows + duplicateCount,
            resolvedRows: hydrateResult.resolved,
            unresolvedRows: hydrateResult.unresolved,
            ambiguousRows: hydrateResult.ambiguous,
            failedRows: parseSummary.skippedRows + unresolvedCount + duplicateCount,
            failureDetails,
            error: unresolvedCount > 0
              ? '本部発注先の商品候補が見つからない行は取り込めません。'
              : duplicateCount > 0
              ? '同じ取込基準日・商品CD・CSV店舗別希望数のCSV取込済みデータが既に存在します。'
              : '取り込める新規データがありません。',
          });
          return;
        }

        this.rememberFilterRetainedRows(importedRows, false);
        this.rows = [
          ...this.rows.filter(row => !this.isBlankAllocationRow(row)),
          ...importedRows,
        ];
        this.normalizeRowsDateFields(this.rows, true);
        this.hydrateProductMetaFromRows();
        this.saveData();

        this.updateCsvImportProgress({
          running: false,
          status: unresolvedCount > 0 || parseSummary.skippedRows > 0 || duplicateCount > 0 ? 'CSV取込が完了しました（一部確認あり）' : 'CSV取込が完了しました',
          progressLabel: '完了',
          progressPercent: 100,
          importedRows: importedRows.length,
          skippedRows: parseSummary.skippedRows + duplicateCount,
          resolvedRows: hydrateResult.resolved,
          unresolvedRows: hydrateResult.unresolved,
          ambiguousRows: hydrateResult.ambiguous,
          failedRows: parseSummary.skippedRows + unresolvedCount + duplicateCount,
          failureDetails,
        });
        this.showToast('CSVから' + importedRows.length + '行を取り込みました');
      } catch (e) {
        console.error('CSV import failed:', e);
        const failureDetails = this.csvImportProgress.failureDetails || [];
        this.updateCsvImportProgress({
          running: false,
          status: 'CSV取込でエラーが発生しました',
          progressLabel: 'エラー',
          progressPercent: 100,
          error: e.message || 'CSV取込でエラーが発生しました',
          failedRows: Math.max(1, this.csvImportProgress.failedRows || 0),
          failureDetails: failureDetails.length > 0 ? failureDetails : [{
            rowNumber: '-',
            code: '',
            name: '',
            reason: e.message || 'CSV取込でエラーが発生しました',
          }],
        });
      } finally {
        this.csvImporting = false;
        if (input) input.value = '';
      }
    },

    async importDirectCsv(event) {
      const input = event?.target;
      const file = input?.files?.[0];
      if (!file) return;

      this.csvImporting = true;
      this.resetCsvImportProgress(file.name);

      try {
        this.updateCsvImportProgress({ status: 'CSVを読み込んでいます', progressLabel: 'CSV読込中', progressPercent: 8 });
        const buffer = await file.arrayBuffer();
        const text = this.decodeCsvBuffer(buffer);
        this.updateCsvImportProgress({ status: 'CSVレイアウトを確認しています', progressLabel: 'レイアウト確認中', progressPercent: 18 });
        let importedRows = this.buildDirectRowsFromCsv(text, file.name);
        const parseSummary = this.lastCsvImportParseSummary || { totalRows: importedRows.length, importedRows: importedRows.length, skippedRows: 0, failureDetails: [] };
        const parseFailureDetails = Array.isArray(parseSummary.failureDetails) ? parseSummary.failureDetails : [];
        this.updateCsvImportProgress({
          status: 'CSVを解析しました',
          progressLabel: 'CSV解析完了',
          progressPercent: 30,
          totalRows: parseSummary.totalRows,
          importedRows: importedRows.length,
          skippedRows: parseSummary.skippedRows,
          failedRows: parseSummary.skippedRows,
          failureDetails: parseFailureDetails,
        });

        if (importedRows.length === 0) {
          this.updateCsvImportProgress({
            running: false,
            status: '取込対象がありません',
            progressLabel: '取込対象なし',
            progressPercent: 100,
            error: '取り込める直送分配データがありませんでした。',
          });
          return;
        }

        const hydrateResult = await this.hydrateImportedAllocationRows(importedRows, { direct: true });
        const unresolvedCount = hydrateResult.unresolved + hydrateResult.ambiguous;
        const hydrateFailureDetails = Array.isArray(hydrateResult.failureDetails) ? hydrateResult.failureDetails : [];
        const duplicateResult = this.filterDuplicateCsvImportRows(importedRows, this.directRows, 'direct_csv_import');
        importedRows = duplicateResult.rows;
        const duplicateFailureDetails = Array.isArray(duplicateResult.failureDetails) ? duplicateResult.failureDetails : [];
        const existingDuplicateRows = Array.isArray(duplicateResult.existingDuplicateRows) ? duplicateResult.existingDuplicateRows : [];
        const duplicateCount = duplicateFailureDetails.length;
        const failureDetails = [...parseFailureDetails, ...hydrateFailureDetails, ...duplicateFailureDetails];
        this.rememberFilterRetainedRows(existingDuplicateRows, true);
        this.updateCsvImportProgress({
          status: '画面へ反映しています',
          progressLabel: '画面反映中',
          progressPercent: 88,
          resolvedRows: hydrateResult.resolved,
          unresolvedRows: hydrateResult.unresolved,
          ambiguousRows: hydrateResult.ambiguous,
          failedRows: parseSummary.skippedRows + unresolvedCount + duplicateCount,
          failureDetails,
        });

        if (importedRows.length === 0) {
          this.updateCsvImportProgress({
            running: false,
            status: 'CSV取込できる新規データがありません',
            progressLabel: '重複',
            progressPercent: 100,
            importedRows: 0,
            skippedRows: parseSummary.skippedRows + duplicateCount,
            resolvedRows: hydrateResult.resolved,
            unresolvedRows: hydrateResult.unresolved,
            ambiguousRows: hydrateResult.ambiguous,
            failedRows: parseSummary.skippedRows + unresolvedCount + duplicateCount,
            failureDetails,
            error: duplicateCount > 0
              ? '同じ取込基準日・商品CD・CSV店舗別希望数のCSV取込済みデータが既に存在します。'
              : '取り込める新規データがありません。',
          });
          return;
        }

        this.rememberFilterRetainedRows(importedRows, true);
        this.directRows = [
          ...this.directRows.filter(row => !this.isBlankAllocationRow(row)),
          ...importedRows,
        ];
        this.normalizeRowsDateFields(this.directRows, true);
        this.hydrateProductMetaFromRows();
        this.saveDirectData();

        this.updateCsvImportProgress({
          running: false,
          status: unresolvedCount > 0 || parseSummary.skippedRows > 0 || duplicateCount > 0 ? 'CSV取込が完了しました（一部確認あり）' : 'CSV取込が完了しました',
          progressLabel: '完了',
          progressPercent: 100,
          importedRows: importedRows.length,
          skippedRows: parseSummary.skippedRows + duplicateCount,
          resolvedRows: hydrateResult.resolved,
          unresolvedRows: hydrateResult.unresolved,
          ambiguousRows: hydrateResult.ambiguous,
          failedRows: parseSummary.skippedRows + unresolvedCount + duplicateCount,
          failureDetails,
        });
        this.showToast('CSVから' + importedRows.length + '行を取り込みました');
      } catch (e) {
        console.error('Direct CSV import failed:', e);
        const failureDetails = this.csvImportProgress.failureDetails || [];
        this.updateCsvImportProgress({
          running: false,
          status: 'CSV取込でエラーが発生しました',
          progressLabel: 'エラー',
          progressPercent: 100,
          error: e.message || 'CSV取込でエラーが発生しました',
          failedRows: Math.max(1, this.csvImportProgress.failedRows || 0),
          failureDetails: failureDetails.length > 0 ? failureDetails : [{
            rowNumber: '-',
            code: '',
            name: '',
            reason: e.message || 'CSV取込でエラーが発生しました',
          }],
        });
      } finally {
        this.csvImporting = false;
        if (input) input.value = '';
      }
    },

    removeRow(id) {
      const row = this.rows.find(r => r.id === id);
      if (row && this.isRowDeleteLocked(row)) {
        this.openModal('エラー', '確定済み、または発注候補生成済みの行は削除できません');
        return;
      }
      this.openConfirm('この行を削除しますか？', () => {
        const deleteKey = this.getAllocationRowDeleteKey(row);
        this.rememberDeletedAllocationRows(row ? [row] : []);
        this.rows = this.rows.filter(r => this.getAllocationRowDeleteKey(r) !== deleteKey);
        this.deleteSelectedRowIds = this.deleteSelectedRowIds.filter(rowId => String(rowId) !== String(id));
        this.saveData();
      }, 'delete');
    },

    // ---------- Product Master ----------
    normalizeProduct(product) {
      const id = product.id || null;
      const code = String(product.code || '');
      const itemContractorId = product.itemContractorId || product.item_contractor_id || null;
      const contractorId = product.contractorId || product.contractor_id || null;
      const supplierId = product.supplierId || product.supplier_id || null;
      const contractorWarehouseId = product.contractorWarehouseId || product.contractor_warehouse_id || null;
      const candidateKey = String(
        product.candidateKey ||
        product.candidate_key ||
        [id || code, itemContractorId || 0, contractorId || 0, supplierId || 0, contractorWarehouseId || 0].join(':')
      );

      return {
        id: id,
        candidateKey: candidateKey,
        itemContractorId: itemContractorId,
        contractorId: contractorId,
        supplierId: supplierId,
        contractorWarehouseId: contractorWarehouseId,
        code: code,
        jan: String(product.jan || ''),
        name: String(product.name || ''),
        volume: String(product.volume || ''),
        unitsPerCase: toInt(product.unitsPerCase || product.units_per_case || product.capacity_case || 1) || 1,
        purchaseUnit: toInt(product.purchaseUnit || product.purchase_unit || 1) || 1,
        orderPoint: toInt(product.orderPoint || product.order_point || 0),
        shelfLocation: String(product.shelfLocation || product.shelf_location || ''),
        lastOrderDate: String(product.lastOrderDate || product.last_order_date || ''),
        salesWeek1: toInt(product.salesWeek1 || product.sales_week1 || 0),
        salesWeek2: toInt(product.salesWeek2 || product.sales_week2 || 0),
        salesWeek3: toInt(product.salesWeek3 || product.sales_week3 || 0),
        supplier: String(product.supplier || ''),
        supplierCode: String(product.supplierCode || product.supplier_code || ''),
        orderTo: String(product.orderTo || product.order_to || ''),
        orderToCode: String(product.orderToCode || product.order_to_code || ''),
        itemContractorNote: String(product.itemContractorNote || product.item_contractor_note || ''),
        suggestedDeliveryDate: String(product.suggestedDeliveryDate || product.suggested_delivery_date || ''),
        deliveryDateCalculation: product.deliveryDateCalculation || product.delivery_date_calculation || null,
        stock: {
          actualQuantity: toInt(product.stock?.actualQuantity || product.stock?.actual_quantity || 0),
          theoreticalQuantity: toInt(product.stock?.theoreticalQuantity || product.stock?.theoretical_quantity || 0),
          reservedQuantity: toInt(product.stock?.reservedQuantity || product.stock?.reserved_quantity || 0),
          incomingQuantity: toInt(product.stock?.incomingQuantity || product.stock?.incoming_quantity || 0),
          incomingCount: toInt(product.stock?.incomingCount || product.stock?.incoming_count || 0),
          selectedWarehouseId: product.stock?.selectedWarehouseId || product.stock?.selected_warehouse_id || null,
          realWarehouseId: product.stock?.realWarehouseId || product.stock?.real_warehouse_id || null,
        },
      };
    },

    rememberProduct(product) {
      const p = this.normalizeProduct(product);
      if (!p.code) return;
      this.productMetaByCode[p.code] = {
        ...(this.productMetaByCode[p.code] || {}),
        ...p,
      };

      const rememberCandidate = (bucket, key) => {
        if (!key) return;

        const normalizedKey = String(key);
        const existing = Array.isArray(bucket[normalizedKey]) ? bucket[normalizedKey] : [];
        const candidateKey = String(p.candidateKey || p.code || '');
        const next = existing.filter(candidate => String(candidate.candidateKey || candidate.code || '') !== candidateKey);
        next.push(p);
        bucket[normalizedKey] = next;
      };

      rememberCandidate(this.productCandidatesByCode, p.code);
      rememberCandidate(this.productCandidatesByJan, this.normalizeScanCode(p.jan));
    },

    hydrateProductMetaFromRows() {
      [...this.rows, ...this.directRows].forEach(row => {
        if (!row.productCode) return;
        this.rememberProduct({
          id: row.itemId,
          candidateKey: row.candidateKey,
          itemContractorId: row.itemContractorId,
          contractorId: row.contractorId,
          supplierId: row.supplierId,
          contractorWarehouseId: row.contractorWarehouseId,
          code: row.productCode,
          jan: row.jan,
          name: row.name,
          volume: row.volume,
          unitsPerCase: row.unitsPerCase,
          purchaseUnit: row.purchaseUnit,
          orderPoint: row.orderPoint,
          shelfLocation: row.shelfLocation,
          lastOrderDate: row.lastOrderDate,
          salesWeek1: row.salesWeek1,
          salesWeek2: row.salesWeek2,
          salesWeek3: row.salesWeek3,
          supplier: row.supplier,
          supplierCode: row.supplierCode,
          orderTo: row.orderTo,
          orderToCode: row.orderToCode,
          itemContractorNote: row.itemContractorNote,
          suggestedDeliveryDate: row.suggestedDeliveryDate,
          deliveryDateCalculation: row.deliveryDateCalculation,
          stock: {
            actualQuantity: row.currentStock,
            theoreticalQuantity: row.reserved,
            reservedQuantity: row.reservedStock,
            incomingQuantity: row.incomingQuantity,
            incomingCount: row.incomingCount,
            selectedWarehouseId: row.selectedWarehouseId,
            realWarehouseId: row.realWarehouseId,
          },
        });
      });
    },

    getProductMeta(productCode) {
      return this.productMetaByCode[productCode] || {};
    },

    getSupplier(productCode) {
      return this.getProductMeta(productCode).supplier || '';
    },

    getOrderTo(productCode) {
      return this.getProductMeta(productCode).orderTo || '';
    },

    formatPartnerWithCode(code, name) {
      const partnerName = String(name || '').trim();
      const partnerCode = String(code || '').trim();

      if (partnerCode && partnerName) return '[' + partnerCode + ']' + partnerName;
      if (partnerName) return partnerName;
      if (partnerCode) return '[' + partnerCode + ']';

      return '';
    },

    getOrderToDisplay(row) {
      const meta = this.getProductMeta(row?.productCode || '');

      return this.formatPartnerWithCode(
        row?.orderToCode || meta.orderToCode,
        row?.orderTo || meta.orderTo
      ) || '-';
    },

    getSupplierDisplay(row) {
      const meta = this.getProductMeta(row?.productCode || '');

      return this.formatPartnerWithCode(
        row?.supplierCode || meta.supplierCode,
        row?.supplier || meta.supplier
      ) || '-';
    },

    getItemContractorNote(row) {
      return String(row?.itemContractorNote || this.getProductMeta(row?.productCode || '').itemContractorNote || '').trim();
    },

    getProductName(productCode) {
      return this.getProductMeta(productCode).name || '-';
    },

    getProductJan(productCode) {
      return this.getProductMeta(productCode).jan || '';
    },

    getStockSummaryTitle(row) {
      const parts = [
        '実在庫: ' + toInt(row.currentStock || 0),
        '引当可能: ' + toInt(row.reserved || 0),
        '引当済: ' + toInt(row.reservedStock || 0),
        '入荷予定残: ' + toInt(row.incomingQuantity || 0),
      ];

      return parts.join(' / ');
    },

    getProductStockSummaryTitle(product) {
      const p = this.normalizeProduct(product);

      return [
        '実在庫: ' + p.stock.actualQuantity,
        '引当可能: ' + p.stock.theoreticalQuantity,
        '引当済: ' + p.stock.reservedQuantity,
        '入荷予定残: ' + p.stock.incomingQuantity,
      ].join(' / ');
    },

    getProductIncomingTitle(product) {
      const p = this.normalizeProduct(product);

      return [
        '入荷予定残: ' + p.stock.incomingQuantity,
        '入荷予定件数: ' + p.stock.incomingCount,
      ].join(' / ');
    },

    normalizeScanCode(value) {
      return String(value || '')
        .replace(/[\uFF10-\uFF19]/g, ch => String.fromCharCode(ch.charCodeAt(0) - 0xFEE0))
        .replace(/[^\d]/g, '');
    },

    isLikelyJanInput(value, allowShort = false) {
      const code = this.normalizeScanCode(value);
      return allowShort
        ? code.length === 8 || (code.length >= 12 && code.length <= 14)
        : code.length === 13 || code.length === 14;
    },

    findJanConvertibleProduct(products, query, allowShort = false) {
      if (!this.isLikelyJanInput(query, allowShort)) return null;

      const code = this.normalizeScanCode(query);
      const exactJan = products.find(p => this.normalizeScanCode(p.jan) === code);
      if (exactJan) return exactJan;

      return products.length === 1 ? products[0] : null;
    },

    canRunProductSearch(query) {
      const q = String(query || '').trim();
      return q.length >= 2 || this.isLikelyJanInput(q, true);
    },

    canRunOrderToSearch(query) {
      const q = String(query || '').trim();
      return Array.from(q).length >= 2;
    },

    getProductCategoryOptions(depth, parentId = '') {
      const targetDepth = Number(depth);
      const normalizedParentId = String(parentId || '');

      return (this.productCategoryOptions || []).filter(category => {
        if (Number(category.depth) !== targetDepth) return false;
        if (targetDepth === 1 || normalizedParentId === '') return true;

        return String(category.parentId || '') === normalizedParentId;
      });
    },

    formatProductCategoryLabel(category) {
      const code = String(category?.code || '').trim();
      const name = String(category?.name || '').trim();

      return code ? '[' + code + ']' + name : name;
    },

    _normalizePartnerCode(value) {
      return String(value || '')
        .replace(/[\uFF10-\uFF19]/g, ch => String.fromCharCode(ch.charCodeAt(0) - 0xFEE0))
        .replace(/\D/g, '');
    },

    _isHqOrderCodeOrName(code, name) {
      const normalizedCode = this._normalizePartnerCode(code);
      const normalizedName = String(name || '').replace(/\s+/g, ' ').trim();

      return normalizedCode === HQ_ORDER_CONTRACTOR_CODE
        || normalizedName === HQ_ORDER_PARTNER_NAME
        || normalizedName.includes(HQ_ORDER_PARTNER_NAME);
    },

    _isHqWarehouseCodeOrName(code, name) {
      const normalizedCode = this._normalizePartnerCode(code);
      const normalizedName = String(name || '').replace(/\s+/g, ' ').trim();

      return normalizedCode === HQ_WAREHOUSE_CODE
        || normalizedName === HQ_WAREHOUSE_NAME
        || normalizedName.includes(HQ_WAREHOUSE_NAME);
    },

    isHqOrderProduct(product) {
      const p = this.normalizeProduct(product);

      return this._isHqOrderCodeOrName(p.orderToCode, p.orderTo)
        || this._isHqOrderCodeOrName(p.supplierCode, p.supplier);
    },

    shouldBlockDirectHqOrder(product) {
      return !!this._searchIsDirectTab && this.isHqOrderProduct(product);
    },

    getDirectHqOrderBlockedMessage() {
      return '本部発注の発注先・仕入先は直送分配では追加できません。本部分配画面で処理してください。';
    },

    isDirectRow(row) {
      return !!row && this.directRows.some(r => r.id === row.id);
    },

    saveRowsForMode(isDirect) {
      if (isDirect) {
        this.saveDirectData();
      } else {
        this.saveData();
      }
    },

    async handleOrderDateChanged(row, isDirect = false) {
      if (!row || this.isRowEditLocked(row)) return;

      const normalizedOrderDate = this.normalizeFlexibleDate(row.orderDate || '');
      row.orderDate = normalizedOrderDate || '';
      this.rememberFilterRetainedRow(row, isDirect);

      if (!row.orderDate || !this.hasRowProductInfo(row)) {
        this.saveRowsForMode(isDirect);
        return;
      }

      try {
        await this.refreshRowDeliveryDateForOrderDate(row);
      } catch (e) {
        console.warn('Failed to refresh delivery date:', e);
        this.showToast('納品希望日の再計算に失敗しました');
      }

      this.saveRowsForMode(isDirect);
    },

    getBulkAllocationDateTargets() {
      return this.getVisibleRows().filter(row => row && !this.isRowEditLocked(row));
    },

    hasBulkAllocationDateTargets() {
      return this.getBulkAllocationDateTargets().length > 0;
    },

    getBulkDateRowLabel(row) {
      return String(row?.productCode || row?.name || row?.id || '-');
    },

    formatDeliveryCourseLabel(course) {
      if (!course) return '';
      const code = String(course.code || '');
      const name = String(course.name || '');
      return (code ? '[' + code + ']' : '') + name;
    },

    async openBulkDeliveryCourseOptions() {
      if (this.bulkDeliveryCourseOptions.length === 0 && !this.bulkDeliveryCourseLoading) {
        await this.searchBulkDeliveryCourses(true);
      }
    },

    async searchBulkDeliveryCourses(force = false) {
      const search = String(this.bulkDeliveryCourseSearch || '').trim();
      if (!force && search === '') {
        this.bulkDeliveryCourseOptions = [];
        this.bulkDeliveryCourseLoaded = false;
        this.bulkDeliveryCourseError = '';
        return;
      }

      if (this.bulkDeliveryCourseAbortController) {
        this.bulkDeliveryCourseAbortController.abort();
      }

      const controller = new AbortController();
      this.bulkDeliveryCourseAbortController = controller;
      this.bulkDeliveryCourseLoading = true;
      this.bulkDeliveryCourseLoaded = true;
      this.bulkDeliveryCourseError = '';

      try {
        const params = new URLSearchParams({ limit: '20' });
        if (search !== '') params.set('search', search);

        const response = await fetch('/api/distribution/delivery-courses?' + params.toString(), {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(payload?.result?.error_message || payload?.message || ('HTTP ' + response.status));
        }

        this.bulkDeliveryCourseOptions = Array.isArray(payload?.result?.data) ? payload.result.data : [];
      } catch (e) {
        if (e.name === 'AbortError') return;
        console.error('Failed to search delivery courses:', e);
        this.bulkDeliveryCourseOptions = [];
        this.bulkDeliveryCourseError = '配送コースの検索に失敗しました';
      } finally {
        if (this.bulkDeliveryCourseAbortController === controller) {
          this.bulkDeliveryCourseAbortController = null;
          this.bulkDeliveryCourseLoading = false;
        }
      }
    },

    selectBulkDeliveryCourse(course) {
      if (!course) return;
      this.bulkDeliveryCourseSelected = {
        id: toInt(course.id || 0),
        code: String(course.code || ''),
        name: String(course.name || ''),
        warehouse_id: course.warehouse_id || null,
        warehouse_code: String(course.warehouse_code || ''),
        warehouse_name: String(course.warehouse_name || ''),
      };
      this.bulkDeliveryCourseSearch = this.formatDeliveryCourseLabel(this.bulkDeliveryCourseSelected);
      this.bulkDeliveryCourseOptions = [];
      this.bulkDeliveryCourseError = '';
    },

    selectExactBulkDeliveryCourse() {
      const search = String(this.bulkDeliveryCourseSearch || '').trim();
      if (search === '') return;

      const normalized = this.normalizeSearchText ? this.normalizeSearchText(search) : search;
      const exact = this.bulkDeliveryCourseOptions.find(course => {
        const code = String(course.code || '');
        const label = this.formatDeliveryCourseLabel(course);
        return code === normalized || label === search;
      });

      if (exact) {
        this.selectBulkDeliveryCourse(exact);
      }
    },

    clearBulkDeliveryCourse() {
      this.bulkDeliveryCourseSearch = '';
      this.bulkDeliveryCourseSelected = null;
      this.bulkDeliveryCourseOptions = [];
      this.bulkDeliveryCourseError = '';
    },

    async applyBulkAllocationDates() {
      if (this.bulkDateApplying) return;

      const rawInputDate = String(this.bulkInputDate || '').trim();
      const inputDate = this.normalizeFlexibleDate(rawInputDate);
      const hasDeliveryCourseInput = String(this.bulkDeliveryCourseSearch || '').trim() !== '';
      const selectedCourse = this.bulkDeliveryCourseSelected && toInt(this.bulkDeliveryCourseSelected.id || 0) > 0
        ? this.bulkDeliveryCourseSelected
        : null;

      if (rawInputDate && !inputDate) {
        this.openModal('エラー', '入力日の日付形式を確認してください。');
        return;
      }

      this.bulkInputDate = inputDate;

      if (hasDeliveryCourseInput && !selectedCourse) {
        this.openModal('注意', '配送コースは検索候補から選択してください。');
        return;
      }

      if (!inputDate && !selectedCourse) {
        this.openModal('注意', '入力日、または配送コースを指定してください。');
        return;
      }

      const targets = this.getBulkAllocationDateTargets();
      if (targets.length === 0) {
        this.openModal('注意', '一括設定を反映できる表示中のデータがありません。');
        return;
      }

      this.bulkDateApplying = true;
      let updatedCount = 0;

      try {
        for (const row of targets) {
          let changed = false;

          if (inputDate) {
            row.orderDate = inputDate;
            row.deliveryDate = inputDate;
            row.suggestedDeliveryDate = inputDate;
            row.deliveryDateCalculation = null;
            this.rememberFilterRetainedRow(row, false);
            changed = true;
          }

          if (selectedCourse) {
            row.deliveryCourseId = toInt(selectedCourse.id || 0);
            row.deliveryCourseCode = String(selectedCourse.code || '');
            row.deliveryCourseName = String(selectedCourse.name || '');
            changed = true;
          }

          if (changed) {
            updatedCount++;
          }
        }

        this.saveData();

        const details = [];
        if (inputDate) details.push('入力日: ' + inputDate);
        if (selectedCourse) details.push('配送コース: ' + this.formatDeliveryCourseLabel(selectedCourse));

        this.openResultModal(
          '一括設定',
          '表示中の修正可能なデータ ' + updatedCount + '件に一括設定を反映しました。' + (details.length > 0 ? '\n' + details.join('\n') : ''),
          'success'
        );
      } finally {
        this.bulkDateApplying = false;
      }
    },

    async refreshRowDeliveryDateForOrderDate(row) {
      const query = String(row?.productCode || row?.jan || '').trim();
      const orderDate = this.normalizeFlexibleDate(row?.orderDate || '') || '';
      if (!row?.id || !query || !orderDate) return false;

      const token = uuid();
      this.orderDateResolveTokens[row.id] = token;

      try {
        const products = (await this.fetchProductCandidates(query, orderDate, 50, {
          orderToSearch: row.orderToCode || row.orderTo || '',
          supplierSearch: row.supplierCode || row.supplier || '',
        })).map(product => this.normalizeProduct(product));

        if (this.orderDateResolveTokens[row.id] !== token) return false;

        const product = this.pickProductForExistingRow(row, products);
        if (!product?.suggestedDeliveryDate) return false;

        this.rememberProduct(product);
        row.deliveryDate = product.suggestedDeliveryDate;
        row.suggestedDeliveryDate = product.suggestedDeliveryDate;
        row.deliveryDateCalculation = product.deliveryDateCalculation || null;

        return true;
      } finally {
        if (this.orderDateResolveTokens[row.id] === token) {
          delete this.orderDateResolveTokens[row.id];
        }
      }
    },

    prepareProductSearch(rowId, isDirect = false, query = '') {
      const targetRows = isDirect ? this.directRows : this.rows;
      const targetRow = targetRows.find(row => String(row.id) === String(rowId));

      if (this.isProductEditLocked(targetRow)) {
        this.openModal('エラー', '発注候補生成済み、または修正不可の行は商品を変更できません。');
        return false;
      }

      this.searchOpenForRow = rowId;
      this._searchIsDirectTab = !!isDirect;
      this.productSearchQuery = String(query || '').trim();
      this.productOrderToQuery = '';
      this.productSupplierQuery = '';
      this.productLargeCategoryId = '';
      this.productMiddleCategoryId = '';
      this.productSmallCategoryId = '';
      this.productMaster = [];
      this.productSelectedCodes = [];
      this.productSearchLoaded = false;
      this.productSearchError = '';
      this.$nextTick(() => {
        if (this.$refs.productSearchInput) {
          this.$refs.productSearchInput.focus();
        }
      });
      return true;
    },

    async openProductSearchWithQuery(rowId, query, isDirect = false, preloadedProducts = null) {
      if (!this.prepareProductSearch(rowId, isDirect, query)) return;
      if (Array.isArray(preloadedProducts)) {
        this.productMaster = preloadedProducts.map(product => this.normalizeProduct(product));
        this.productMaster.forEach(product => this.rememberProduct(product));
        this.productSearchLoaded = true;
        this.productSearchLoading = false;
        return;
      }

      if (this.canRunProductSearch(query)) {
        await this.loadProductMaster();
      }
    },

    async fetchProductCandidates(search, orderDate, limit = 10, filters = {}) {
      const params = new URLSearchParams({
        limit: String(limit),
        order_date: orderDate || getTodayString(),
        search: String(search || '').trim(),
      });
      const orderToSearch = String(filters.orderToSearch || filters.order_to_search || '').trim();
      const supplierSearch = String(filters.supplierSearch || filters.supplier_search || '').trim();
      const category1Id = String(filters.category1Id || filters.category1_id || '').trim();
      const category2Id = String(filters.category2Id || filters.category2_id || '').trim();
      const category3Id = String(filters.category3Id || filters.category3_id || '').trim();
      if (orderToSearch) {
        params.set('order_to_search', orderToSearch);
      }
      if (supplierSearch) {
        params.set('supplier_search', supplierSearch);
      }
      if (category1Id) {
        params.set('category1_id', category1Id);
      }
      if (category2Id) {
        params.set('category2_id', category2Id);
      }
      if (category3Id) {
        params.set('category3_id', category3Id);
      }
      const response = await fetch('/api/distribution/products?' + params.toString(), {
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }

      const payload = await response.json();
      return Array.isArray(payload?.result?.data)
        ? payload.result.data
        : (Array.isArray(payload) ? payload : []);
    },

    async fetchProductCandidatesBatch(queries = []) {
      const results = new Map();
      const normalizedQueries = queries
        .map((query, index) => ({
          key: String(query.key ?? index),
          search: String(query.search || '').trim(),
          order_date: query.order_date || getTodayString(),
          order_to_search: String(query.order_to_search || query.orderToSearch || '').trim(),
          supplier_search: String(query.supplier_search || query.supplierSearch || '').trim(),
        }))
        .filter(query => query.search !== '');

      for (let offset = 0; offset < normalizedQueries.length; offset += 500) {
        const chunk = normalizedQueries.slice(offset, offset + 500);
        const response = await fetch('/api/distribution/products/batch', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...this.csrfHeaders(),
          },
          body: JSON.stringify({ queries: chunk }),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
          const message = payload?.result?.error_message
            || payload?.message
            || ('HTTP ' + response.status);
          throw new Error(message);
        }

        const items = Array.isArray(payload?.result?.data?.items)
          ? payload.result.data.items
          : [];

        items.forEach(item => {
          results.set(String(item.key), Array.isArray(item.products) ? item.products : []);
        });
      }

      return results;
    },

    async refreshOrderCandidateRowsDatesForToday(rows = []) {
      const targets = this.getOrderCandidatePastDateRows(rows);
      if (targets.length === 0) return 0;

      const today = getTodayString();
      const queries = targets.map(row => ({
        key: String(row.id),
        search: row.productCode || row.jan || '',
        order_date: today,
        order_to_search: row.orderToCode || row.orderTo || '',
        supplier_search: row.supplierCode || row.supplier || '',
      }));
      const productsByRowId = await this.fetchProductCandidatesBatch(queries);
      const unresolvedRows = [];
      const updates = [];

      targets.forEach(row => {
        const products = productsByRowId.get(String(row.id)) || [];
        const product = this.pickProductForExistingRow(row, products);
        const normalizedProduct = product ? this.normalizeProduct(product) : null;

        if (!normalizedProduct || !normalizedProduct.suggestedDeliveryDate) {
          unresolvedRows.push(row);
          return;
        }

        updates.push({ row, product: normalizedProduct });
      });

      if (unresolvedRows.length > 0) {
        const examples = unresolvedRows
          .slice(0, 5)
          .map(row => String(row.productCode || row.name || row.id || '-'))
          .join('、');
        const suffix = unresolvedRows.length > 5 ? ' ほか' + (unresolvedRows.length - 5) + '件' : '';
        throw new Error('納品希望日の再計算ができない商品があります。商品情報を確認してください。対象: ' + examples + suffix);
      }

      updates.forEach(({ row, product }) => {
        this.rememberProduct(product);
        row.orderDate = today;
        row.deliveryDate = product.suggestedDeliveryDate;
        row.suggestedDeliveryDate = product.suggestedDeliveryDate;
        row.deliveryDateCalculation = product.deliveryDateCalculation || null;
      });

      return targets.length;
    },

    isExactProductCandidate(product, query) {
      const q = String(query || '').trim();
      const scan = this.normalizeScanCode(q);
      return String(product.code || '') === q
        || this.normalizeScanCode(product.code) === scan
        || (scan !== '' && this.normalizeScanCode(product.jan) === scan);
    },

    isJanConversion(product, query) {
      const scan = this.normalizeScanCode(query);
      return scan !== ''
        && this.normalizeScanCode(product.jan) === scan
        && String(product.code || '') !== String(query || '').trim();
    },

    clearProductFromRow(row) {
      if (!row) return;

      const productCode = String(row.productCode || '').trim();
      row.productCode = productCode;
      row.itemId = null;
      row.candidateKey = '';
      row.itemContractorId = null;
      row.contractorId = null;
      row.supplierId = null;
      row.contractorWarehouseId = null;
      row.jan = '';
      row.name = '';
      row.volume = '';
      row.unitsPerCase = 0;
      row.purchaseUnit = 1;
      row.lot = 0;
      row.orderPoint = 0;
      row.shelfLocation = '';
      row.lastOrderDate = '';
      row.salesWeek1 = 0;
      row.salesWeek2 = 0;
      row.salesWeek3 = 0;
      row.currentStock = 0;
      row.reserved = 0;
      row.reservedStock = 0;
      row.incomingQuantity = 0;
      row.incomingCount = 0;
      row.selectedWarehouseId = null;
      row.realWarehouseId = null;
      row.supplier = '';
      row.supplierCode = '';
      row.orderTo = '';
      row.orderToCode = '';
      row.itemContractorNote = '';
      row.suggestedDeliveryDate = '';
      row.deliveryDateCalculation = null;
    },

    getUniqueCachedProductCandidate(candidates) {
      if (!Array.isArray(candidates) || candidates.length !== 1) return null;

      return candidates[0] || null;
    },

    findCachedExactProduct(query) {
      const q = String(query || '').trim();
      if (!q) return null;

      const codeCandidates = this.productCandidatesByCode[q];
      if (Array.isArray(codeCandidates)) {
        return this.getUniqueCachedProductCandidate(codeCandidates);
      }

      const janKey = this.normalizeScanCode(q);
      const janCandidates = this.productCandidatesByJan[janKey];
      if (Array.isArray(janCandidates)) {
        return this.getUniqueCachedProductCandidate(janCandidates);
      }

      const meta = this.productMetaByCode[q];
      return meta ? this.normalizeProduct(meta) : null;
    },

    invalidateProductCodeFastResolve(row) {
      if (!row?.id) return;

      this.productCodeResolveTokens[row.id] = uuid();
    },

    async resolveManualProductCodeFast(row) {
      if (!row || this.isProductEditLocked(row)) return;

      const query = String(row.productCode || '').trim();
      const isDirect = this.isDirectRow(row);
      row.productCode = query;

      if (!query) {
        this.invalidateProductCodeFastResolve(row);
        this.clearProductFromRow(row);
        this.productCodeEditBefore[row.id] = '';
        this.saveRowsForMode(isDirect);
        return;
      }

      const cachedProduct = this.findCachedExactProduct(query);
      if (cachedProduct) {
        this.applyProductToRow(row, cachedProduct);
        this.productCodeEditBefore[row.id] = row.productCode;
        this.saveRowsForMode(isDirect);
        return;
      }

      if (!this.canRunProductSearch(query)) return;

      const token = uuid();
      this.productCodeResolveTokens[row.id] = token;
      const normalizedOrderDate = this.normalizeFlexibleDate(row.orderDate || '') || getTodayString();
      row.orderDate = normalizedOrderDate;

      try {
        const products = (await this.fetchProductCandidates(query, normalizedOrderDate, 10))
          .map(product => this.normalizeProduct(product));

        if (this.productCodeResolveTokens[row.id] !== token) return;
        if (String(row.productCode || '').trim() !== query) return;

        products.forEach(product => this.rememberProduct(product));
        const exactProducts = products.filter(product => this.isExactProductCandidate(product, query));

        if (exactProducts.length === 1) {
          this.applyProductToRow(row, exactProducts[0]);
          this.productCodeEditBefore[row.id] = row.productCode;
          this.saveRowsForMode(isDirect);
        }
      } catch (e) {
        // Keep typing responsive; blur/Enter still performs the full resolver with feedback.
        console.debug('Fast product resolve skipped:', e);
      }
    },

    async resolveManualProductCode(row) {
      if (!row || this.isProductEditLocked(row)) return;

      this.invalidateProductCodeFastResolve(row);

      const query = String(row.productCode || '').trim();
      const previousQuery = String(this.productCodeEditBefore[row.id] ?? '').trim();
      const isDirect = this.isDirectRow(row);
      row.productCode = query;

      if (row.itemId && query !== '' && query === previousQuery) {
        delete this.productCodeEditBefore[row.id];
        return;
      }

      if (!query) {
        this.clearProductFromRow(row);
        this.productCodeEditBefore[row.id] = '';
        this.saveRowsForMode(isDirect);
        return;
      }

      const normalizedOrderDate = this.normalizeFlexibleDate(row.orderDate || '') || getTodayString();
      row.orderDate = normalizedOrderDate;

      try {
        const products = (await this.fetchProductCandidates(query, normalizedOrderDate, 50))
          .map(product => this.normalizeProduct(product));
        products.forEach(product => this.rememberProduct(product));

        const exactProducts = products.filter(product => this.isExactProductCandidate(product, query));
        const candidates = exactProducts.length > 0 ? exactProducts : products;

        if (candidates.length === 1) {
          const product = candidates[0];
          this.applyProductToRow(row, product);
          this.productCodeEditBefore[row.id] = row.productCode;
          this.saveRowsForMode(isDirect);

          if (this.isJanConversion(product, query)) {
            this.showToast('JANを商品コード ' + product.code + ' に変換しました');
          }
          return;
        }

        if (candidates.length > 1) {
          this.productCodeEditBefore[row.id] = query;
          await this.openProductSearchWithQuery(row.id, query, isDirect, candidates);
          return;
        }

        this.clearProductFromRow(row);
        this.productCodeEditBefore[row.id] = row.productCode;
        this.saveRowsForMode(isDirect);
        this.showToast('商品コードが見つかりません: ' + query);
      } catch (e) {
        console.error('Failed to resolve product code:', e);
        this.productCodeEditBefore[row.id] = query;
        await this.openProductSearchWithQuery(row.id, query, isDirect);
      }
    },

    // ---------- Product search modal ----------
    openProductSearch(rowId, options = {}) {
      this.prepareProductSearch(rowId, false, options.query || '');
    },

    async loadProductMaster(options = {}) {
      const q = (this.productSearchQuery || '').trim();
      const orderToQuery = (this.productOrderToQuery || '').trim();
      const supplierQuery = (this.productSupplierQuery || '').trim();
      const category1Id = String(this.productLargeCategoryId || '').trim();
      const category2Id = String(this.productMiddleCategoryId || '').trim();
      const category3Id = String(this.productSmallCategoryId || '').trim();
      const canSearchProduct = q === '' || this.canRunProductSearch(q);
      const canSearchOrderTo = orderToQuery === '' || this.canRunOrderToSearch(orderToQuery);
      const canSearchSupplier = supplierQuery === '' || this.canRunOrderToSearch(supplierQuery);
      this.productSearchError = '';

      if (this.productSearchAbortController) {
        this.productSearchAbortController.abort();
      }

      const hasSearchableProduct = q && canSearchProduct;
      const hasSearchableOrderTo = orderToQuery && canSearchOrderTo;
      const hasSearchableSupplier = supplierQuery && canSearchSupplier;
      const hasSearchableCategory = category1Id || category2Id || category3Id;

      if (!hasSearchableProduct && !hasSearchableOrderTo && !hasSearchableSupplier && !hasSearchableCategory) {
        this.productMaster = [];
        this.productSearchLoading = false;
        this.productSearchLoaded = false;
        this.productSearchAbortController = null;
        return [];
      }

      const controller = new AbortController();
      this.productSearchAbortController = controller;
      this.productSearchLoading = true;
      this.productSearchLoaded = true;
      this.productSelectedCodes = [];
      const targetRows = this._searchIsDirectTab ? this.directRows : this.rows;
      const targetRow = targetRows.find(r => r.id === this.searchOpenForRow);
      const normalizedOrderDate = this.normalizeFlexibleDate(targetRow?.orderDate || '') || getTodayString();
      if (targetRow) {
        targetRow.orderDate = normalizedOrderDate;
      }

      try {
        const params = new URLSearchParams({ limit: '50', order_date: normalizedOrderDate });
        if (q && canSearchProduct) {
          params.set('search', q);
        }
        if (orderToQuery && canSearchOrderTo) {
          params.set('order_to_search', orderToQuery);
        }
        if (supplierQuery && canSearchSupplier) {
          params.set('supplier_search', supplierQuery);
        }
        if (category1Id) {
          params.set('category1_id', category1Id);
        }
        if (category2Id) {
          params.set('category2_id', category2Id);
        }
        if (category3Id) {
          params.set('category3_id', category3Id);
        }
        const response = await fetch('/api/distribution/products?' + params.toString(), {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        const payload = await response.json();
        const data = Array.isArray(payload?.result?.data)
          ? payload.result.data
          : (Array.isArray(payload) ? payload : []);

        this.productMaster = data.map(p => this.normalizeProduct(p));
        this.productMaster.forEach(p => this.rememberProduct(p));

        if (options.autoSelectJan && q && this.searchOpenForRow !== null) {
          const product = this.findJanConvertibleProduct(
            this.productMaster,
            q,
            !!options.allowShortJan
          );

          if (product) {
            this.selectProduct(product, { convertedFromJan: q });
          }
        }

        return this.productMaster;
      } catch (e) {
        if (e.name === 'AbortError') return [];
        console.error('Failed to search product master:', e);
        this.productMaster = [];
        this.productSearchError = '商品マスタ検索に失敗しました';
        return [];
      } finally {
        if (this.productSearchAbortController === controller) {
          this.productSearchLoading = false;
          this.productSearchAbortController = null;
        }
      }
    },

    applyProductToRow(row, product) {
      row.productCode = product.code;
      row.itemId = product.id;
      row.candidateKey = product.candidateKey || '';
      row.itemContractorId = product.itemContractorId || null;
      row.contractorId = product.contractorId || null;
      row.supplierId = product.supplierId || null;
      row.contractorWarehouseId = product.contractorWarehouseId || null;
      row.jan = product.jan || '';
      row.name = product.name;
      row.volume = product.volume || '';
      row.unitsPerCase = product.unitsPerCase;
      row.purchaseUnit = product.purchaseUnit;
      row.lot = product.purchaseUnit || 0;
      row.orderPoint = product.orderPoint || 0;
      row.shelfLocation = product.shelfLocation || '';
      row.lastOrderDate = product.lastOrderDate || '';
      row.salesWeek1 = product.salesWeek1 || 0;
      row.salesWeek2 = product.salesWeek2 || 0;
      row.salesWeek3 = product.salesWeek3 || 0;
      row.currentStock = product.stock.actualQuantity;
      row.reserved = product.stock.theoreticalQuantity;
      row.reservedStock = product.stock.reservedQuantity;
      row.incomingQuantity = product.stock.incomingQuantity;
      row.incomingCount = product.stock.incomingCount;
      row.selectedWarehouseId = product.stock.selectedWarehouseId;
      row.realWarehouseId = product.stock.realWarehouseId;
      row.supplier = product.supplier;
      row.supplierCode = product.supplierCode;
      row.orderTo = product.orderTo;
      row.orderToCode = product.orderToCode;
      row.itemContractorNote = product.itemContractorNote || '';
      row.suggestedDeliveryDate = product.suggestedDeliveryDate || '';
      row.deliveryDateCalculation = product.deliveryDateCalculation || null;
      if (product.suggestedDeliveryDate) {
        row.deliveryDate = product.suggestedDeliveryDate;
      }
      this.rememberProduct(product);
    },

    applyProductStockToRow(row, product) {
      if (!row || !product?.stock) return;

      row.currentStock = product.stock.actualQuantity;
      row.reserved = product.stock.theoreticalQuantity;
      row.reservedStock = product.stock.reservedQuantity;
      row.incomingQuantity = product.stock.incomingQuantity;
      row.incomingCount = product.stock.incomingCount;
      row.selectedWarehouseId = product.stock.selectedWarehouseId;
      row.realWarehouseId = product.stock.realWarehouseId;
      this.rememberProduct(product);
    },

    pickProductForExistingRow(row, products) {
      const normalizedProducts = products.map(product => this.normalizeProduct(product));
      normalizedProducts.forEach(product => this.rememberProduct(product));

      if (row?.candidateKey) {
        const byCandidateKey = normalizedProducts.find(product => String(product.candidateKey || '') === String(row.candidateKey));
        if (byCandidateKey) return byCandidateKey;
      }

      if (row?.itemContractorId) {
        const byContractor = normalizedProducts.find(product => String(product.itemContractorId || '') === String(row.itemContractorId));
        if (byContractor) return byContractor;
      }

      if (row?.itemId) {
        const byItem = normalizedProducts.find(product => String(product.id || '') === String(row.itemId));
        if (byItem) return byItem;
      }

      const exactProducts = normalizedProducts.filter(product => this.isExactProductCandidate(product, row?.productCode || row?.jan || ''));
      if (exactProducts.length === 1) return exactProducts[0];

      return normalizedProducts.length === 1 ? normalizedProducts[0] : null;
    },

    getSelectedProductsFromMaster() {
      const selectedKeys = new Set(this.productSelectedCodes.map(code => String(code)));
      return this.productMaster
        .map(product => this.normalizeProduct(product))
        .filter(product => selectedKeys.has(product.candidateKey || product.code));
    },

    selectCheckedProducts() {
      const selectedProducts = this.getSelectedProductsFromMaster();
      const blockedProducts = selectedProducts.filter(product => this.shouldBlockDirectHqOrder(product));
      const products = selectedProducts.filter(product => !this.shouldBlockDirectHqOrder(product));

      if (blockedProducts.length > 0) {
        const blockedKeys = new Set(blockedProducts.map(product => String(product.candidateKey || product.code)));
        this.productSelectedCodes = this.productSelectedCodes.filter(code => !blockedKeys.has(String(code)));
        this.openModal('注意', this.getDirectHqOrderBlockedMessage());
      }

      if (products.length === 0) return;

      const targetRows = this._searchIsDirectTab ? this.directRows : this.rows;
      const rowIndex = targetRows.findIndex(row => row.id === this.searchOpenForRow);
      const baseRow = rowIndex >= 0 ? targetRows[rowIndex] : null;
      if (this.isProductEditLocked(baseRow)) {
        this.openModal('エラー', '発注候補生成済み、または修正不可の行は商品を変更できません。');
        this.closeProductSearch();
        return;
      }

      const baseOrderDate = baseRow?.orderDate || getTodayString();
      const rowsToInsert = [];

      products.forEach((product, index) => {
        if (index === 0 && baseRow) {
          this.applyProductToRow(baseRow, product);
          return;
        }

        const row = this._searchIsDirectTab
          ? this.makeDirectRow({ orderDate: baseOrderDate })
          : this.makeAllocationRow({ orderDate: baseOrderDate });
        this.applyProductToRow(row, product);
        rowsToInsert.push(row);
      });

      if (rowsToInsert.length > 0) {
        if (rowIndex >= 0) {
          targetRows.splice(rowIndex + 1, 0, ...rowsToInsert);
        } else {
          targetRows.push(...rowsToInsert);
        }
      }

      this.showToast(products.length + '件の商品を追加しました');
      this.closeProductSearch();
    },

    selectProduct(p, options = {}) {
      const product = this.normalizeProduct(p);
      if (this.shouldBlockDirectHqOrder(product)) {
        this.openModal('注意', this.getDirectHqOrderBlockedMessage());
        return;
      }

      const targetRows = this._searchIsDirectTab ? this.directRows : this.rows;
      const row = targetRows.find(r => r.id === this.searchOpenForRow);
      if (row) {
        if (this.isProductEditLocked(row)) {
          this.openModal('エラー', '発注候補生成済み、または修正不可の行は商品を変更できません。');
          this.closeProductSearch();
          return;
        }

        this.applyProductToRow(row, product);

        if (options.convertedFromJan) {
          this.showToast('JANを商品コード ' + product.code + ' に変換しました');
        }
      }
      this.closeProductSearch();
    },

    closeProductSearch() {
      if (this.productSearchAbortController) {
        this.productSearchAbortController.abort();
      }
      this.searchOpenForRow = null;
      this.productSearchQuery = '';
      this.productOrderToQuery = '';
      this.productSupplierQuery = '';
      this.productLargeCategoryId = '';
      this.productMiddleCategoryId = '';
      this.productSmallCategoryId = '';
      this.productMaster = [];
      this.productSelectedCodes = [];
      this.productSearchLoading = false;
      this.productSearchLoaded = false;
      this.productSearchError = '';
      this.productSearchAbortController = null;
      this._searchIsDirectTab = false;
    },

    // ---------- Arrival schedule modal ----------
    hasArrivalSchedule(rowOrCode) {
      if (typeof rowOrCode === 'object' && rowOrCode !== null) {
        return toInt(rowOrCode.incomingCount || 0) > 0 || toInt(rowOrCode.incomingQuantity || 0) > 0;
      }

      const productCode = String(rowOrCode || '');
      if (!productCode) return false;

      const meta = this.getProductMeta(productCode);
      return toInt(meta?.stock?.incomingCount || 0) > 0 || toInt(meta?.stock?.incomingQuantity || 0) > 0;
    },

    async openArrivalSchedule(rowOrCode) {
      const productCode = typeof rowOrCode === 'object' ? rowOrCode.productCode : rowOrCode;
      const itemId = typeof rowOrCode === 'object'
        ? (rowOrCode.itemId || this.getProductMeta(rowOrCode.productCode).id || null)
        : (this.getProductMeta(productCode).id || null);

      this.arrivalScheduleProduct = productCode;
      this.arrivalScheduleItemId = itemId;
      this.arrivalScheduleItems = [];
      this.arrivalScheduleLoading = false;
      this.arrivalScheduleLoaded = false;
      this.arrivalScheduleError = '';

      await this.loadArrivalSchedules(productCode, itemId);
    },

    async loadArrivalSchedules(productCode, itemId = null) {
      if (!productCode && !itemId) {
        this.arrivalScheduleItems = [];
        this.arrivalScheduleLoaded = true;
        return;
      }

      this.arrivalScheduleLoading = true;
      this.arrivalScheduleLoaded = true;
      this.arrivalScheduleError = '';

      try {
        const params = new URLSearchParams({ limit: '20' });
        if (itemId) params.set('item_id', itemId);
        if (productCode) params.set('product_code', productCode);

        const response = await fetch('/api/distribution/arrival-schedules?' + params.toString(), {
          headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        const payload = await response.json();
        this.arrivalScheduleItems = Array.isArray(payload?.result?.data)
          ? payload.result.data
          : (Array.isArray(payload) ? payload : []);
      } catch (e) {
        console.error('Failed to load arrival schedules:', e);
        this.arrivalScheduleItems = [];
        this.arrivalScheduleError = '入荷予定の取得に失敗しました';
      } finally {
        this.arrivalScheduleLoading = false;
      }
    },

    getArrivalSchedule(productCode) {
      if (!productCode) return [];
      return this.arrivalScheduleItems || [];
    },

    formatArrivalQuantity(item) {
      const label = item.quantityTypeLabel ? ' ' + item.quantityTypeLabel : '';
      return `${item.quantity ?? 0}${label}`;
    },

    formatArrivalOrderTo(item) {
      if (!item?.orderTo) return '-';
      return `${item.orderToCode ? '[' + item.orderToCode + ']' : ''}${item.orderTo}`;
    },

    formatArrivalSupplier(item) {
      if (!item?.supplier) return '-';
      return `${item.supplierCode ? '[' + item.supplierCode + ']' : ''}${item.supplier}`;
    },

    formatArrivalWarehouse(item) {
      if (!item?.warehouse) return '-';
      return `${item.warehouseCode ? '[' + item.warehouseCode + ']' : ''}${item.warehouse}`;
    },

    formatArrivalStatus(item) {
      return item?.statusLabel || item?.status || '-';
    },

    formatArrivalDetail(item) {
      const parts = [];
      if (item.slipNumber) parts.push(`伝票: ${item.slipNumber}`);
      if (item.purchaseSlipNumber) parts.push(`仕入伝票: ${item.purchaseSlipNumber}`);
      if (item.note) parts.push(item.note);

      return parts.length > 0 ? parts.join(' / ') : '-';
    },

    // ---------- Lot popover ----------
    toggleLotPopover(rowId) {
      this.lotPopoverRowId = this.lotPopoverRowId === rowId ? null : rowId;
    },

    saveMemoPopup() {
      const row = this.rows.find(x => x.id === this.memoPopupRowId);
      if (!row) {
        this.memoPopupRowId = null;
        return;
      }

      if (this.isRowEditLocked(row)) {
        this.memoPopupRowId = null;
        this.openModal('エラー', '修正不可の行は明細備考を修正できません');
        return;
      }

      row.memo = this.memoPopupText;
      this.saveData();
      this.memoPopupRowId = null;
    },

    // ---------- Save / Clear ----------
    saveData() {
      try {
        this.normalizeRowsDateFields(this.rows, true);
        const persistable = this.getPersistableAllocationRows();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(persistable));
        this.touchStoreRows();
        this.showToast('保存しました（' + persistable.length + '行）');
      } catch (e) {
        console.error(e);
        this.openModal('エラー', '保存に失敗しました');
      }
    },

    clearAll() {
      this.openConfirm('全データをクリアします。よろしいですか？', () => {
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(DELETED_ALLOCATION_SOURCE_KEY);
        this.deletedAllocationSourceKeys = [];
        this.rows = makeInitialRows();
        this.touchStoreRows();
      });
    },

    // ========== 直送分配 Methods ==========
    saveDirectData() {
      try {
        this.normalizeRowsDateFields(this.directRows, true);
        const active = this.directRows
          .filter(r => r.productCode)
          .map(row => {
            const copy = { ...row };
            delete copy.storeSourceType;
            return copy;
          });
        localStorage.setItem(DIRECT_STORAGE_KEY, JSON.stringify(active));
        this.touchStoreRows();
      } catch (e) {
        this.openModal('エラー', '保存に失敗しました');
      }
    },

    makeDirectRow(overrides = {}) {
      return this.normalizeRowDateFields({
        id: uuid(),
        itemId: null,
        candidateKey: '',
        itemContractorId: null,
        contractorId: null,
        supplierId: null,
        contractorWarehouseId: null,
        productCode: '',
        jan: '',
        name: '',
        lot: 0,
        unitsPerCase: 1,
        purchaseUnit: 1,
        currentStock: 0,
        reserved: 0,
        reservedStock: 0,
        incomingQuantity: 0,
        incomingCount: 0,
        orderPoint: 0,
        shelfLocation: '',
        lastOrderDate: '',
        salesWeek1: 0,
        salesWeek2: 0,
        salesWeek3: 0,
        selectedWarehouseId: null,
        realWarehouseId: null,
        poCase: '',
        poEach: '',
        checked: false,
        printed: false,
        locked: false,
        warehouseTransferGenerated: false,
        warehouseTransferQueueIds: [],
        warehouseTransferCreatedAt: '',
        orderCandidateGenerated: false,
        orderCandidateIds: [],
        orderCandidateCreatedAt: '',
        memo: '',
        itemContractorNote: '',
        orderDate: getTodayString(),
        deliveryDate: '',
        suggestedDeliveryDate: '',
        deliveryDateCalculation: null,
        ...buildDestKeys(),
        ...overrides,
      }, true);
    },

    addDirectRow() {
      this.directRows.push(this.makeDirectRow());
    },

    removeDirectRow(id) {
      const row = this.directRows.find(r => r.id === id);
      if (row && this.isRowDeleteLocked(row)) {
        this.openModal('エラー', '確定済み、または発注候補生成済みの行は削除できません');
        return;
      }
      this.openConfirm('この行を削除しますか？', () => {
        this.directRows = this.directRows.filter(r => r.id !== id);
        this.directDeleteSelectedRowIds = this.directDeleteSelectedRowIds.filter(rowId => String(rowId) !== String(id));
        this.saveDirectData();
      }, 'delete');
    },

    openDirectProductSearch(rowId, options = {}) {
      this.prepareProductSearch(rowId, true, options.query || '');
    },

    calcDirectWishTotal(row) {
      let total = 0;
      DESTS.forEach(d => { total += toInt(row['wish_' + d.key] || 0); });
      return total;
    },

    calcDirectAllocTotal(row) {
      let total = 0;
      DESTS.forEach(d => { total += toInt(row['alloc_' + d.key] || 0); });
      return total;
    },

    allVisibleDirectChecked() {
      const visible = this.getVisibleDirectRows().filter(r => !this.isRowLocked(r));
      if (visible.length === 0) {
        const allVisible = this.getVisibleDirectRows();
        return allVisible.length > 0 && allVisible.every(r => r.checked);
      }

      return visible.length > 0 && visible.every(r => r.checked);
    },

    toggleAllDirectChecked() {
      const visible = this.getVisibleDirectRows().filter(r => !this.isRowLocked(r));
      const allChecked = visible.length > 0 && visible.every(r => r.checked);
      visible.forEach(r => { r.checked = !allChecked; });
    },

    downloadDirectCSV() {
      try {
        this.normalizeDateFilters();
        this.normalizeRowsDateFields(this.directRows, true);
        let dataRows = this.getVisibleDirectRows().filter(r => r.checked);
        if (dataRows.length === 0) {
          dataRows = this.getVisibleDirectRows();
        }
        if (dataRows.length === 0) { this.openModal('エラー', '出力するデータがありません。'); return; }
        const headers = ['計上日','発注日','商品コード','商品名','仕入先','納品希望日','ロット','入数','理論','希計','分計','発注ケース','発注バラ','明細備考',
          ...DESTS.flatMap(d => [d.name + '_希望', d.name + '_分配'])];
        const body = dataRows.map(r => {
          const vals = [this.getCsvImportPostingDate(r) || r.orderDate || '',r.orderDate||'',r.productCode,r.name,this.getSupplier(r.productCode),r.deliveryDate||'',
            toInt(r.lot),toInt(r.unitsPerCase),toInt(r.reserved),this.calcDirectWishTotal(r),this.calcDirectAllocTotal(r),toInt(r.poCase),toInt(r.poEach),r.memo||'',
            ...DESTS.flatMap(d => [toInt(r['wish_'+d.key]||0),toInt(r['alloc_'+d.key]||0)])];
          return vals.map(x => '"'+String(x??'').replace(/"/g,'""')+'"').join(',');
        });
        const csv = [headers.join(','),...body].join('\r\n');
        const blob = new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = 'direct_allocation_'+getTodayString()+'.csv';
        document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
        this.showToast('CSVを出力しました（'+dataRows.length+'行）');
      } catch(e) { this.openModal('エラー','CSV出力でエラーが発生しました'); }
    },

    printDirectRequestForm(opts) {
      try {
        const remark = opts?.remark || '';
        const tantou = opts?.tantou || '';
        const mgmtComment = opts?.mgmtComment || '';
        this.normalizeRowsDateFields(this.directRows, true);
        const dataset = this.getDirectRequestPrintableRows();
        if (dataset.length === 0) { this.openModal('エラー','出力するデータがありません。確定を入れてください。'); return; }

        // 仕入先ごとにグループ化
        const supplierGroups = {};
        dataset.forEach(r => {
          const sup = this.getSupplier(r.productCode) || '不明';
          if (!supplierGroups[sup]) supplierGroups[sup] = [];
          supplierGroups[sup].push(r);
        });

        const now = new Date();
        const issueDate = [
          now.getFullYear(),
          String(now.getMonth() + 1).padStart(2, '0'),
          String(now.getDate()).padStart(2, '0'),
        ].join('-');
        const pad2 = value => String(value).padStart(2, '0');
        const requestNumberBase = 'DR-' + [
          now.getFullYear(),
          pad2(now.getMonth() + 1),
          pad2(now.getDate()),
          pad2(now.getHours()),
          pad2(now.getMinutes()),
          pad2(now.getSeconds()),
        ].join('');
        const dests = DESTS;
        const companyLogoUrl = new URL('/images/hana-logo.png', window.location.origin).href;
        const supplierEntries = Object.entries(supplierGroups);

        const pagesHtml = supplierEntries.map(([supplier, rows], pageIndex) => {
          const activeDests = dests;
          const requestNumber = requestNumberBase + (supplierEntries.length > 1 ? '-' + String(pageIndex + 1).padStart(2, '0') : '');

          const destHeaders = activeDests.map(d => {
            let nm = d.name === '本店' ? d.name : d.name.replace(/店$/, '');
            nm = nm.replace(/サンドーム前/, 'SD前');
            return `<th style="padding:2px 2px;border:1px solid #d1d5db;background:#f3f4f6;font-size:10px;text-align:center;white-space:nowrap;">${nm}</th>`;
          }).join('');

          // 納品希望日を収集（重複排除）
          const deliveryDates = [...new Set(rows.map(r => r.deliveryDate).filter(Boolean))];

          const bodyRows = rows.map(r => {
            const destCells = activeDests.map(d => {
              const v = r['alloc_' + d.key] || 0;
              return `<td class="qty-cell">${v > 0 ? v : ''}</td>`;
            }).join('');
            const allocTotal = activeDests.reduce((s, d) => s + (r['alloc_' + d.key] || 0), 0);
            return `<tr>
              <td class="jan-cell">${r.jan || (this.getProductMeta(r.productCode) || {}).jan || ''}</td>
              <td class="product-cell">${r.name || ''}</td>
              <td class="center-cell">${r.unitsPerCase || ''}</td>
              ${destCells}
              <td class="total-cell">${allocTotal}</td>
            </tr>`;
          }).join('');

          return `<div class="page-break">
            <div class="document-topline"></div>
            <div class="document-header">
              <div class="document-left">
                <div class="document-label">DIRECT DISTRIBUTION REQUEST</div>
                <div class="document-title">直送分配 依頼書</div>
                <div class="supplier-box">
                  <div class="supplier-name">${supplier} 御中</div>
                  <div class="supplier-caption">下記内容にて、各店舗への振分対応をお願いいたします。</div>
                </div>
              </div>
              <div class="company-box">
                <img src="${companyLogoUrl}" alt="リカーワールド華" class="company-logo">
                <div class="company-name">リカーワールド華</div>
                <div>本部／商品企画課</div>
                <div>依頼番号：${requestNumber}</div>
                ${tantou ? `<div>担当：${tantou}</div>` : ''}
              </div>
            </div>
            <div class="summary-bar">
              <div class="summary-stamp-cell">
                <div class="stamp-grid">
                  <div><span>確認</span></div>
                  <div><span>処理</span></div>
                  <div><span>承認</span></div>
                </div>
              </div>
            </div>
            <div class="detail-date-lines">
              <div class="issue-date-line"><span>発行日：</span><strong>${issueDate}</strong></div>
              <div class="delivery-date-line"><span>納品希望日：</span><strong>${deliveryDates.length > 0 ? deliveryDates.join('、') : '-'}</strong></div>
            </div>
            <div class="unit-label">単位：バラ</div>
            <table class="request-list-table" style="border-collapse:collapse;width:100%;margin-bottom:12px;">
              <thead>
                <tr style="background:#f3f4f6;">
                  <th style="padding:2px 3px;border:1px solid #d1d5db;background:#f3f4f6;font-size:11px;white-space:nowrap;">JAN</th>
                  <th style="padding:2px 4px;border:1px solid #d1d5db;background:#f3f4f6;font-size:11px;">商品名</th>
                  <th style="padding:2px 3px;border:1px solid #d1d5db;background:#f3f4f6;font-size:11px;white-space:nowrap;">入数</th>
                  ${destHeaders}
                  <th style="padding:1px 3px;border:1px solid #d1d5db;background:#f3f4f6;font-size:11px;white-space:nowrap;">合計</th>
                </tr>
              </thead>
              <tbody>${bodyRows}</tbody>
            </table>
            ${remark ? `<div class="note-box"><span>伝票備考：</span>${remark.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</div>` : ''}
            <div class="footer-area">
              <div class="comment-box">
                <div class="box-label">備考</div>
                <div class="box-body">${mgmtComment ? mgmtComment.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>') : ''}</div>
              </div>
            </div>
          </div>`;
        }).join('');

        const w = window.open('', '_blank');
        if (!w) { this.openModal('エラー', 'ポップアップがブロックされています。'); return; }
        w.document.write(`<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>依頼書</title>
          <style>
            @page { size: A4 landscape; margin: 5mm; }
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family:"BIZ UDPGothic", "Yu Gothic", "YuGothic", "Meiryo", sans-serif; font-size:12px; padding:8px; color:#111827; background:#f3f4f6; overflow-x:auto; }
            .page-break { page-break-after:always; width:297mm; min-height:210mm; margin:0 auto 20px; padding:12px 14px 14px; border:1px solid #cbd5e1; background:#fff; box-shadow:0 8px 22px rgba(15,23,42,.12); }
            .page-break:last-child { page-break-after:auto; }
            .document-topline { height:1px; background:#d1d5db; margin:-12px -14px 12px; }
            .document-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:12px; }
            .document-left { min-width:0; flex:1; }
            .document-label { font-size:10px; letter-spacing:.08em; color:#6b7280; font-weight:700; margin-bottom:2px; }
            .document-title { font-size:23px; font-weight:800; line-height:1.15; color:#111827; margin-bottom:10px; }
            .supplier-box { border:1px solid #d1d5db; border-left:5px solid #374151; padding:9px 12px; background:#f9fafb; max-width:520px; }
            .supplier-name { font-size:18px; font-weight:800; border-bottom:1px solid #d1d5db; padding-bottom:4px; margin-bottom:5px; }
            .supplier-caption { font-size:11px; color:#4b5563; }
            .company-box { width:230px; border:1px solid #d1d5db; background:#fff; padding:8px 10px; text-align:right; font-size:11px; line-height:1.55; }
            .company-logo { display:block; width:100%; height:auto; margin:0 0 5px; }
            .company-name { font-size:13px; font-weight:800; color:#111827; }
            .summary-bar { display:flex; justify-content:flex-end; gap:12px; border:0; margin:2px 0 0; background:transparent; align-items:flex-start; }
            .detail-date-lines { display:flex; flex-direction:column; gap:0; padding:0; margin:0; }
            .issue-date-line, .delivery-date-line { display:flex; align-items:center; gap:.75em; padding:0; font-size:12px; color:#111827; line-height:1.4; white-space:nowrap; }
            .issue-date-line span, .delivery-date-line span { font-weight:700; }
            .issue-date-line strong, .delivery-date-line strong { font-size:12px; font-weight:400; color:#111827; }
            .summary-stamp-cell { width:250px; padding:0 !important; background:#fff; }
            .summary-stamp-cell .stamp-grid { height:auto; min-height:62px; border:1px solid #d1d5db; font-family:"BIZ UDPGothic", "Yu Gothic", "YuGothic", "Meiryo", sans-serif; }
            .summary-stamp-cell .stamp-grid span { padding:3px 0; color:#111827; font-size:12px; font-weight:800; }
            .unit-label { text-align:right; font-size:11px; margin-bottom:1px; color:#374151; font-weight:700; }
            table { border-collapse:collapse; width:100%; }
            th { font-weight:bold; text-align:center; }
            .request-list-table thead th { background:#f3f4f6; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .request-list-table th { border:1px solid #d1d5db !important; color:#111827; }
            .request-list-table td { border:1px solid #d1d5db; padding:2px 4px; font-size:11px; line-height:1.25; }
            .jan-cell { white-space:nowrap; font-family:Consolas, "Courier New", monospace; }
            .product-cell { min-width:210px; }
            .center-cell { text-align:center; white-space:nowrap; }
            .qty-cell { text-align:right; white-space:nowrap; min-width:28px; }
            .total-cell { text-align:right; white-space:nowrap; font-weight:800; background:#f9fafb; }
            .note-box { margin-top:10px; padding:8px 10px; border:1px solid #d6b35a; border-radius:3px; font-size:12px; background:#fff8dc; }
            .note-box span { font-weight:800; }
            .footer-area { margin-top:12px; }
            .comment-box { border:1px solid #cbd5e1; background:#fff; min-height:50px; }
            .box-label { background:#f3f4f6; border-bottom:1px solid #cbd5e1; padding:4px 7px; font-size:11px; font-weight:800; }
            .box-body { padding:7px; min-height:34px; font-size:12px; }
            .stamp-grid { display:grid; grid-template-columns:repeat(3,1fr); border:1px solid #d1d5db; min-height:56px; text-align:center; font-size:11px; color:#374151; }
            .stamp-grid div { border-left:1px solid #d1d5db; }
            .stamp-grid div:first-child { border-left:0; }
            .stamp-grid span { display:block; padding:4px 0; border-bottom:1px solid #d1d5db; background:#f3f4f6; font-weight:700; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .btn-container { position:fixed; top:20px; right:20px; display:flex; gap:8px; z-index:1000; }
            .print-button, .download-button { color:white; border:none; padding:12px 24px; font-size:16px; font-weight:bold; border-radius:8px; cursor:pointer; }
            .print-button { background:#2563eb; }
            .download-button { background:#059669; }
            @media print { body { background:white; padding:0; overflow:visible; } .btn-container { display:none !important; } .page-break { width:auto; min-height:auto; border:none; box-shadow:none; margin:0; padding:0; } .document-topline { margin:0 0 10px; } }
          </style></he${''}ad><bo${''}dy>
          <div class="btn-container"><button class="print-button" onclick="window.print()">印刷</button></div>
          ${pagesHtml}
        ${'</bo' + 'dy></ht' + 'ml>'}`);
        w.document.close();

        dataset.forEach(r => { r.printed = true; });
        this.saveDirectData();
        this.showToast('依頼書を出力しました');
      } catch(e) { this.openModal('エラー','依頼書出力でエラーが発生しました: ' + e.message); }
    },

    // ---------- 発注候補生成 ----------
    buildOrderCandidatePayload(dataset, allocTotalResolver, options = {}) {
      this.normalizeRowsDateFields(dataset, true);
      const rows = dataset.map(row => {
        const meta = this.getProductMeta(row.productCode || '');
        const allocTotal = toInt(allocTotalResolver(row));
        const allocations = DESTS.map(dest => {
          const quantity = toInt(row['alloc_' + dest.key] || 0);
          if (quantity <= 0) return null;

          return {
            destination_id: dest.id || null,
            destination_key: dest.key,
            destination_name: dest.name || '',
            quantity: quantity,
          };
        }).filter(allocation => allocation !== null);

        return {
          row_id: String(row.id || ''),
          item_id: row.itemId || meta.id || null,
          product_code: row.productCode || '',
          item_contractor_id: row.itemContractorId || meta.itemContractorId || null,
          contractor_id: row.contractorId || meta.contractorId || null,
          supplier_id: row.supplierId || meta.supplierId || null,
          order_to_code: row.orderToCode || meta.orderToCode || '',
          order_to: row.orderTo || meta.orderTo || '',
          supplier_code: row.supplierCode || meta.supplierCode || '',
          supplier: row.supplier || meta.supplier || '',
          order_date: row.orderDate || '',
          delivery_date: row.deliveryDate || '',
          alloc_total: allocTotal,
          po_case: toInt(row.poCase),
          po_each: toInt(row.poEach),
          units_per_case: toInt(row.unitsPerCase) || 1,
          purchase_unit: toInt(row.purchaseUnit) || 1,
          order_point: toInt(row.orderPoint),
          allocations: allocations,
        };
      }).filter(row => row.alloc_total > 0 || row.po_case > 0 || row.po_each > 0);

      return { mode: options.mode || 'allocation', rows: rows };
    },

    async createOrderCandidates(dataset, allocTotalResolver, options = {}) {
      const targetRows = this.getOrderCandidateGeneratableRows(dataset, allocTotalResolver);
      const payload = this.buildOrderCandidatePayload(targetRows, allocTotalResolver, options);

      if (payload.rows.length === 0) {
        throw new Error('分配数、または発注ケース・発注バラが入力されている確定行がありません。');
      }

      const response = await fetch('/api/distribution/order-candidates', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...this.csrfHeaders(),
        },
        body: JSON.stringify(payload),
      });
      const responsePayload = await response.json().catch(() => ({}));

      if (!response.ok) {
        const message = responsePayload?.result?.error_message
          || responsePayload?.message
          || ('HTTP ' + response.status);
        throw new Error(message);
      }

      return responsePayload?.result?.data || {};
    },

    async checkExistingOrderCandidates(dataset, allocTotalResolver, options = {}) {
      const targetRows = this.getOrderCandidateGeneratableRows(dataset, allocTotalResolver);
      const payload = this.buildOrderCandidatePayload(targetRows, allocTotalResolver, options);

      if (payload.rows.length === 0) {
        return { already_generated_count: 0, already_generated_row_ids: [], candidate_ids: [] };
      }

      const response = await fetch('/api/distribution/order-candidates', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...this.csrfHeaders(),
        },
        body: JSON.stringify({ ...payload, check_only: true }),
      });
      const responsePayload = await response.json().catch(() => ({}));

      if (!response.ok) {
        const message = responsePayload?.result?.error_message
          || responsePayload?.message
          || ('HTTP ' + response.status);
        throw new Error(message);
      }

      return responsePayload?.result?.data || {};
    },

    getAlreadyGeneratedRowIdSet(result) {
      return new Set(
        (Array.isArray(result?.already_generated_row_ids) ? result.already_generated_row_ids : [])
          .map(rowId => String(rowId))
      );
    },

    getOrderCandidateToastMessage(result, generatedFallbackCount) {
      const createdCount = toInt(result.created_count || 0);
      const updatedCount = toInt(result.updated_count || 0);
      const alreadyGeneratedCount = toInt(result.already_generated_count || 0);
      const changedCount = createdCount + updatedCount;
      const ignoredCount = alreadyGeneratedCount;
      const suffix = ignoredCount > 0 ? ' / 生成済み除外' + ignoredCount + '件' : '';

      if (changedCount > 0 && alreadyGeneratedCount > 0) {
        return '発注候補を生成しました（新規/更新' + changedCount + '件 / 作成済み' + alreadyGeneratedCount + '件）';
      }
      if (changedCount > 0) {
        return changedCount + '件の発注候補を生成しました' + suffix;
      }
      if (alreadyGeneratedCount > 0) {
        return '作成済みのため、発注候補は重複生成しませんでした。';
      }

      return (result.candidate_count || generatedFallbackCount) + '件の発注候補を生成しました' + suffix;
    },

    isOrderCandidateDuplicateOnlyResult(result) {
      const createdCount = toInt(result.created_count || 0);
      const updatedCount = toInt(result.updated_count || 0);
      const alreadyGeneratedCount = toInt(result.already_generated_count || 0);

      return createdCount + updatedCount === 0 && alreadyGeneratedCount > 0;
    },

    prepareOrderCandidateRows(rows, allocTotalResolver) {
      let targetCount = 0;

      rows.forEach(row => {
        const allocTotal = toInt(allocTotalResolver(row));
        const hasOrderQuantity = toInt(row.poCase) > 0 || toInt(row.poEach) > 0;

        if (allocTotal <= 0 && !hasOrderQuantity) return;

        if (allocTotal > 0 && !hasOrderQuantity) {
          row.poCase = '';
          row.poEach = allocTotal;
        }

        targetCount++;
      });

      return targetCount;
    },

    countOrderCandidateTargetRows(rows, allocTotalResolver) {
      return rows.reduce((count, row) => {
        const allocTotal = toInt(allocTotalResolver(row));
        const hasOrderQuantity = toInt(row.poCase) > 0 || toInt(row.poEach) > 0;

        return count + ((allocTotal > 0 || hasOrderQuantity) ? 1 : 0);
      }, 0);
    },

    countDirectOrderCandidateTargetRows(rows) {
      return rows.reduce((count, row) => {
        const allocTotal = this.calcDirectAllocTotal(row);
        const hasOrderQuantity = toInt(row.poCase) > 0 || toInt(row.poEach) > 0;

        return count + ((allocTotal > 0 || hasOrderQuantity) ? 1 : 0);
      }, 0);
    },

    markRowsAsOrderCandidateGenerated(dataset, result, rowCollection = this.rows) {
      const markedRowIds = []
        .concat(Array.isArray(result?.generated_row_ids) ? result.generated_row_ids : [])
        .concat(Array.isArray(result?.already_generated_row_ids) ? result.already_generated_row_ids : []);
      const rowIds = new Set(
        markedRowIds.length > 0
          ? markedRowIds.map(id => String(id))
          : (Array.isArray(result?.row_ids) ? result.row_ids.map(id => String(id)) : [])
      );
      const candidateIds = Array.isArray(result?.candidate_ids)
        ? result.candidate_ids.filter(id => id !== undefined && id !== null)
        : [];
      const createdAt = new Date().toISOString();

      if (rowIds.size === 0) {
        return;
      }

      rowCollection.forEach(row => {
        if (!rowIds.has(String(row.id))) return;

        const existingCandidateIds = Array.isArray(row.orderCandidateIds)
          ? row.orderCandidateIds
          : [];
        row.checked = true;
        row.orderCandidateGenerated = true;
        row.locked = this.isRowLocked(row);
        row.orderCandidateCreatedAt = createdAt;
        row.orderCandidateIds = Array.from(new Set([...existingCandidateIds, ...candidateIds]));
      });
    },

        getOrderCandidateLockConfirmMessage() {
          return '発注候補生成で対象行の商品情報・発注数・分配数は修正できなくなります。\n発注候補を生成してもよろしいですか？';
        },

    async generateOrderCandidates(confirmed = false, dateRefreshConfirmed = false, existingGeneratedRowIds = []) {
      if (this.orderCandidateCreating) return;

      this.normalizeRowsDateFields(this.rows, true);
      const visibleRows = this.getVisibleRows();
      const ignoredGeneratedCount = this.getCheckedAlreadyGeneratedOrderCandidateRows(visibleRows).length;
      const rows = this.getOrderCandidateGeneratableRows(visibleRows, row => this.calcAllocTotal(row));
      if (rows.length === 0) {
        this.openModal(
          'エラー',
          ignoredGeneratedCount > 0
            ? '選択中のデータはすでに発注候補生成済みです。未生成のデータを選択してください。'
            : '確定済みの行がありません。チェックを入れてください。'
        );
        return;
      }

      const targetCount = this.countOrderCandidateTargetRows(rows, row => this.calcAllocTotal(row));

      if (targetCount === 0) {
        this.openModal('注意', '分配数、または発注ケース・発注バラが入力されている確定行がありません。');
        return;
      }

      let existingGeneratedRowIdSet = new Set(existingGeneratedRowIds.map(rowId => String(rowId)));

      if (!dateRefreshConfirmed && this.hasOrderCandidatePastDateRows(rows)) {
        try {
          const existingResult = await this.checkExistingOrderCandidates(rows, row => this.calcAllocTotal(row));
          existingGeneratedRowIdSet = this.getAlreadyGeneratedRowIdSet(existingResult);
        } catch (e) {
          console.error(e);
          this.openModal('エラー', '発注候補生成済み判定に失敗しました: ' + e.message);
          return;
        }

        const dateRefreshRows = rows.filter(row => !existingGeneratedRowIdSet.has(String(row.id)));

        if (this.hasOrderCandidatePastDateRows(dateRefreshRows)) {
          this.openConfirm(
            this.getOrderCandidateDateRefreshConfirmMessage(),
            () => this.generateOrderCandidates(true, true, Array.from(existingGeneratedRowIdSet))
          );
          return;
        }
      }

      if (!confirmed) {
        this.openConfirm(this.getOrderCandidateLockConfirmMessage(), () => this.generateOrderCandidates(true, dateRefreshConfirmed, Array.from(existingGeneratedRowIdSet)));
        return;
      }

      this.orderCandidateCreating = true;
      try {
        const dateRefreshRows = rows.filter(row => !existingGeneratedRowIdSet.has(String(row.id)));
        const refreshedDateCount = await this.refreshOrderCandidateRowsDatesForToday(dateRefreshRows);
        if (refreshedDateCount > 0) {
          this.saveData();
        }
        const result = await this.createOrderCandidates(rows, row => this.calcAllocTotal(row));
        this.markRowsAsOrderCandidateGenerated(rows, result, this.rows);
        this.saveData();
        this.openResultModal(
          '発注候補生成',
          this.getOrderCandidateToastMessage(result, targetCount, ignoredGeneratedCount),
          this.isOrderCandidateDuplicateOnlyResult(result) ? 'danger' : 'success'
        );
      } catch (e) {
        console.error(e);
        this.openModal('エラー', '発注候補生成に失敗しました: ' + e.message);
      } finally {
        this.orderCandidateCreating = false;
      }
    },

    async generateDirectOrderCandidates(confirmed = false, dateRefreshConfirmed = false, existingGeneratedRowIds = []) {
      if (this.orderCandidateCreating) return;

      this.normalizeRowsDateFields(this.directRows, true);
      const visibleRows = this.getVisibleDirectRows();
      const ignoredGeneratedCount = this.getCheckedAlreadyGeneratedOrderCandidateRows(visibleRows).length;
      const rows = this.getDirectOrderCandidateGeneratableRows(visibleRows);
      if (rows.length === 0) {
        this.openModal(
          'エラー',
          ignoredGeneratedCount > 0
            ? '選択中のデータはすでに発注候補生成済みです。未生成のデータを選択してください。'
            : '確定済みの行がありません。チェックを入れてください。'
        );
        return;
      }

      const targetCount = this.countDirectOrderCandidateTargetRows(rows);

      if (targetCount === 0) {
        this.openModal('注意', '分配数、または発注ケース・発注バラが入力されている確定行がありません。');
        return;
      }

      let existingGeneratedRowIdSet = new Set(existingGeneratedRowIds.map(rowId => String(rowId)));

      if (!dateRefreshConfirmed && this.hasOrderCandidatePastDateRows(rows)) {
        try {
          const existingResult = await this.checkExistingOrderCandidates(rows, row => this.calcDirectAllocTotal(row), { mode: 'direct' });
          existingGeneratedRowIdSet = this.getAlreadyGeneratedRowIdSet(existingResult);
        } catch (e) {
          console.error(e);
          this.openModal('エラー', '発注候補生成済み判定に失敗しました: ' + e.message);
          return;
        }

        const dateRefreshRows = rows.filter(row => !existingGeneratedRowIdSet.has(String(row.id)));

        if (this.hasOrderCandidatePastDateRows(dateRefreshRows)) {
          this.openConfirm(
            this.getOrderCandidateDateRefreshConfirmMessage(),
            () => this.generateDirectOrderCandidates(true, true, Array.from(existingGeneratedRowIdSet))
          );
          return;
        }
      }

      if (!confirmed) {
        this.openConfirm(this.getOrderCandidateLockConfirmMessage(), () => this.generateDirectOrderCandidates(true, dateRefreshConfirmed, Array.from(existingGeneratedRowIdSet)));
        return;
      }

      this.orderCandidateCreating = true;
      try {
        const dateRefreshRows = rows.filter(row => !existingGeneratedRowIdSet.has(String(row.id)));
        const refreshedDateCount = await this.refreshOrderCandidateRowsDatesForToday(dateRefreshRows);
        if (refreshedDateCount > 0) {
          this.saveDirectData();
        }
        const result = await this.createOrderCandidates(rows, row => this.calcDirectAllocTotal(row), { mode: 'direct' });
        this.markRowsAsOrderCandidateGenerated(rows, result, this.directRows);
        this.saveDirectData();
        this.openResultModal(
          '発注候補生成',
          this.getOrderCandidateToastMessage(result, targetCount, ignoredGeneratedCount),
          this.isOrderCandidateDuplicateOnlyResult(result) ? 'danger' : 'success'
        );
      } catch (e) {
        console.error(e);
        this.openModal('エラー', '発注候補生成に失敗しました: ' + e.message);
      } finally {
        this.orderCandidateCreating = false;
      }
    },

    // ---------- CSV Export ----------
    downloadCSV() {
      try {
        this.normalizeDateFilters();
        this.normalizeRowsDateFields(this.rows, true);
        const dataset = this.getActiveRows();
        if (dataset.length === 0) {
          // Fall back to all visible rows if none checked
          const visible = this.getVisibleRows();
          if (visible.length === 0) {
            this.openModal('エラー', '出力するデータがありません。');
            return;
          }
          this._exportCSVRows(visible);
        } else {
          this._exportCSVRows(dataset);
        }
      } catch (e) {
        console.error(e);
        this.openModal('エラー', 'CSV出力でエラーが発生しました');
      }
    },

    _exportCSVRows(dataRows) {
      const headers = [
        '計上日', '発注日', '商品コード', '商品名', '仕入先', '納品希望日', 'ロット', '入数', '理論', '希計', '分計',
        '発注ケース', '発注バラ', '明細備考',
        ...DESTS.flatMap(d => [d.name + '_希望', d.name + '_分配']),
      ];

      const body = dataRows.map(r => {
        const vals = [
          this.getCsvImportPostingDate(r) || r.orderDate || '',
          r.orderDate || '', r.productCode, r.name,
          this.getSupplier(r.productCode), r.deliveryDate || '',
          toInt(r.lot), toInt(r.unitsPerCase), toInt(r.reserved),
          this.calcWishTotal(r),
          this.calcAllocTotal(r),
          toInt(r.poCase), toInt(r.poEach), r.memo || '',
          ...DESTS.flatMap(d => [toInt(r['wish_' + d.key] || 0), toInt(r['alloc_' + d.key] || 0)]),
        ];
        return vals.map(x => '"' + String(x ?? '').replace(/"/g, '""') + '"').join(',');
      });

      const csv = [headers.join(','), ...body].join('\r\n');
      const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'allocation_' + getTodayString() + '.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      this.showToast('CSVを出力しました（' + dataRows.length + '行）');
    },

    // ---------- Print Slips (移動伝票) ----------
    buildStockTransferSlipPayload(dataset, remark, destsList = DESTS, options = {}) {
      this.normalizeRowsDateFields(dataset, true);
      const includeZeroWishAllocations = !!options.includeZeroWishAllocations;
      const includeZeroAllocations = !!options.includeZeroAllocations;
      const processDate = this.normalizeFlexibleDate(options.processDate || '') || '';
      const rows = dataset.map(row => {
        const allocations = destsList.map(dest => {
          const quantity = toInt(row['alloc_' + dest.key] || 0);
          const wishQuantity = toInt(row['wish_' + dest.key] || 0);
          if (
            quantity <= 0 &&
            !includeZeroAllocations &&
            !(includeZeroWishAllocations && wishQuantity > 0)
          ) return null;
          if (!dest.id) {
            throw new Error('店舗マスタの取得が完了していません。少し待ってから再実行してください。');
          }

          return {
            destination_id: dest.id,
            destination_key: dest.key,
            quantity: quantity,
          };
        }).filter(allocation => allocation !== null);

        return {
          row_id: String(row.id || ''),
          item_id: row.itemId || null,
          product_code: row.productCode || '',
          order_date: processDate || row.orderDate || '',
          delivery_date: processDate || row.deliveryDate || '',
          delivery_course_id: toInt(row.deliveryCourseId || row.delivery_course_id || 0) || null,
          memo: row.memo || '',
          allocations: allocations,
        };
      }).filter(row => row.allocations.length > 0);

      return {
        remark: remark || '',
        process_date: processDate || undefined,
        rows: rows,
      };
    },

    csrfHeaders() {
      const token = getCookie('XSRF-TOKEN');
      return token ? { 'X-XSRF-TOKEN': token } : {};
    },

    async createStockTransferSlips(dataset, remark, destsList = DESTS) {
      const payload = this.buildStockTransferSlipPayload(dataset, remark, destsList);

      if (payload.rows.length === 0) {
        throw new Error('伝票出力できる確定行がありません。');
      }

      const response = await fetch('/api/distribution/stock-transfer-slips', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...this.csrfHeaders(),
        },
        body: JSON.stringify(payload),
      });
      const responsePayload = await response.json().catch(() => ({}));

      if (!response.ok) {
        const message = responsePayload?.result?.error_message
          || responsePayload?.message
          || ('HTTP ' + response.status);
        throw new Error(message);
      }

      return responsePayload?.result?.data || {};
    },

    async createWarehouseTransfers(dataset, destsList = DESTS) {
      const processDate = this.normalizeFlexibleDate(this.bulkInputDate || '') || getTodayString();
      this.bulkInputDate = processDate;
      const payload = this.buildStockTransferSlipPayload(dataset, '倉庫移動生成', destsList, { processDate });

      if (payload.rows.length === 0) {
        throw new Error('倉庫移動生成できる分配数が入力された確定行がありません。');
      }

      const response = await fetch('/api/distribution/warehouse-transfers', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...this.csrfHeaders(),
        },
        body: JSON.stringify(payload),
      });
      const responsePayload = await response.json().catch(() => ({}));

      if (!response.ok) {
        const message = responsePayload?.result?.error_message
          || responsePayload?.message
          || ('HTTP ' + response.status);
        throw new Error(message);
      }

      return responsePayload?.result?.data || {};
    },

    markRowsAsTransferSlipCreated(dataset, result) {
      const rowIds = new Set(
        Array.isArray(result?.row_ids) && result.row_ids.length > 0
          ? result.row_ids.map(id => String(id))
          : dataset.map(row => String(row.sourceRowId || row.id))
      );
      const queueIds = Array.isArray(result?.queues)
        ? result.queues.map(queue => queue.queue_id).filter(id => id !== undefined && id !== null)
        : [];
      const createdAt = new Date().toISOString();

      this.rows.forEach(row => {
        if (!rowIds.has(String(row.id))) return;

        const existingQueueIds = Array.isArray(row.transferSlipQueueIds)
          ? row.transferSlipQueueIds
          : [];
        row.checked = true;
        row.printed = true;
        row.locked = this.isRowLocked(row);
        row.transferSlipCreatedAt = createdAt;
        row.transferSlipQueueIds = Array.from(new Set([...existingQueueIds, ...queueIds]));
      });
    },

    markRowsAsWarehouseTransferCreated(dataset, result) {
      const rowIds = new Set(
        Array.isArray(result?.row_ids) && result.row_ids.length > 0
          ? result.row_ids.map(id => String(id))
          : dataset.map(row => String(row.id))
      );
      const queueIds = Array.isArray(result?.queues)
        ? result.queues.map(queue => queue.queue_id).filter(id => id !== undefined && id !== null)
        : [];
      const createdAt = new Date().toISOString();

      this.rows.forEach(row => {
        if (!rowIds.has(String(row.id))) return;

        const existingQueueIds = Array.isArray(row.warehouseTransferQueueIds)
          ? row.warehouseTransferQueueIds
          : [];
        row.checked = true;
        row.warehouseTransferGenerated = true;
        row.warehouseTransferCreatedAt = createdAt;
        row.warehouseTransferQueueIds = Array.from(new Set([...existingQueueIds, ...queueIds]));
        row.locked = true;
      });
    },

    async printSlips(opts, rows = null, destsList = DESTS, options = {}) {
      if (this.transferSlipCreating) return;

      try {
        const remark = opts?.remark || '';
        this.normalizeRowsDateFields(this.rows, true);
        const dataset = Array.isArray(rows) ? rows : this.getPrintableRows();
        if (dataset.length === 0) {
          this.openModal('エラー', '出力するデータがありません。チェックを入れてください。');
          return;
        }
        this.transferSlipCreating = true;
        this._printSlipsForDests(destsList, dataset, remark, options);
        this.markRowsAsTransferSlipCreated(dataset, {});
        this.reprintSelectedRowIds = [];
        this.reprintSelectedStoreKeys = [];
        this.saveData();
        this.transferSlipCreating = false;
        this.showToast('伝票を再出力しました');
      } catch (e) {
        this.transferSlipCreating = false;
        console.error('伝票出力エラー:', e);
        this.openModal('エラー', '伝票出力でエラーが発生しました: ' + e.message);
      }
    },

    async generateWarehouseTransfers() {
      if (this.warehouseTransferCreating || this.transferSlipCreating) return;

      try {
        this.normalizeRowsDateFields(this.rows, true);
        const sourceRows = this.getWarehouseTransferSourceRows();
        const transferRows = this.getWarehouseTransferCreatableRows(this.getWarehouseTransferPendingRows(sourceRows));
        const slipOnlyRows = this.getWarehouseTransferSlipPendingRows(sourceRows);
        const dataset = [...transferRows, ...slipOnlyRows];
        if (dataset.length === 0) {
          this.openModal('エラー', '倉庫移動・伝票待ちのデータがありません。確定済み、または発注候補生成済みの行を確認してください。');
          return;
        }

        this.warehouseTransferCreating = true;
        let result = {};

        if (transferRows.length > 0) {
          result = await this.createWarehouseTransfers(transferRows);
          this.markRowsAsWarehouseTransferCreated(transferRows, result);
          this.saveData();
        }

        const createdQueueCount = toInt(result?.created_queue_count ?? result?.queue_count ?? 0);
        const skippedQueueCount = toInt(result?.skipped_queue_count || 0);
        try {
          this._printSlipsForDests(DESTS, dataset, '', { includeZeroAllocRows: false, processDate: this.bulkInputDate });
          if (transferRows.length > 0) {
            this.markRowsAsTransferSlipCreated(transferRows, result);
          }
          if (slipOnlyRows.length > 0) {
            this.markRowsAsTransferSlipCreated(slipOnlyRows, {});
          }
          this.saveData();
        } catch (printError) {
          console.error('伝票出力エラー:', printError);
          this.openModal('エラー', '倉庫移動は生成されましたが、伝票出力でエラーが発生しました。店舗別管理の伝票出力から再出力してください: ' + printError.message);
          return;
        }

        if (transferRows.length === 0 && slipOnlyRows.length > 0) {
          this.openResultModal('倉庫移動生成', '倉庫移動生成済みの伝票を出力しました（' + slipOnlyRows.length + '件）');
        } else if (createdQueueCount > 0 && skippedQueueCount > 0) {
          this.openResultModal('倉庫移動生成', '倉庫移動を生成し、伝票を出力しました（新規' + createdQueueCount + '件 / 作成済み' + skippedQueueCount + '件）');
        } else if (createdQueueCount > 0) {
          this.openResultModal('倉庫移動生成', '倉庫移動を生成し、伝票を出力しました（' + createdQueueCount + '件）');
        } else if (skippedQueueCount > 0) {
          this.openResultModal('倉庫移動生成', '作成済みのため、倉庫移動は重複作成せず伝票を再出力しました');
        } else {
          this.openResultModal('倉庫移動生成', '倉庫移動を生成し、伝票を出力しました');
        }
      } catch (e) {
        console.error('倉庫移動生成エラー:', e);
        this.openModal('エラー', '倉庫移動生成でエラーが発生しました: ' + e.message);
      } finally {
        this.warehouseTransferCreating = false;
      }
    },

    async printSlipsForSelectedStores(opts) {
      if (this.transferSlipCreating) return;

      try {
        const remark = opts?.remark || '';
        if (this.selectedStores.length === 0) {
          this.openModal('エラー', '伝票を出力する店舗を選択してください。');
          return;
        }
        this.normalizeDateFilters();
        this.normalizeRowsDateFields(this.rows, true);
        const dataset = this.getPrintableRowsForSelectedStores();
        if (dataset.length === 0) {
          this.openModal('エラー', '選択店舗に希望または分配が入力されている行がありません。');
          return;
        }
        const selectedDestsList = this.getPrintableDestsForSelectedStores();
        if (selectedDestsList.length === 0) {
          this.openModal('エラー', '伝票を出力する店舗を選択してください。');
          return;
        }
        this.transferSlipCreating = true;
        this._printSlipsForDests(selectedDestsList, dataset, remark, { includeZeroAllocRows: true });
        this.markRowsAsTransferSlipCreated(dataset, {});
        this.saveData();
        this.transferSlipCreating = false;
        this.showToast('選択店舗の伝票を出力しました（' + selectedDestsList.length + '店舗）');
      } catch (e) {
        this.transferSlipCreating = false;
        console.error('伝票出力エラー:', e);
        this.openModal('エラー', '伝票出力でエラーが発生しました: ' + e.message);
      }
    },

    _printLegacySlipsForDests(destsList, dataset, remark, options = {}) {
      const orderDate = this.normalizeFlexibleDate(options.processDate || '') || dataset[0]?.orderDate || this.bulkInputDate || getTodayString();
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      const issueDateTime = now.getFullYear() + '/' + pad(now.getMonth() + 1) + '/' + pad(now.getDate()) + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
      const includeZeroAllocRows = !!options.includeZeroAllocRows;
      const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

      const barcodeIds = [];
      const slipsHtml = destsList.map(dest => {
        const destData = dataset.filter(r => {
          const allocQty = toInt(r['alloc_' + dest.key] || 0);
          if (allocQty > 0) return true;

          return includeZeroAllocRows;
        });
        if (destData.length === 0) return '';

        const slipBaseNumber = new Date().getTime().toString().slice(-8);
        const slipNumber = slipBaseNumber + String(dest.key || '');
        const slipDisplayNumber = slipBaseNumber + '-' + dest.key;
        const sourceWarehouseLabel = HQ_WAREHOUSE_CODE + ' ' + HQ_WAREHOUSE_NAME;
        const destWarehouseLabel = (dest.code || dest.key || '') + ' ' + (dest.name || '');
        barcodeIds.push(slipNumber);

        let rowNumber = 1;
        const rowsHtml = destData.map(r => {
            const alloc = toInt(r['alloc_' + dest.key] || 0);
            const unitsPerCase = Math.max(1, toInt(r.unitsPerCase || 1));
            const allocCase = Math.floor(alloc / unitsPerCase);
            const allocEach = alloc % unitsPerCase;

            return '<tr class="legacy-detail-row">' +
              '<td class="center">' + (rowNumber++) + '</td>' +
              '<td>' + escapeHtml(r.productCode || '') + '</td>' +
              '<td>' + escapeHtml(r.name || '') + '</td>' +
              '<td class="center">' + unitsPerCase.toLocaleString('ja-JP') + '</td>' +
              '<td class="right">' + allocCase.toLocaleString('ja-JP') + '</td>' +
              '<td class="right">' + allocEach.toLocaleString('ja-JP') + '</td>' +
              '<td class="right">' + alloc.toLocaleString('ja-JP') + '</td>' +
              '<td>' + escapeHtml(r.memo || '') + '</td>' +
            '</tr>';
        }).join('');

        return '<section class="legacy-slip page-break">' +
          '<header class="legacy-header">' +
            '<div class="legacy-title">【移動伝票】</div>' +
            '<div class="legacy-header-content">' +
              '<div class="legacy-header-left">' +
                '<div>伝票日付: ' + escapeHtml(orderDate) + '</div>' +
                '<div>伝票番号: ' + escapeHtml(slipDisplayNumber) + '</div>' +
                '<div class="warehouse-section">' +
                  '<div class="warehouse-box">' +
                    '<div class="warehouse-label">出庫倉庫 (' + escapeHtml(sourceWarehouseLabel) + ')</div>' +
                    '<div class="warehouse-content">' +
                      '<div class="stamp-box"><div class="stamp-title">配送者印</div><div class="stamp-area"></div></div>' +
                      '<div class="stamp-box"><div class="stamp-title">入力者印</div><div class="stamp-area"></div></div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="warehouse-box">' +
                    '<div class="warehouse-label">入庫倉庫 (' + escapeHtml(destWarehouseLabel) + ')</div>' +
                    '<div class="warehouse-content">' +
                      '<div class="stamp-box"><div class="stamp-title">配送者印</div><div class="stamp-area"></div></div>' +
                      '<div class="stamp-box"><div class="stamp-title">入力者印</div><div class="stamp-area"></div></div>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
              '</div>' +
              '<div class="legacy-header-right">' +
                '<div class="legacy-issue-time">発行日時: ' + escapeHtml(issueDateTime) + '</div>' +
                '<div class="legacy-barcode-box"><svg id="barcode-' + escapeHtml(slipNumber) + '" class="legacy-barcode"></svg></div>' +
              '</div>' +
            '</div>' +
          '</header>' +
          '<table class="legacy-slip-table">' +
            '<thead><tr>' +
              '<th class="no-col">No</th>' +
              '<th class="code-col">商品CD</th>' +
              '<th class="name-col">商品名</th>' +
              '<th class="unit-col">入数</th>' +
              '<th class="qty-col">ケース</th>' +
              '<th class="qty-col">バラ</th>' +
              '<th class="qty-col">総バラ</th>' +
              '<th class="memo-col">明細備考</th>' +
            '</tr></thead>' +
            '<tbody>' + rowsHtml + '</tbody>' +
          '</table>' +
          (remark ? '<div class="legacy-note"><span>伝票備考：</span>' + escapeHtml(remark).replace(/\n/g, '<br>') + '</div>' : '') +
        '</section>';
      }).filter(html => html !== '').join('');

      if (!slipsHtml || slipsHtml.trim() === '') {
        this.openModal('エラー', includeZeroAllocRows ? '希望または分配が入力されている店舗がありません。' : '分配数が入力されている店舗がありません。');
        return;
      }

      const htmlContent = '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>移動伝票</title>' +
        '<style>' +
          '@page { size: A4 landscape; margin: 10mm; }' +
          '* { margin: 0; padding: 0; box-sizing: border-box; }' +
          'body { font-family: "BIZ UDPGothic", "MS PGothic", "Yu Gothic", "YuGothic", "Meiryo", sans-serif; font-size: 13px; background: linear-gradient(to bottom, #f5f5f5, #e8e8e8); padding: 20px; min-height: 100vh; color: #111827; }' +
          '.legacy-slip { page-break-after: always; page-break-inside: avoid; margin: 0 auto 50px auto; max-width: 1100px; background: white; padding: 20px; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 6px 16px rgba(0,0,0,0.15); }' +
          '.legacy-slip:last-child { page-break-after: auto; margin-bottom: 20px; }' +
          '.legacy-header { margin-bottom: 12px; }' +
          '.legacy-title { font-size: 14px; font-weight: bold; text-align: center; margin-bottom: 10px; border: 2px solid #000; padding: 6px; background: #f8f8f8; }' +
          '.legacy-header-content { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 12px; }' +
          '.legacy-header-left { flex: 1; min-width: 0; }' +
          '.legacy-header-right { width: 280px; text-align: right; flex-shrink: 0; }' +
          '.legacy-issue-time { font-size: 11px; color: #666; margin-bottom: 4px; }' +
          '.legacy-barcode-box { padding: 8px; background: white; display: inline-block; border: 1px solid #ddd; }' +
          '.legacy-barcode { width: 250px; height: 80px; }' +
          '.warehouse-section { display: flex; gap: 12px; margin-top: 12px; margin-bottom: 12px; max-width: 58%; }' +
          '.warehouse-box { flex: 1; border: 2px solid #444; padding: 8px; background: #fafafa; border-radius: 3px; min-width: 0; }' +
          '.warehouse-label { font-size: 12px; font-weight: bold; margin-bottom: 5px; padding-bottom: 4px; border-bottom: 2px solid #888; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }' +
          '.warehouse-content { padding-top: 4px; display: flex; gap: 6px; }' +
          '.stamp-box { flex: 1; border: 1px solid #888; padding: 0; font-size: 11px; background: #fff; border-radius: 2px; display: flex; flex-direction: column; }' +
          '.stamp-title { padding: 4px 6px; background: #e8e8e8; border-bottom: 1px solid #888; font-weight: bold; text-align: center; font-size: 10px; }' +
          '.stamp-area { flex: 1; min-height: 50px; padding: 6px; }' +
          '.legacy-slip-table { border-collapse: collapse; width: 100%; margin-top: 8px; background: white; table-layout: fixed; }' +
          '.legacy-slip-table th { background: linear-gradient(to bottom, #e8e8e8, #d8d8d8); padding: 6px 8px; border: 1px solid #999; font-size: 13px; font-weight: bold; text-align: center; color: #222; -webkit-print-color-adjust: exact; print-color-adjust: exact; }' +
          '.legacy-slip-table td { border: 1px solid #ccc; padding: 3px 5px; height: 20px; font-size: 12px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }' +
          '.legacy-slip-table .center { text-align: center; } .legacy-slip-table .right { text-align: right; }' +
          '.legacy-slip-table tbody tr:nth-child(even) { background: #f9f9f9; }' +
          '.no-col { width: 30px; } .code-col { width: 100px; } .name-col { width: 300px; } .unit-col { width: 50px; } .qty-col { width: 60px; } .memo-col { width: 200px; }' +
          '.legacy-note { margin-top: 10px; padding: 8px; border: 1px solid #999; border-radius: 4px; font-size: 12px; background: #fffbe6; } .legacy-note span { font-weight: bold; }' +
          '.print-button-container { position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; gap: 8px; } .print-button { color: white; border: none; padding: 12px 24px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 8px rgba(0,0,0,0.2); background: linear-gradient(to bottom, #2563eb, #1d4ed8); }' +
          '@media print { body { background: white; padding: 0; margin: 0; } .legacy-slip { margin: 0; padding: 0; border: none; box-shadow: none; border-radius: 0; max-width: none; } .print-button-container { display: none !important; } .legacy-slip-table th { background: #e0e0e0 !important; } }' +
        '</style>' +
      '</he' + 'ad><bo' + 'dy>' +
        '<div class="print-button-container"><button class="print-button" onclick="window.print()">印刷</button></div>' +
        slipsHtml +
        '<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>' +
        '<script>' +
          'window.onload = function() {' +
            'var barcodeIds = ' + JSON.stringify(barcodeIds) + ';' +
            'barcodeIds.forEach(function(id) {' +
              'var element = document.getElementById("barcode-" + id);' +
              'if (element && typeof JsBarcode !== "undefined") {' +
                'try { JsBarcode(element, id, { format: "CODE128", width: 2, height: 50, displayValue: true, fontSize: 12, margin: 5 }); } catch(e) { console.error(e); }' +
              '}' +
            '});' +
          '};' +
        '<\/script>' +
      '</bo' + 'dy></ht' + 'ml>';

      const printUrl = URL.createObjectURL(new Blob([htmlContent], { type: 'text/html;charset=utf-8' }));
      const w = window.open(printUrl, '_blank');
      if (!w) {
        URL.revokeObjectURL(printUrl);
        this.openModal('エラー', 'ポップアップがブロックされています。ブラウザの設定でポップアップを許可してください。');
        return;
      }

      setTimeout(() => URL.revokeObjectURL(printUrl), 60000);
    },

    _printSlipsForDests(destsList, dataset, remark, options = {}) {
      if (options?.layout === 'legacy') {
        this._printLegacySlipsForDests(destsList, dataset, remark, options);
        return;
      }

      const orderDate = this.normalizeFlexibleDate(options.processDate || '') || dataset[0]?.orderDate || this.bulkInputDate || getTodayString();
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      const issueDateTime = String(now.getFullYear()).slice(-2) + '/' + pad(now.getMonth() + 1) + '/' + pad(now.getDate()) + '(' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ')';
      const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
      const formatChecklistDate = value => {
        const raw = String(value || '').trim();
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (match) return match[1].slice(-2) + '/' + match[2] + '/' + match[3];

        return raw;
      };
      const numberText = value => {
        const n = toInt(value);

        return Number.isFinite(n) ? n.toLocaleString('ja-JP') : '';
      };

      const slipRowsPerPage = 8;
      const includeZeroAllocRows = !!options.includeZeroAllocRows;
      let printedPageNumber = 0;

      const slipsHtml = destsList.map(dest => {
        const destData = dataset.filter(r => {
          const allocQty = toInt(r['alloc_' + dest.key] || 0);
          if (allocQty > 0) return true;

          return includeZeroAllocRows;
        });
        if (destData.length === 0) return '';

        const slipBaseNumber = new Date().getTime().toString().slice(-8);
        const slipNumber = slipBaseNumber + dest.key;
        const slipDisplayNumber = slipBaseNumber + '-' + dest.key;
        const destPages = [];
        for (let i = 0; i < destData.length; i += slipRowsPerPage) {
          destPages.push(destData.slice(i, i + slipRowsPerPage));
        }

        const slipIdentity = slipNumber;
        const sourceWarehouseLabel = HQ_WAREHOUSE_CODE + ' ' + HQ_WAREHOUSE_NAME;
        const destinationWarehouseLabel = (dest.code || dest.key || '') + ' ' + (dest.name || '');
        const copies = [
          { type: 'in', title: '転送伝票（入庫用）' },
          { type: 'out', title: '転送伝票（出庫用）' },
        ];

        return copies.map(copy => destPages.map((pageRows, pageIndex) => {
          printedPageNumber++;
          const rowsHtml = pageRows.map(r => {
            const alloc = toInt(r['alloc_' + dest.key] || 0);
            const unitsPerCase = Math.max(1, toInt(r.unitsPerCase || 1));
            const meta = this.getProductMeta(r.productCode) || {};
            const jan = r.jan || meta.jan || '';
            const productName = r.name || meta.name || '';
            const spec = '0x' + unitsPerCase;

            return '<tbody class="slip-line">' +
              '<tr class="detail-main-row">' +
                '<td class="center">' + escapeHtml(r.productCode || '') + '</td>' +
                '<td class="center">' + escapeHtml(jan) + '</td>' +
                '<td class="center identification-cell">' + escapeHtml(slipIdentity) + '</td>' +
                '<td class="center slip-number-cell">' + escapeHtml(slipDisplayNumber) + '</td>' +
                '<td class="center">' + escapeHtml(spec) + '</td>' +
                '<td class="qty-large" rowspan="2">0</td>' +
                '<td class="qty-large" rowspan="2">' + numberText(alloc) + '</td>' +
                '<td class="confirm-cell" rowspan="2">&nbsp;</td>' +
              '</tr>' +
              '<tr class="item-name-row">' +
                '<td colspan="5">' + escapeHtml(productName) + '</td>' +
              '</tr>' +
            '</tbody>';
          }).join('');

          return '<section class="checklist-page page-break">' +
            '<header>' +
              '<div class="title-row">' +
                '<div class="document-title">' + escapeHtml(copy.title) + '</div>' +
                '<div class="created-at">' +
                  '<div>作成 ' + escapeHtml(issueDateTime) + '</div>' +
                  '<div class="page-number">' + printedPageNumber + '頁</div>' +
                '</div>' +
              '</div>' +
              '<div class="info-row">' +
                '<div class="info-left">' +
                  '<div>転送倉庫：' + escapeHtml(sourceWarehouseLabel) + '</div>' +
                  '<div>出荷日：' + escapeHtml(formatChecklistDate(orderDate)) + '</div>' +
                  '<div>入力担当：</div>' +
                '</div>' +
                '<div class="info-center">識別ID：' + escapeHtml(slipIdentity) + '</div>' +
                '<div class="info-right">' +
                  '<div>伝票番号：' + escapeHtml(slipDisplayNumber) + '</div>' +
                  '<div>転入倉庫：' + escapeHtml(destinationWarehouseLabel) + '</div>' +
                  '<div>転入予定日：' + escapeHtml(formatChecklistDate(orderDate)) + '</div>' +
                '</div>' +
              '</div>' +
            '</header>' +
            '<table class="transfer-checklist-table">' +
              '<colgroup>' +
                '<col class="col-product-code">' +
                '<col class="col-jan">' +
                '<col class="col-identification">' +
                '<col class="col-slip">' +
                '<col class="col-spec">' +
                '<col class="col-case">' +
                '<col class="col-piece">' +
                '<col class="col-confirm">' +
              '</colgroup>' +
              '<thead>' +
                '<tr>' +
                  '<th>商品コード</th>' +
                  '<th>JANコード</th>' +
                  '<th>識別ID</th>' +
                  '<th>伝票番号</th>' +
                  '<th>規格</th>' +
                  '<th rowspan="2">ケース</th>' +
                  '<th rowspan="2">バラ</th>' +
                  '<th rowspan="2">入庫確認</th>' +
                '</tr>' +
                '<tr>' +
                  '<th colspan="5">商品名</th>' +
                '</tr>' +
              '</thead>' +
              rowsHtml +
            '</table>' +
          '</section>';
        }).join('')).join('');
      }).filter(html => html !== '').join('');

      if (!slipsHtml || slipsHtml.trim() === '') {
        this.openModal('エラー', includeZeroAllocRows ? '希望または分配が入力されている店舗がありません。' : '分配数が入力されている店舗がありません。');
        return;
      }

      const htmlContent = '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title></title>' +
        '<style>' +
          '@page { size: A4 portrait; margin: 0; }' +
          '* { margin: 0; padding: 0; box-sizing: border-box; }' +
          'body { font-family: "BIZ UDPGothic", "Yu Gothic", "YuGothic", "Meiryo", sans-serif; font-size: 11.5px; line-height: 1.35; color: #000; background: #eceff3; padding: 20px; }' +
          '.checklist-page { width: 210mm; min-height: 297mm; margin: 0 auto 18px; background: #fff; padding: 12mm 11.8mm; box-shadow: 0 6px 18px rgba(15,23,42,.14); }' +
          '.page-break { page-break-after: always; page-break-inside: avoid; }' +
          '.page-break:last-child { page-break-after: auto; margin-bottom: 0; }' +
          '.title-row { position: relative; height: 13mm; }' +
          '.document-title { text-align: center; font-size: 20px; font-weight: 500; line-height: 1.1; padding-top: 1mm; }' +
          '.created-at { position: absolute; top: 0; right: 0; width: 38mm; text-align: right; font-size: 10.5px; line-height: 1.6; }' +
          '.page-number { padding-top: 1mm; }' +
          '.info-row { display: grid; grid-template-columns: minmax(0, 62mm) 42mm minmax(0, 1fr); column-gap: 4mm; margin-top: 3mm; min-height: 22mm; font-size: 12px; line-height: 1.5; }' +
          '.info-left, .info-right { min-width: 0; }' +
          '.info-left div, .info-right div { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }' +
          '.info-center { min-width: 0; padding-top: 1mm; font-size: 13px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }' +
          '.transfer-checklist-table { width: 100%; margin-top: 6mm; border-collapse: collapse; table-layout: fixed; border: 1px solid #000; border-bottom: 3px double #000; font-size: 10.5px; }' +
          '.transfer-checklist-table th, .transfer-checklist-table td { border: 1px solid #000; vertical-align: middle; overflow: visible; }' +
          '.transfer-checklist-table th { height: 6mm; padding: 0 0.8mm; background: #f2f2f2; font-weight: 400; text-align: center; white-space: nowrap; -webkit-print-color-adjust: exact; print-color-adjust: exact; }' +
          '.detail-main-row td { height: 7.5mm; padding: 0 1mm; font-size: 10.5px; white-space: nowrap; overflow: hidden; }' +
          '.item-name-row td { height: 11mm; padding: 0.8mm 2mm; font-size: 13.5px; font-weight: 500; line-height: 1.2; white-space: normal; overflow-wrap: anywhere; word-break: break-word; }' +
          '.center { text-align: center; }' +
          '.identification-cell { font-size: 14px; }' +
          '.qty-large { text-align: center; font-size: 16px; font-weight: 400; }' +
          '.confirm-cell { background: #fff; }' +
          '.col-product-code { width: 24mm; }' +
          '.slip-number-cell { font-size: 10px; }' +
          '.col-jan { width: 36mm; }' +
          '.col-identification { width: 28mm; }' +
          '.col-slip { width: 30mm; }' +
          '.col-spec { width: 18mm; }' +
          '.col-case { width: 16mm; }' +
          '.col-piece { width: 20mm; }' +
          '.col-confirm { width: 14mm; }' +
          '.print-button-container { position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; gap: 8px; }' +
          '.print-button { color: white; border: none; padding: 10px 18px; font-size: 14px; font-weight: 700; border-radius: 7px; cursor: pointer; box-shadow: 0 4px 8px rgba(0,0,0,0.2); background: linear-gradient(to bottom, #2563eb, #1d4ed8); }' +
          '@media print { html, body { width: auto; min-height: auto; background: #fff; padding: 0; margin: 0; } .checklist-page { width: 210mm; min-height: 297mm; margin: 0; box-shadow: none; } .print-button-container { display: none !important; } }' +
        '</style>' +
      '</he' + 'ad><bo' + 'dy>' +
        '<div class="print-button-container"><button class="print-button" onclick="window.print()">印刷</button></div>' +
        slipsHtml +
      '</bo' + 'dy></ht' + 'ml>';

      const printUrl = URL.createObjectURL(new Blob([htmlContent], { type: 'text/html;charset=utf-8' }));
      const w = window.open(printUrl, '_blank');
      if (!w) {
        URL.revokeObjectURL(printUrl);
        this.openModal('エラー', 'ポップアップがブロックされています。ブラウザの設定でポップアップを許可してください。');
        return;
      }

      setTimeout(() => URL.revokeObjectURL(printUrl), 60000);
    },

    // ---------- Store View helpers ----------
    getStoreName(storeKey) {
      const store = DESTS.find(s => s.key === storeKey);
      return store ? store.name : storeKey;
    },

    getStoreLabel(storeKey) {
      const store = DESTS.find(s => s.key === storeKey);
      if (!store) return storeKey;

      return '[' + store.key + '] ' + store.name;
    },

    toggleStore(storeKey) {
      const displayKeys = this.getStoreDisplayDestinationKeys();
      if (!this.isHqStoreViewWarehouse()) {
        this.selectedStores = displayKeys;
        return;
      }

      if (!displayKeys.includes(String(storeKey))) {
        return;
      }

      const idx = this.selectedStores.indexOf(storeKey);
      if (idx >= 0) {
        this.selectedStores.splice(idx, 1);
      } else {
        this.selectedStores.push(storeKey);
      }
    },

    toggleAllStores() {
      const displayKeys = this.getStoreDisplayDestinationKeys();
      if (!this.isHqStoreViewWarehouse()) {
        this.selectedStores = displayKeys;
        return;
      }

      if (this.selectedStores.length === displayKeys.length) {
        this.selectedStores = [];
      } else {
        this.selectedStores = displayKeys;
      }
    },

    getStoreProductCount(storeKey) {
      return this.getStoreFilteredProducts(storeKey).length;
    },

    getStoreFilteredProducts(storeKey) {
      this.getStoreFilteredRows();
      const cacheKey = this.storeFilteredRowsCacheKey + '::' + String(storeKey || '');
      if (Array.isArray(this.storeProductsCache?.[cacheKey])) {
        return this.storeProductsCache[cacheKey];
      }

      const products = [];
      const visibleRows = this.storeFilteredRowsCache;
      visibleRows.forEach(row => {
        const allocQty = toInt(row['alloc_' + storeKey] || 0);
        const wishQty = toInt(row['wish_' + storeKey] || 0);
        const rowKey = this.getStoreSlipRowKey(row);
        const sourceType = row.storeSourceType || 'allocation';
        const sourceLabel = this.getStoreDistributionTypeLabel(sourceType);
        products.push({
          rowId: rowKey,
          rowKey: rowKey,
          sourceType: sourceType,
          sourceLabel: sourceLabel,
          comparisonKey: '[' + sourceLabel + '] ' + (row.productCode || ''),
          orderDate: row.orderDate,
          deliveryDate: row.deliveryDate,
          productCode: row.productCode,
          name: row.name,
          unitsPerCase: toInt(row.unitsPerCase),
          wish: wishQty,
          alloc: allocQty,
          checked: row.checked,
        });
      });
      this.storeProductsCache = {
        ...this.storeProductsCache,
        [cacheKey]: products,
      };

      return products;
    },

    // ---------- Comparison View ----------
    getComparisonProductCodes() {
      const codes = new Set();
      this.selectedStores.forEach(storeKey => {
        this.getStoreFilteredProducts(storeKey).forEach(p => {
          codes.add(p.comparisonKey || p.productCode);
        });
      });
      return Array.from(codes);
    },

    getComparisonProductName(productCode) {
      for (const storeKey of this.selectedStores) {
        const products = this.getStoreFilteredProducts(storeKey);
        const p = products.find(x => (x.comparisonKey || x.productCode) === productCode);
        if (p) return p.name;
      }
      return '-';
    },

    getComparisonProduct(productCode) {
      for (const storeKey of this.selectedStores) {
        const products = this.getStoreFilteredProducts(storeKey);
        const p = products.find(x => (x.comparisonKey || x.productCode) === productCode);
        if (p) return p;
      }

      return null;
    },

    getComparisonProductSourceType(productCode) {
      return this.getComparisonProduct(productCode)?.sourceType || 'allocation';
    },

    getComparisonProductSourceLabel(productCode) {
      return this.getComparisonProduct(productCode)?.sourceLabel || this.getStoreDistributionTypeLabel('allocation');
    },

    getComparisonProductDisplayCode(productCode) {
      return this.getComparisonProduct(productCode)?.productCode || String(productCode || '').replace(/^\[[^\]]+\]\s*/, '');
    },

    getComparisonCell(productCode, storeKey) {
      const products = this.getStoreFilteredProducts(storeKey);
      return products.find(p => (p.comparisonKey || p.productCode) === productCode) || null;
    },

    getComparisonCellStateClass(productCode, storeKey) {
      const cell = this.getComparisonCell(productCode, storeKey);
      if (!cell) return '';
      if (cell.checked) return 'bg-emerald-50';
      if (toInt(cell.alloc || 0) <= 0) return 'bg-amber-50';

      return '';
    },

    isComparisonCellConfirmed(productCode, storeKey) {
      return !!this.getComparisonCell(productCode, storeKey)?.checked;
    },

    isComparisonProductConfirmed(productCode) {
      return this.selectedStores.some(storeKey => this.isComparisonCellConfirmed(productCode, storeKey));
    },
  };
};
</script>


    @endpush
    </div>
</x-filament-panels::page></div>
