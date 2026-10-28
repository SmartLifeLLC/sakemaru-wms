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
    .distribution-form-dialog .distribution-import-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:16px; }
    .distribution-form-dialog .distribution-option-group { min-width:0; }
    .distribution-form-dialog .distribution-option-group legend { margin-bottom:8px; color:#334155; font-size:13px; font-weight:600; }
    .distribution-form-dialog .distribution-option-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .distribution-form-dialog .distribution-option { display:grid; grid-template-columns:minmax(0,1fr) auto; align-content:start; gap:6px 8px; min-height:104px; border:1px solid #cbd5e1; border-radius:8px; background:white; padding:12px; cursor:pointer; }
    .distribution-form-dialog .distribution-option:hover { border-color:#93c5fd; }
    .distribution-form-dialog .distribution-option:has(input:checked) { border-color:#2563eb; background:#eff6ff; box-shadow:0 0 0 1px #2563eb; }
    .distribution-form-dialog .distribution-option:has(input:focus-visible) { outline:2px solid #2563eb; outline-offset:3px; }
    .distribution-form-dialog .distribution-option:has(input:disabled) { opacity:.6; cursor:wait; }
    .distribution-form-dialog .distribution-option input { width:16px; height:16px; margin:2px 0 0; accent-color:#2563eb; }
    .distribution-form-dialog .distribution-option-title { color:#0f172a; font-size:14px; font-weight:600; }
    .distribution-form-dialog .distribution-option-description { grid-column:1 / -1; color:#64748b; font-size:12px; line-height:1.6; }
    .distribution-form-dialog .distribution-import-preview-action { display:flex; justify-content:flex-end; margin-top:16px; }
    .distribution-form-dialog .distribution-mapping-select { margin-left:8px; border:1px solid #cbd5e1; border-radius:5px; background:white; padding:5px 8px; color:#0f172a; font-size:12px; }
    .dark .distribution-form-dialog { background:#111827; color:#e2e8f0; }
    .dark .distribution-form-dialog .distribution-form-label,.dark .distribution-form-dialog .distribution-option-group legend { color:#e2e8f0; }
    .dark .distribution-form-dialog .distribution-import-panel { background:#1e293b; border-color:#475569; }
    .dark .distribution-form-dialog .distribution-form-input,.dark .distribution-form-dialog .distribution-import-file,.dark .distribution-form-dialog .distribution-option,.dark .distribution-form-dialog .distribution-mapping-select { background:#111827; color:#e2e8f0; border-color:#64748b; }
    .dark .distribution-form-dialog .distribution-option-title { color:#f1f5f9; }
    .dark .distribution-form-dialog .distribution-option-description { color:#94a3b8; }
    .dark .distribution-form-dialog .distribution-option:has(input:checked) { background:#172554; border-color:#60a5fa; box-shadow:0 0 0 1px #60a5fa; }
    @media (max-width:767px) { .distribution-form-dialog .distribution-import-options { grid-template-columns:1fr; } }
</style>
<div x-show="excelImport.visible" x-cloak class="fixed inset-0 z-[220] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Excel取込">
    <div class="distribution-form-dialog flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
        <div class="bg-slate-800 px-5 py-3 text-sm font-semibold text-white">Excel取込：<span x-text="batchInfo?.name"></span></div>
        <div class="overflow-auto p-5 text-sm">
            <p class="mb-3">数量入力シートを先頭にしてください。「単品CD」「自社CD」の見出しを探します。登録済み商品の数量は上書きします。</p>
            <div class="distribution-import-panel">
                <label class="distribution-form-label">Excelファイル <span class="distribution-required">必須</span>
                    <input x-ref="excelInput" type="file" accept=".xls,.xlsx" :disabled="excelImport.busy" @change="excelImport.file=$event.target.files[0];excelImport.preview=null;excelImport.mapping={};excelImport.acknowledged=false" class="distribution-import-file">
                </label>
                <p class="mt-2 text-xs text-slate-500">対応形式：.xls / .xlsx（先頭のシートを取り込みます）</p>
            </div>
            <div class="distribution-import-options">
                <fieldset class="distribution-option-group" :disabled="excelImport.busy">
                    <legend>数量単位 <span class="distribution-required">必須</span></legend>
                    <div class="distribution-option-grid">
                        <label class="distribution-option">
                            <span class="distribution-option-title">バラ</span>
                            <input type="radio" name="distribution-excel-unit" value="piece" x-model="excelImport.unit" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="バラ">
                            <span class="distribution-option-description">入力された数量をそのまま取り込みます。</span>
                        </label>
                        <label class="distribution-option">
                            <span class="distribution-option-title">ケース</span>
                            <input type="radio" name="distribution-excel-unit" value="case" x-model="excelImport.unit" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="ケース">
                            <span class="distribution-option-description">商品マスタの入数を掛けて、バラ数に換算します。</span>
                        </label>
                    </div>
                </fieldset>
                <fieldset class="distribution-option-group" :disabled="excelImport.busy">
                    <legend>店舗見出し <span class="distribution-required">必須</span></legend>
                    <div class="distribution-option-grid">
                        <label class="distribution-option">
                            <span class="distribution-option-title">店舗名</span>
                            <input type="radio" name="distribution-excel-store-mode" value="name" x-model="excelImport.storeMode" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="店舗名">
                            <span class="distribution-option-description">「本店」「SD前」などの店舗名で照合します。</span>
                        </label>
                        <label class="distribution-option">
                            <span class="distribution-option-title">店舗コード</span>
                            <input type="radio" name="distribution-excel-store-mode" value="code" x-model="excelImport.storeMode" @change="excelImport.preview=null;excelImport.acknowledged=false" aria-label="店舗コード">
                            <span class="distribution-option-description">「01」「04」などの店舗CDで照合します。</span>
                        </label>
                    </div>
                </fieldset>
            </div>
            <div class="distribution-import-preview-action">
                <button type="button" @click="previewExcel()" :disabled="excelImport.busy || !excelImport.file || !excelImport.unit || !excelImport.storeMode" class="rounded bg-indigo-600 px-4 py-2 text-white disabled:bg-gray-300">取込内容を確認</button>
            </div>
            <p class="my-3 whitespace-pre-wrap text-red-700" role="alert" x-text="excelImport.error"></p>
            <template x-if="excelImport.preview"><div>
                <p class="my-3 font-semibold">対象：<span x-text="excelImport.preview.sheet_name"></span> ／ 見出し：<span x-text="excelImport.preview.header_row"></span>行目 ／ Excel内：<span x-text="excelImport.preview.excel_total"></span>バラ</p>
                <p>新規 <span x-text="excelImport.preview.new_count"></span>件 ／ 更新 <span x-text="excelImport.preview.update_count"></span>件 ／ 変更なし <span x-text="excelImport.preview.unchanged_count"></span>件 ／ スキップ <span x-text="excelImport.preview.skipped.length"></span>行</p>
                <details class="my-3" open><summary>店舗の対応・対象外列</summary><div class="mt-2 flex flex-wrap gap-2"><template x-for="column in excelImport.preview.columns" :key="column.column"><label class="rounded border p-2 text-xs"><span x-text="column.column+'：'+(column.header||'見出しなし')"></span><select class="distribution-mapping-select" @change="excelImport.mapping[column.column]=$event.target.value;excelImport.needsPreview=true"><option value="" x-text="column.reason"></option><option value="ignore">対象外として無視</option><template x-for="d in excelImport.preview.destinations" :key="d.code"><option :value="d.code" x-text="d.code+' '+d.name"></option></template></select></label></template></div><button x-show="excelImport.needsPreview" @click="previewExcel()" class="mt-3 rounded bg-indigo-600 px-3 py-1 text-white">対応を反映して再確認</button></details>
                <div class="my-3 text-red-700"><template x-for="(message,i) in excelImport.preview.errors" :key="i"><p x-text="message"></p></template></div>
                <details x-show="excelImport.preview.warnings.length" class="my-3"><summary>注意事項（入数差・対象外列・納品区分など）</summary><template x-for="(message,i) in excelImport.preview.warnings" :key="i"><p class="text-amber-800" x-text="message"></p></template></details>
                <div class="max-h-64 overflow-auto rounded border"><table class="w-full text-xs" style="min-width:1050px;table-layout:fixed"><thead class="sticky top-0 bg-slate-100"><tr><th class="p-2" style="width:55px">Excel行</th><th style="width:75px">商品CD</th><th style="width:220px">商品名</th><th style="width:60px">処理</th><th style="width:75px">更新前合計</th><th style="width:75px">取込後合計</th><th>店舗別変更（バラ）</th></tr></thead><tbody><template x-for="row in excelImport.preview.rows.slice(0,200)" :key="row.excel_row"><tr class="border-t"><td class="p-2" x-text="row.excel_row"></td><td x-text="row.product_code"></td><td x-text="row.name"></td><td x-text="row.action"></td><td x-text="Object.entries(row.before).filter(([k])=>k.startsWith('alloc_')).reduce((s,[k,v])=>s+Number(v||0),0)"></td><td x-text="row.after_total"></td><td x-text="Object.entries(row.allocations).sort(([a],[b])=>Number(a)-Number(b)).map(([k,v])=>k+': '+(row.before['alloc_'+k]||0)+' → '+v).join(' / ')"></td></tr></template></tbody></table></div>
                <p x-show="excelImport.preview.rows.length>200" class="text-xs">表示は先頭200行です。登録はプレビューの全対象行に適用されます。</p>
                <details class="my-3" x-show="excelImport.preview.skipped.length"><summary>スキップ行と理由</summary><template x-for="row in excelImport.preview.skipped" :key="row.row"><p x-text="row.row+'行目 '+row.code+'：'+row.reason"></p></template></details>
                <label class="mt-3 block"><input type="checkbox" x-model="excelImport.acknowledged"> 店舗の対応、数量の上書き、注意事項・スキップ行を確認しました</label>
            </div></template>
        </div>
        <div class="flex justify-end gap-3 border-t px-5 py-4">
            <button @click="excelImport.visible=false" :disabled="excelImport.busy" class="rounded border px-4 py-2">取り込まず閉じる</button>
            <button @click="commitExcel()" :disabled="excelImport.busy || !excelImport.preview || excelImport.preview.errors.length || !excelImport.acknowledged || excelImport.needsPreview" class="rounded bg-red-600 px-4 py-2 text-white disabled:bg-gray-300">この内容で取り込む</button>
        </div>
    </div>
</div>
