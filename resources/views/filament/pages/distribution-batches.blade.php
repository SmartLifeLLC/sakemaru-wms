<x-filament-panels::page>
    <div x-data="distributionBatchList()" class="distribution-batch-list" x-cloak>
        <style>
            .distribution-batch-list [x-cloak]{display:none!important}.distribution-batch-list .batch-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.distribution-batch-list input,.distribution-batch-list textarea{border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;font-size:13px}.distribution-batch-list button{border-radius:6px;padding:7px 12px;font-size:13px}.distribution-batch-list button:disabled{opacity:.5;cursor:wait}.distribution-batch-list .primary{background:#059669;color:white}.distribution-batch-list .danger{background:#dc2626;color:white}.distribution-batch-list .secondary{border:1px solid #cbd5e1;background:white}.distribution-batch-list table{border-collapse:collapse;width:100%;font-size:12px;white-space:nowrap}.distribution-batch-list td,.distribution-batch-list th{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.distribution-batch-list th{background:#f1f5f9;position:sticky;top:0}.distribution-batch-list th:last-child,.distribution-batch-list td:last-child{position:sticky;right:0;background:white;border-left:2px solid #cbd5e1;z-index:1}.distribution-batch-list th:last-child{background:#f1f5f9;z-index:2}.distribution-batch-list tr:nth-child(even){background:#f5f9ff}.distribution-batch-list .batch-scroll{overflow:auto;max-height:calc(100vh - 250px);border:1px solid #e2e8f0;border-radius:8px}.distribution-batch-list .batch-overlay{position:fixed;inset:0;z-index:200;background:#0007;display:flex;align-items:center;justify-content:center;padding:20px}.distribution-batch-list .batch-dialog{background:white;border-radius:10px;width:600px;max-width:100%;max-height:90vh;overflow:auto}.distribution-batch-list .batch-dialog header{background:#1e293b;color:white;padding:14px 20px}.distribution-batch-list .batch-dialog main{padding:20px;display:grid;gap:12px}.distribution-batch-list .batch-dialog label{display:grid;gap:5px;font-size:13px}.distribution-batch-list .batch-dialog footer{display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid #e2e8f0}.distribution-batch-list .batch-error{color:#b91c1c;white-space:pre-wrap;padding:8px 0}

            .distribution-batch-list .batch-tools{gap:10px;margin-bottom:12px}
            .distribution-batch-list .batch-search{width:260px;max-width:100%;height:36px;background:white}
            .distribution-batch-list .batch-date{display:flex;align-items:center;gap:8px;color:#64748b;font-size:12px}
            .distribution-batch-list .batch-date input{height:36px;background:white;color:#334155}
            .distribution-batch-list .batch-filterbar{display:flex;flex-wrap:wrap;align-items:center;gap:12px 24px;padding:12px 14px;margin-bottom:14px;border:1px solid #e2e8f0;border-radius:8px;background:white}
            .distribution-batch-list .batch-filter-group{display:flex;align-items:center;gap:10px;min-width:0}
            .distribution-batch-list .batch-filter-label{flex:none;color:#64748b;font-size:12px;font-weight:600}
            .distribution-batch-list .batch-filter-options{display:flex;flex-wrap:wrap;gap:3px;padding:3px;border:1px solid #e2e8f0;border-radius:7px;background:#f8fafc}
            .distribution-batch-list .batch-filter-choice{border:1px solid transparent;border-radius:5px;padding:5px 10px;color:#64748b;background:transparent;font-size:12px;line-height:20px;white-space:nowrap}
            .distribution-batch-list .batch-filter-choice:hover{color:#0f172a;background:#e2e8f0}
            .distribution-batch-list .batch-filter-choice[aria-pressed="true"]{color:#0f172a;background:white;border-color:#cbd5e1;box-shadow:0 1px 2px #0f172a10;font-weight:600}
            .distribution-batch-list .batch-filter-choice.department-warehouse[aria-pressed="true"]{color:#047857;background:#ecfdf5;border-color:#6ee7b7}
            .distribution-batch-list .batch-filter-choice.department-headquarters[aria-pressed="true"]{color:#1d4ed8;background:#eff6ff;border-color:#93c5fd}
            .distribution-batch-list button:focus-visible,.distribution-batch-list input:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
            .distribution-batch-list .batch-department-badge{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:5px;padding:3px 8px;background:#f8fafc;color:#64748b;font-size:12px;font-weight:600}
            .distribution-batch-list .batch-department-badge::before{content:"";width:6px;height:6px;flex:none;border-radius:50%;background:currentColor}
            .distribution-batch-list .batch-department-badge.is-warehouse{background:#ecfdf5;border-color:#a7f3d0;color:#047857}
            .distribution-batch-list .batch-department-badge.is-headquarters{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
            .distribution-batch-list .batch-empty{padding:20px 0;color:#64748b;font-size:13px}
            .distribution-batch-list .batch-scroll{max-height:calc(100vh - 300px)}
            .dark .distribution-batch-list .batch-filterbar,.dark .distribution-batch-list .batch-search,.dark .distribution-batch-list .batch-date input{background:#111827;border-color:#475569;color:#e2e8f0}
            .dark .distribution-batch-list .batch-filter-label,.dark .distribution-batch-list .batch-date{color:#cbd5e1}
            .dark .distribution-batch-list .batch-filter-options{background:#1e293b;border-color:#475569}
            .dark .distribution-batch-list .batch-filter-choice{color:#cbd5e1}
            .dark .distribution-batch-list .batch-filter-choice:hover,.dark .distribution-batch-list .batch-filter-choice[aria-pressed="true"]{color:white;background:#334155;border-color:#64748b}
            .dark .distribution-batch-list .batch-filter-choice.department-warehouse[aria-pressed="true"],.dark .distribution-batch-list .batch-department-badge.is-warehouse{background:#064e3b;border-color:#059669;color:#a7f3d0}
            .dark .distribution-batch-list .batch-filter-choice.department-headquarters[aria-pressed="true"],.dark .distribution-batch-list .batch-department-badge.is-headquarters{background:#1e3a8a;border-color:#3b82f6;color:#bfdbfe}
            @media(max-width:767px){.distribution-batch-list .batch-filterbar{align-items:stretch;gap:10px;padding:10px}.distribution-batch-list .batch-filter-group{width:100%;align-items:flex-start}.distribution-batch-list .batch-filter-label{width:60px;padding-top:9px}.distribution-batch-list .batch-filter-options{flex:1}.distribution-batch-list .batch-filter-choice{padding:4px 8px}.distribution-batch-list .batch-search{flex:1;min-width:160px}.distribution-batch-list .batch-scroll{max-height:calc(100vh - 390px)}}
        </style>
        <div class="batch-tools">
            <button type="button" class="primary" @click="openCreate()">＋ 新規分配登録</button>
            <input class="batch-search" x-model="search" @keydown.enter.prevent="load(1)" placeholder="分配名・備考で検索" aria-label="分配検索">
            <label class="batch-date">出荷日<input type="date" x-model="date" @change="load(1)" aria-label="出荷日で絞込"></label>
            <button type="button" class="secondary" @click="load(1)">検索</button>
        </div>
        <div class="batch-filterbar">
            <div class="batch-filter-group" role="group" aria-label="担当部署で絞込">
                <span class="batch-filter-label">担当部署</span>
                <div class="batch-filter-options">
                    <button type="button" class="batch-filter-choice" :aria-pressed="department===''" @click="department='';load(1)" aria-label="担当部署：すべて">すべて</button>
                    <button type="button" class="batch-filter-choice department-warehouse" :aria-pressed="department==='warehouse'" @click="department='warehouse';load(1)">倉庫担当</button>
                    <button type="button" class="batch-filter-choice department-headquarters" :aria-pressed="department==='headquarters'" @click="department='headquarters';load(1)">本部担当</button>
                    <button type="button" class="batch-filter-choice" :aria-pressed="department==='unassigned'" @click="department='unassigned';load(1)">未設定</button>
                </div>
            </div>
            <div class="batch-filter-group" role="group" aria-label="登録者で絞込">
                <span class="batch-filter-label">表示範囲</span>
                <div class="batch-filter-options">
                    <button type="button" class="batch-filter-choice" :aria-pressed="!mine" @click="mine=false;load(1)" aria-label="表示範囲：すべて">すべて</button>
                    <button type="button" class="batch-filter-choice" :aria-pressed="mine" @click="mine=true;load(1)">自分の分配</button>
                </div>
            </div>
            <div class="batch-filter-group" role="group" aria-label="処理状況で絞込">
                <span class="batch-filter-label">処理状況</span>
                <div class="batch-filter-options">
                    <button type="button" class="batch-filter-choice" :aria-pressed="status===''" @click="status='';load(1)" aria-label="処理状況：すべて">すべて</button>
                    <button type="button" class="batch-filter-choice" :aria-pressed="status==='pending'" @click="status='pending';load(1)">未生成</button>
                    <button type="button" class="batch-filter-choice" :aria-pressed="status==='generated'" @click="status='generated';load(1)" title="生成受付済みの明細を含む分配">生成受付済み</button>
                    <button type="button" class="batch-filter-choice" :aria-pressed="status==='archived'" @click="status='archived';load(1)">終了</button>
                </div>
            </div>
        </div>
        <p class="batch-error" role="alert" x-text="error"></p>
        <p x-show="loading">分配を読み込んでいます…</p>
        <div class="batch-scroll">
            <table><thead><tr><th>分配名</th><th>担当部署</th><th>出荷日</th><th>登録者</th><th>登録日時</th><th>最終更新者</th><th>最終更新日時</th><th>商品行数</th><th>分配合計（バラ）</th><th>処理状況</th><th>備考</th><th>操作</th></tr></thead>
                <tbody><template x-for="batch in batches" :key="batch.id"><tr>
                    <td x-text="batch.name"></td><td><span class="batch-department-badge" :class="{'is-warehouse':batch.responsible_department==='warehouse','is-headquarters':batch.responsible_department==='headquarters'}" x-text="batch.responsible_department_label || '未設定'"></span></td><td x-text="batch.shipping_date || '要確認'"></td><td x-text="batch.creator"></td><td x-text="batch.created_at"></td><td x-text="batch.updater"></td><td x-text="batch.updated_at"></td><td x-text="batch.row_count"></td><td x-text="batch.total"></td><td x-text="batch.status"></td><td x-text="batch.note"></td>
                    <td><a :href="'/admin/distribution-adjustment/'+batch.id" class="text-blue-700">詳細</a></td>
                </tr></template></tbody>
            </table>
        </div>
        <p x-show="!loading && batches.length===0" class="batch-empty" x-text="search || date || mine || department || status ? '条件に一致する分配はありません。条件を変更してください。' : '登録された分配はありません。新規分配登録から商品リストを作成してください。'"></p>
        <div class="batch-tools" style="margin-top:12px"><button class="secondary" :disabled="page<=1 || loading" @click="load(page-1)">前へ</button><span x-text="page+' / '+lastPage"></span><button class="secondary" :disabled="page>=lastPage || loading" @click="load(page+1)">次へ</button></div>
        <div class="batch-overlay" x-show="creating" role="dialog" aria-modal="true" aria-label="新規分配登録">
            <form class="batch-dialog" @submit.prevent="create()">
                <header>新規分配登録</header><main>
                    <label>分配名（必須）<input x-model="form.name" required maxlength="100" autofocus></label>
                    <label>出荷日（必須）<input type="date" x-model="form.shipping_date" required></label>
                    @include('filament.partials.distribution-department-field', ['departmentModel' => 'form.responsible_department', 'departmentBusy' => 'saving', 'departmentGroup' => 'create-department'])
                    <label>備考<textarea x-model="form.note" rows="3" maxlength="1000"></textarea></label>
                    <p>登録者：{{ auth()->user()?->name }}</p><p class="batch-error" x-text="formError" role="alert"></p>
                </main><footer><button type="button" class="secondary" :disabled="saving" @click="creating=false">登録せず閉じる</button><button type="submit" class="danger" :disabled="saving">登録して商品を入力</button></footer>
            </form>
        </div>
    </div>
    <script>
        window.distributionBatchList = function () {
            return {batches:[],search:'',date:'',mine:false,department:'',status:'',page:1,lastPage:1,loading:false,error:'',creating:false,saving:false,formError:'',form:{},
                init(){this.load(1)},
                openCreate(){this.form={name:'',shipping_date:{{ \Illuminate\Support\Js::from(now()->toDateString()) }},note:'',responsible_department:'',execution_id:crypto.randomUUID()};this.formError='';this.creating=true},
                async request(url,options={}){const response=await fetch(url,{...options,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,...options.headers}});const data=await response.json();if(!response.ok)throw new Error(Object.values(data.errors||{}).flat().join('\n')||data.result?.error_message||data.message||'処理に失敗しました。');return data.result.data},
                async load(page){this.loading=true;this.error='';try{const data=await this.request('/api/distribution/batches?'+new URLSearchParams({page,search:this.search,status:this.status,responsible_department:this.department,shipping_date:this.date,mine:this.mine?'1':'0'}));this.batches=data.data;this.page=data.current_page;this.lastPage=data.last_page}catch(e){this.error=e.message}finally{this.loading=false}},
                async create(){if(this.saving)return;this.saving=true;this.formError='';try{const batch=await this.request('/api/distribution/batches',{method:'POST',body:JSON.stringify(this.form)});window.location.href='/admin/distribution-adjustment/'+batch.id}catch(e){this.formError=e.message;this.saving=false}}
            };
        };
    </script>
</x-filament-panels::page>
