<div x-show="excelImport.visible" x-cloak class="fixed inset-0 z-[220] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Excel取込">
    <div class="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
        <div class="bg-slate-800 px-5 py-3 text-sm font-semibold text-white">Excel取込：<span x-text="batchInfo?.name"></span></div>
        <div class="overflow-auto p-5 text-sm">
            <p class="mb-3">数量入力シートを先頭にしてください。「単品CD」「自社CD」の見出しを探します。登録済み商品の数量は上書きします。</p>
            <div class="flex flex-wrap items-end gap-4">
                <label>Excelファイル<input x-ref="excelInput" type="file" accept=".xls,.xlsx" @change="excelImport.file=$event.target.files[0];excelImport.preview=null;excelImport.mapping={}" class="block text-xs"></label>
                <label>数量単位（必須）<select x-model="excelImport.unit" @change="excelImport.preview=null" class="block rounded border-gray-300"><option value="">選択してください</option><option value="piece">バラ</option><option value="case">ケース</option></select></label>
                <label>店舗見出し（必須）<select x-model="excelImport.storeMode" @change="excelImport.preview=null" class="block rounded border-gray-300"><option value="">選択してください</option><option value="name">店舗名</option><option value="code">店舗コード</option></select></label>
                <button @click="previewExcel()" :disabled="excelImport.busy || !excelImport.file || !excelImport.unit || !excelImport.storeMode" class="rounded bg-indigo-600 px-3 py-2 text-white disabled:bg-gray-300">取込内容を確認</button>
            </div>
            <p class="my-3 whitespace-pre-wrap text-red-700" role="alert" x-text="excelImport.error"></p>
            <template x-if="excelImport.preview"><div>
                <p class="my-3 font-semibold">対象：<span x-text="excelImport.preview.sheet_name"></span> ／ 見出し：<span x-text="excelImport.preview.header_row"></span>行目 ／ Excel内：<span x-text="excelImport.preview.excel_total"></span>バラ</p>
                <p>新規 <span x-text="excelImport.preview.new_count"></span>件 ／ 更新 <span x-text="excelImport.preview.update_count"></span>件 ／ 変更なし <span x-text="excelImport.preview.unchanged_count"></span>件 ／ スキップ <span x-text="excelImport.preview.skipped.length"></span>行</p>
                <details class="my-3" open><summary>店舗の対応・対象外列</summary><div class="mt-2 flex flex-wrap gap-2"><template x-for="column in excelImport.preview.columns" :key="column.column"><label class="rounded border p-2 text-xs"><span x-text="column.column+'：'+(column.header||'見出しなし')"></span><select class="ml-2 rounded border-gray-300 text-xs" @change="excelImport.mapping[column.column]=$event.target.value;excelImport.needsPreview=true"><option value="" x-text="column.reason"></option><option value="ignore">対象外として無視</option><template x-for="d in excelImport.preview.destinations" :key="d.code"><option :value="d.code" x-text="d.code+' '+d.name"></option></template></select></label></template></div><button x-show="excelImport.needsPreview" @click="previewExcel()" class="mt-3 rounded bg-indigo-600 px-3 py-1 text-white">対応を反映して再確認</button></details>
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
