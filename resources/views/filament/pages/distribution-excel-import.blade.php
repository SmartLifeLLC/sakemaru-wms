<style>
    .distribution-form-dialog .distribution-form-label { display:block; color:#334155; font-size:13px; font-weight:600; }
    .distribution-form-dialog .distribution-required { margin-left:4px; color:#dc2626; font-size:11px; font-weight:500; }
    .distribution-form-dialog .distribution-form-input { display:block; width:100%; margin-top:6px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; padding:9px 12px; color:#0f172a; font-size:14px; font-weight:400; }
    .distribution-form-dialog .distribution-form-input:focus { border-color:#2563eb; outline:2px solid #bfdbfe; outline-offset:1px; }
    .distribution-form-dialog .distribution-form-input:disabled { background:#f1f5f9; cursor:wait; }
    .distribution-form-dialog textarea.distribution-form-input { min-height:90px; resize:vertical; }
    .distribution-form-dialog .distribution-import-panel { min-width:0; border:1px solid #dbe3ee; border-radius:8px; background:#f8fafc; padding:14px; }
    .distribution-form-dialog .distribution-import-file { display:block; width:100%; margin-top:8px; border:1px solid #cbd5e1; border-radius:6px; background:white; color:#475569; font-size:13px; }
    .distribution-form-dialog .distribution-import-file::file-selector-button { margin-right:12px; border:0; border-right:1px solid #2563eb; background:#2563eb; padding:10px 14px; color:white; font-weight:600; cursor:pointer; }
    .distribution-form-dialog .distribution-import-file:focus-visible { outline:2px solid #2563eb; outline-offset:2px; }
    .distribution-form-dialog .distribution-import-file:disabled::file-selector-button { cursor:wait; opacity:.5; }
    .distribution-form-dialog .distribution-import-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; margin-top:12px; }
    .distribution-form-dialog .distribution-option-group { min-width:0; }
    .distribution-form-dialog .distribution-option-group legend { margin-bottom:8px; color:#334155; font-size:13px; font-weight:600; }
    .distribution-form-dialog .distribution-option-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .distribution-form-dialog .distribution-option { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:8px; min-height:42px; border:1px solid #cbd5e1; border-radius:6px; background:white; padding:10px 12px; cursor:pointer; }
    .distribution-form-dialog .distribution-option:hover { border-color:#93c5fd; }
    .distribution-form-dialog .distribution-option:has(input:checked) { border-color:#2563eb; background:#eff6ff; box-shadow:0 0 0 1px #2563eb; }
    .distribution-form-dialog .distribution-option:has(input:focus-visible) { outline:2px solid #2563eb; outline-offset:3px; }
    .distribution-form-dialog .distribution-option:has(input:disabled) { opacity:.6; cursor:wait; }
    .distribution-form-dialog .distribution-option input { width:16px; height:16px; margin:2px 0 0; accent-color:#2563eb; }
    .distribution-form-dialog .distribution-option-title { color:#0f172a; font-size:14px; font-weight:600; }
    .distribution-form-dialog .distribution-import-preview-action { display:flex; justify-content:flex-end; margin-top:12px; }
    .distribution-form-dialog .distribution-mapping-select { width:100%; min-width:0; border:1px solid #cbd5e1; border-radius:5px; background:white; padding:5px 8px; color:#0f172a; font-size:12px; }
    .distribution-excel-screen { display:flex; flex-direction:column; width:100%; height:100%; min-height:0; overflow:hidden; }
    .distribution-excel-screen .distribution-excel-header { flex-shrink:0; background:#1e293b; padding:12px 16px; color:white; font-size:14px; font-weight:600; }
    .distribution-excel-screen .distribution-excel-settings { display:grid; grid-template-columns:minmax(260px,1.5fr) minmax(180px,1fr) minmax(220px,1fr) auto; align-items:end; gap:16px; flex-shrink:0; border-bottom:1px solid #e2e8f0; padding:12px 16px; }
    .distribution-excel-screen .distribution-option-group legend { margin-bottom:6px; }
    .distribution-excel-screen .distribution-option-grid { gap:8px; }
    .distribution-excel-screen .distribution-option { min-height:38px; padding:8px 10px; }
    .distribution-excel-screen .distribution-import-file { margin-top:6px; }
    .distribution-excel-screen .distribution-excel-content { display:flex; flex:1; flex-direction:column; gap:10px; min-height:0; overflow:hidden; padding:12px 16px; font-size:13px; }
    .distribution-excel-screen .distribution-excel-summary { display:flex; flex-wrap:wrap; align-items:center; gap:8px 20px; flex-shrink:0; }
    .distribution-excel-screen .distribution-excel-messages { flex-shrink:0; max-height:140px; overflow:auto; }
    .distribution-excel-screen .distribution-excel-matrix { flex:1; min-height:120px; overflow:auto; border:1px solid #cbd5e1; border-radius:6px; }
    .distribution-excel-screen .distribution-excel-matrix table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; table-layout:fixed; }
    .distribution-excel-screen .distribution-excel-matrix th,.distribution-excel-screen .distribution-excel-matrix td { border-right:1px solid #cbd5e1; border-bottom:1px solid #e2e8f0; padding:7px 8px; text-align:right; vertical-align:middle; }
    .distribution-excel-screen .distribution-excel-matrix th { position:sticky; top:0; z-index:2; background:#f1f5f9; text-align:left; }
    .distribution-excel-screen .distribution-excel-matrix th:first-child { left:0; z-index:3; width:260px; }
    .distribution-excel-screen .distribution-excel-matrix td:first-child { position:sticky; left:0; z-index:1; font-weight:600; text-align:left; }
    .distribution-excel-screen .distribution-excel-matrix tbody tr:nth-child(odd) td { background:#fff; }
    .distribution-excel-screen .distribution-excel-matrix tbody tr:nth-child(even) td { background:#f5f9ff; }
    .distribution-excel-screen .distribution-excel-matrix td.distribution-quantity-changed { background:#eff6ff; color:#1d4ed8; font-weight:600; }
    .distribution-excel-screen .distribution-column-source { display:block; margin-bottom:5px; color:#64748b; font-size:11px; font-weight:400; overflow-wrap:anywhere; }
    .distribution-excel-screen .distribution-product-code { display:block; }
    .distribution-excel-screen .distribution-product-name { display:block; margin-top:3px; color:#475569; font-size:11px; font-weight:400; line-height:1.45; white-space:normal; overflow-wrap:anywhere; }
    .distribution-excel-screen .distribution-store-mapping { position:relative; }
    .distribution-excel-screen .distribution-store-code { display:flex; align-items:center; justify-content:center; gap:4px; width:100%; min-height:30px; border:1px solid #cbd5e1; border-radius:5px; background:white; padding:3px; color:#334155; font-size:13px; font-weight:600; cursor:pointer; }
    .distribution-excel-screen .distribution-store-code:hover { border-color:#2563eb; color:#1d4ed8; }
    .distribution-excel-screen .distribution-store-code:disabled { cursor:wait; opacity:.5; }
    .distribution-excel-screen .distribution-store-code-arrow { color:#64748b; font-size:9px; }
    .distribution-excel-screen .distribution-store-editor { position:absolute; right:0; top:calc(100% + 6px); z-index:5; width:230px; border:1px solid #94a3b8; border-radius:6px; background:white; padding:12px; box-shadow:0 5px 15px #0f172a26; }
    .distribution-excel-screen .distribution-store-editor label { display:block; color:#334155; font-size:12px; font-weight:600; }
    .distribution-excel-screen .distribution-store-editor .distribution-mapping-select { margin-top:8px; }
    .distribution-excel-screen .distribution-store-editor-close { display:block; margin:8px 0 0 auto; color:#475569; font-size:12px; }
    .dark .distribution-excel-screen .distribution-product-name { color:#cbd5e1; }
    .dark .distribution-excel-screen .distribution-store-code,.dark .distribution-excel-screen .distribution-store-editor { background:#1e293b; border-color:#64748b; color:#e2e8f0; }
    .dark .distribution-excel-screen .distribution-store-editor label,.dark .distribution-excel-screen .distribution-store-editor-close { color:#e2e8f0; }
    .distribution-excel-screen .distribution-quantity-before { display:block; margin-top:2px; color:#64748b; font-size:10px; font-weight:400; }
    .distribution-excel-screen .distribution-excel-footer { display:flex; flex-shrink:0; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:10px; border-top:1px solid #cbd5e1; padding:12px 16px; }
    .distribution-excel-screen .distribution-excel-ack { margin-right:auto; font-size:13px; }
    .distribution-excel-screen .distribution-excel-empty { display:flex; flex:1; align-items:center; justify-content:center; color:#64748b; }
    .dark .distribution-excel-screen .distribution-excel-settings,.dark .distribution-excel-screen .distribution-excel-footer,.dark .distribution-excel-screen .distribution-excel-matrix,.dark .distribution-excel-screen .distribution-excel-matrix th,.dark .distribution-excel-screen .distribution-excel-matrix td { border-color:#475569; }
    .dark .distribution-excel-screen .distribution-excel-matrix th { background:#1e293b; }
    .dark .distribution-excel-screen .distribution-excel-matrix tbody tr:nth-child(odd) td { background:#111827; }
    .dark .distribution-excel-screen .distribution-excel-matrix tbody tr:nth-child(even) td { background:#1e293b; }
    .dark .distribution-excel-screen .distribution-excel-matrix td.distribution-quantity-changed { background:#172554; color:#93c5fd; }
    .dark .distribution-excel-screen .distribution-column-source,.dark .distribution-excel-screen .distribution-quantity-before { color:#94a3b8; }
    @media (max-width:1023px) { .distribution-excel-screen .distribution-excel-settings { grid-template-columns:repeat(2,minmax(0,1fr)); } .distribution-excel-screen .distribution-excel-file { grid-column:1 / -1; } }
    .dark .distribution-form-dialog { background:#111827; color:#e2e8f0; }
    .dark .distribution-form-dialog .distribution-form-label,.dark .distribution-form-dialog .distribution-option-group legend { color:#e2e8f0; }
    .dark .distribution-form-dialog .distribution-import-panel { background:#1e293b; border-color:#475569; }
    .dark .distribution-form-dialog .distribution-form-input,.dark .distribution-form-dialog .distribution-import-file,.dark .distribution-form-dialog .distribution-option,.dark .distribution-form-dialog .distribution-mapping-select { background:#111827; color:#e2e8f0; border-color:#64748b; }
    .dark .distribution-form-dialog .distribution-option-title { color:#f1f5f9; }
    .dark .distribution-form-dialog .distribution-option:has(input:checked) { background:#172554; border-color:#60a5fa; box-shadow:0 0 0 1px #60a5fa; }
    @media (max-width:767px) { .distribution-form-dialog .distribution-import-options { grid-template-columns:1fr; } }
</style>
<template x-teleport="body">
<div x-show="excelImport.visible" x-cloak class="fixed inset-0 z-[220] bg-white" role="dialog" aria-modal="true" aria-label="Excel取込">
    <div class="distribution-form-dialog distribution-excel-screen">
        <div class="distribution-excel-header">Excel取込：<span x-text="batchInfo?.name"></span></div>
        <div class="distribution-excel-settings">
            <label class="distribution-form-label distribution-excel-file">Excelファイル <span class="distribution-required">必須</span>
                <input x-ref="excelInput" type="file" accept=".xls,.xlsx" :disabled="excelImport.busy" @change="excelImport.file=$event.target.files[0];excelImport.preview=null;excelImport.mapping={};excelImport.acknowledged=false" class="distribution-import-file">
            </label>
            <fieldset class="distribution-option-group" :disabled="excelImport.busy">
                <legend>数量単位 <span class="distribution-required">必須</span></legend>
                <div class="distribution-option-grid">
                    <label class="distribution-option"><span class="distribution-option-title">バラ</span><input type="radio" name="distribution-excel-unit" value="piece" x-model="excelImport.unit" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="バラ"></label>
                    <label class="distribution-option"><span class="distribution-option-title">ケース</span><input type="radio" name="distribution-excel-unit" value="case" x-model="excelImport.unit" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="ケース"></label>
                </div>
            </fieldset>
            <fieldset class="distribution-option-group" :disabled="excelImport.busy">
                <legend>店舗見出し <span class="distribution-required">必須</span></legend>
                <div class="distribution-option-grid">
                    <label class="distribution-option"><span class="distribution-option-title">店舗名</span><input type="radio" name="distribution-excel-store-mode" value="name" x-model="excelImport.storeMode" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="店舗名"></label>
                    <label class="distribution-option"><span class="distribution-option-title">店舗コード</span><input type="radio" name="distribution-excel-store-mode" value="code" x-model="excelImport.storeMode" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="店舗コード"></label>
                </div>
            </fieldset>
            <button type="button" @click="previewExcel()" :disabled="excelImport.busy || !excelImport.file || !excelImport.unit || !excelImport.storeMode" class="rounded bg-indigo-600 px-4 py-2 text-sm text-white disabled:bg-gray-300">取込内容を確認</button>
        </div>
        <div class="distribution-excel-content">
            <p x-show="excelImport.error" class="shrink-0 whitespace-pre-wrap text-red-700" role="alert" x-text="excelImport.error"></p>
            <div x-show="!excelImport.preview" class="distribution-excel-empty">先頭シートを取り込みます。ファイルと取込設定を選択してください。</div>
            <template x-if="excelImport.preview"><div class="flex min-h-0 flex-1 flex-col gap-3">
                <div class="distribution-excel-summary">
                    <strong><span x-text="excelImport.preview.sheet_name"></span> ／ <span x-text="excelImport.preview.header_row"></span>行目 ／ <span x-text="excelImport.preview.excel_total"></span>バラ</strong>
                    <span>新規 <span x-text="excelImport.preview.new_count"></span>件 ／ 更新 <span x-text="excelImport.preview.update_count"></span>件 ／ 変更なし <span x-text="excelImport.preview.unchanged_count"></span>件 ／ スキップ <span x-text="excelImport.preview.skipped.length"></span>行</span>
                    <span class="text-slate-500">数量：バラ</span>
                </div>
                <div class="distribution-excel-messages">
                    <p x-show="excelImport.needsPreview" class="mb-2 text-amber-700">列の対応を変更しました。再確認してください。<button type="button" @click="previewExcel()" :disabled="excelImport.busy" class="ml-3 rounded bg-indigo-600 px-3 py-1 text-white">対応を反映して再確認</button></p>
                    <div class="text-red-700"><template x-for="(message,i) in excelImport.preview.errors" :key="i"><p x-text="message"></p></template></div>
                    <details x-show="excelImport.preview.warnings.length"><summary>注意事項（<span x-text="excelImport.preview.warnings.length"></span>件）</summary><template x-for="(message,i) in excelImport.preview.warnings" :key="i"><p class="text-amber-800" x-text="message"></p></template></details>
                    <p x-show="!excelImport.preview.errors.length && !excelImport.preview.rows.length" class="text-slate-600">取込対象の商品がありません。</p>
                    <div role="status" class="text-slate-600">
                        <template x-for="row in excelImport.preview.skipped.filter(row => row.reason_code === 'merged_store_quantity')" :key="row.row">
                            <p x-text="'商品CD'+row.code+'：'+row.name+'はスキップしました。（'+row.reason+'）'"></p>
                        </template>
                    </div>
                    <details x-show="excelImport.preview.skipped.some(row => row.reason_code !== 'merged_store_quantity')"><summary>スキップ行（<span x-text="excelImport.preview.skipped.filter(row => row.reason_code !== 'merged_store_quantity').length"></span>行）</summary><template x-for="row in excelImport.preview.skipped.filter(row => row.reason_code !== 'merged_store_quantity')" :key="row.row"><p x-text="row.row+'行目 '+row.code+'：'+row.reason"></p></template></details>
                </div>
                <div class="distribution-excel-matrix" x-init="$nextTick(() => $el.scrollTo(0,0))" tabindex="0" aria-label="商品CD・店舗別分配数の表">
                    <table :style="'min-width:'+Math.max(640,260+excelPreviewColumns().length*64)+'px'">
                        <thead><tr>
                            <th scope="col">商品CD／商品名<span class="distribution-column-source" x-text="excelImport.preview.code_column+'：'+excelImport.preview.code_header"></span></th>
                            <template x-for="column in excelPreviewColumns()" :key="column.column"><th scope="col" :style="excelImport.editingColumn===column.column ? 'z-index:4' : ''">
                                <div class="distribution-store-mapping" @click.outside="if(excelImport.editingColumn===column.column) excelImport.editingColumn=null" @keydown.escape.prevent.stop="excelImport.editingColumn=null; $el.querySelector('button').focus()">
                                    <button type="button" class="distribution-store-code" :disabled="excelImport.busy" :aria-label="column.column+'列の店舗対応を変更：'+column.reason" :aria-expanded="excelImport.editingColumn===column.column" :title="column.column+'：'+(column.header||'見出しなし')+' → '+column.reason" @click="excelImport.editingColumn=excelImport.editingColumn===column.column ? null : column.column">
                                        <span x-text="column.destination_code==null ? '未設定' : String(column.destination_code).padStart(2,'0')"></span><span class="distribution-store-code-arrow" aria-hidden="true">▾</span>
                                    </button>
                                    <div class="distribution-store-editor" x-show="excelImport.editingColumn===column.column" x-cloak>
                                        <label><span x-text="column.column+'：'+(column.header||'見出しなし')"></span>
                                            <select class="distribution-mapping-select" :aria-label="column.column+'列の取込先'" :disabled="excelImport.busy" @change="updateExcelPreviewMapping(column,$event.target.value)">
                                                <option value="" x-text="column.status === 'matched' ? column.reason : '店舗を選択'"></option>
                                                <option value="ignore">対象外</option>
                                                <template x-for="d in excelImport.preview.destinations" :key="d.code"><option :value="d.code" x-text="d.code+' '+d.name"></option></template>
                                            </select>
                                        </label>
                                        <button type="button" class="distribution-store-editor-close" @click="excelImport.editingColumn=null">閉じる</button>
                                    </div>
                                </div>
                            </th></template>
                        </tr></thead>
                        <tbody><template x-for="row in excelImport.preview.rows.slice(0,200)" :key="row.excel_row"><tr>
                            <td :title="row.name"><span class="distribution-product-code" x-text="row.product_code"></span><span class="distribution-product-name" x-text="row.name"></span></td>
                            <template x-for="column in excelPreviewColumns()" :key="column.column"><td :class="{'distribution-quantity-changed':excelPreviewQuantity(row,column)!==null && excelPreviewQuantity(row,column)!==excelPreviewQuantity(row,column,true)}">
                                <span x-text="excelPreviewQuantity(row,column) ?? '—'"></span>
                                <span class="distribution-quantity-before" x-show="row.action!=='新規' && excelPreviewQuantity(row,column)!==null && excelPreviewQuantity(row,column)!==excelPreviewQuantity(row,column,true)" x-text="'変更前 '+excelPreviewQuantity(row,column,true)"></span>
                            </td></template>
                        </tr></template></tbody>
                    </table>
                </div>
                <p x-show="excelImport.preview.rows.length>200" class="shrink-0 text-xs">先頭200行を表示しています。取込は全対象行に適用します。</p>
            </div></template>
        </div>
        <div class="distribution-excel-footer">
            <label x-show="excelImport.preview" class="distribution-excel-ack"><input type="checkbox" x-model="excelImport.acknowledged" :disabled="excelImport.busy || excelImport.needsPreview"> 店舗・数量・注意事項を確認しました</label>
            <button type="button" @click="excelImport.visible=false" :disabled="excelImport.busy" class="rounded border px-4 py-2">取り込まず閉じる</button>
            <button type="button" @click="commitExcel()" :disabled="excelImport.busy || !excelImport.preview || !excelImport.preview.rows.length || excelImport.preview.errors.length || !excelImport.acknowledged || excelImport.needsPreview" class="rounded bg-red-600 px-4 py-2 text-white disabled:bg-gray-300">この内容で取り込む</button>
        </div>
    </div>
</div>
</template>
