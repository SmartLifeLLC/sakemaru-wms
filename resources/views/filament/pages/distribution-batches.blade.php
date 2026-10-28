<x-filament-panels::page>
    <div x-data="distributionBatchList()" class="distribution-batch-list" x-cloak>
        <style>
            .distribution-batch-list [x-cloak]{display:none!important}.distribution-batch-list .batch-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.distribution-batch-list input,.distribution-batch-list textarea{border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;font-size:13px}.distribution-batch-list button{border-radius:6px;padding:7px 12px;font-size:13px}.distribution-batch-list button:disabled{opacity:.5;cursor:wait}.distribution-batch-list .primary{background:#059669;color:white}.distribution-batch-list .danger{background:#dc2626;color:white}.distribution-batch-list .secondary{border:1px solid #cbd5e1;background:white}.distribution-batch-list table{border-collapse:collapse;width:100%;font-size:12px;white-space:nowrap}.distribution-batch-list td,.distribution-batch-list th{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.distribution-batch-list th{background:#f1f5f9;position:sticky;top:0}.distribution-batch-list th:last-child,.distribution-batch-list td:last-child{position:sticky;right:0;background:white;border-left:2px solid #cbd5e1;z-index:1}.distribution-batch-list th:last-child{background:#f1f5f9;z-index:2}.distribution-batch-list tr:nth-child(even){background:#f5f9ff}.distribution-batch-list .batch-scroll{overflow:auto;max-height:calc(100vh - 250px);border:1px solid #e2e8f0;border-radius:8px}.distribution-batch-list .batch-overlay{position:fixed;inset:0;z-index:200;background:#0007;display:flex;align-items:center;justify-content:center;padding:20px}.distribution-batch-list .batch-dialog{background:white;border-radius:10px;width:600px;max-width:100%;max-height:90vh;overflow:auto}.distribution-batch-list .batch-dialog header{background:#1e293b;color:white;padding:14px 20px}.distribution-batch-list .batch-dialog main{padding:20px;display:grid;gap:12px}.distribution-batch-list .batch-dialog label{display:grid;gap:5px;font-size:13px}.distribution-batch-list .batch-dialog footer{display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #e2e8f0}.distribution-batch-list .batch-error{color:#b91c1c;white-space:pre-wrap;padding:8px 0}
        </style>
        <div class="batch-tools">
            <button type="button" class="primary" @click="openCreate()">新規分配登録</button>
            <input x-model="search" @keydown.enter.prevent="load(1)" placeholder="分配名・備考で検索" aria-label="分配検索">
            <input type="date" x-model="date" @change="load(1)" aria-label="出荷日で絞込">
            <label><input type="checkbox" x-model="mine" @change="load(1)"> 自分の分配</label>
            <select x-model="status" @change="load(1)" aria-label="処理状況で絞込" style="border:1px solid #cbd5e1;border-radius:6px;font-size:13px"><option value="">すべての処理状況</option value="pending">未生成</option><option value="generated">生成受付済みを含む</option><option value="archived">終了</option></select><button type="button" class="secondary" @click="load(1)">検索</button>
        </div>
        <p class="batch-error" role="alert" x-text="error"></p>
        <p x-show="loading">分配を読み込んでいます…</p>
        <div class="batch-scroll">
            <table><thead><tr><th>分配名</th><th>出荷日</th><th>登録者</th><th>登録日時</th><th>最終更新者</th><th>最終更新日時</th><th>商品行数</th><th>分配合計（バラ）</th><th>処理状況</th><th>備考</th><th>操作</th></tr></thead>
                <tbody><template x-for="batch in batches" :key="batch.id"><tr>
                    <td x-text="batch.name"></td><td x-text="batch.shipping_date || '要確認'"></td><td x-text="batch.creator"></td><td x-text="batch.created_at"></td><td x-text="batch.updater"></td><td x-text="batch.updated_at"></td><td x-text="batch.row_count"></td><td x-text="batch.total"></td><td x-text="batch.status"></td><td x-text="batch.note"></td>
                    <td><a :href="'/admin/distribution-adjustment/'+batch.id" class="text-blue-700">詳細</a></td>
                </tr></template></tbody>
            </table>
        </div>
        <p x-show="!loading && batches.length===0">登録された分配はありません。新規分配登録から商品リストを作成してください。</p>
        <div class="batch-tools" style="margin-top:12px"><button class="secondary" :disabled="page<=1 || loading" @click="load(page-1)">前へ</button><span x-text="page+' / '+lastPage"></span><button class="secondary" :disabled="page>=lastPage || loading" @click="load(page+1)">次へ</button></div>
        <div class="batch-overlay" x-show="creating" role="dialog" aria-modal="true" aria-label="新規分配登録">
            <form class="batch-dialog" @submit.prevent="create()">
                <header>新規分配登録</header><main>
                    <label>分配名（必須）<input x-model="form.name" required maxlength="100" autofocus></label>
                    <label>出荷日（必須）<input type="date" x-model="form.shipping_date" required></label>
                    <label>備考<textarea x-model="form.note" rows="3" maxlength="1000"></textarea></label>
                    <p>登録者：{{ auth()->user()?->name }}</p><p class="batch-error" x-text="formError" role="alert"></p>
                </main><footer><button type="button" class="secondary" :disabled="saving" @click="creating=false">登録せず閉じる</button><button type="submit" class="danger" :disabled="saving">登録して商品を入力</button></footer>
            </form>
        </div>
    </div>
    <script>
        window.distributionBatchList = function () {
            return {batches:[],search:'',date:'',mine:false,status:'',page:1,lastPage:1,loading:false,error:'',creating:false,saving:false,formError:'',form:{},
                init(){this.load(1)},
                openCreate(){this.form={name:'',shipping_date:{{ \Illuminate\Support\Js::from(now()->toDateString()) }},note:'',execution_id:crypto.randomUUID()};this.formError='';this.creating=true},
                async request(url,options={}){const response=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...options.headers}});const data=await response.json();if(!response.ok)throw new Error(Object.values(data.errors||{}).flat().join('\n')||data.result?.error_message||data.message||'処理に失敗しました。');return data.result.data},
                async load(page){this.loading=true;this.error='';try{const data=await this.request('/api/distribution/batches?'+new URLSearchParams({page,search:this.search,status:this.status,shipping_date:this.date,mine:this.mine?'1':'0'}));this.batches=data.data;this.page=data.current_page;this.lastPage=data.last_page}catch(e){this.error=e.message}finally{this.loading=false}},
                async create(){if(this.saving)return;this.saving=true;this.formError='';try{const batch=await this.request('/api/distribution/batches',{method:'POST',body:JSON.stringify(this.form)});window.location.href='/admin/distribution-adjustment/'+batch.id}catch(e){this.formError=e.message;this.saving=false}}
            };
        };
    </script>
</x-filament-panels::page>
