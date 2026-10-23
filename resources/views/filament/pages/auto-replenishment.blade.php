<x-filament-panels::page>
    @include('filament.pages.auto-replenishment-style')
    @php
        $title = ['settings'=>'商品別発注設定','checklist'=>'自動発注確認リスト','history'=>'自動発注履歴'][$screen];
        $labels = ['READY'=>'生成対象','NO_ORDER'=>'発注不要','HOLD'=>'保留','INCOMING_CREATED'=>'JX生成待ち','TRANSFER_PREPARED'=>'移動確定待ち','FAILED_RETRYABLE'=>'工程再開が必要','EOS_JX_READY'=>'JX生成完了','TRANSFER_QUEUED'=>'移動作成待ち','TRANSFER_PROCESSING'=>'移動作成中','TRANSFER_CREATED'=>'移動作成完了','RECONCILIATION_REQUIRED'=>'照合待ち','STOPPED'=>'停止','ABORTED'=>'未公開処理中止'];
    @endphp
    <div class="ar-shell" @if($run?->status === 'RUNNING') wire:poll.3s @endif>
        <main class="ar-main">
            <div class="ar-title"><div><small>発注 / 自動発注</small><h1>{{ $title }}</h1></div>@if($warehouse)
                @if($screen === 'settings')<button class="ar-warehouse-status {{ $warehouse->is_auto_order_enabled ? 'is-on' : '' }}" wire:click="editWarehouse" @disabled(!$control || $control->reset_state !== 'COMPLETE')><span>この倉庫の自動発注</span><strong>{{ $warehouse->is_auto_order_enabled ? 'ON' : 'OFF' }}</strong><span>設定する →</span></button>
                @else<span class="ar-badge {{ $warehouse->is_auto_order_enabled ? 'green' : '' }}">倉庫 {{ $warehouse->is_auto_order_enabled ? 'ON' : 'OFF' }}</span>@endif
            @endif</div>
            <nav class="ar-tabs">
                @foreach(['settings'=>'商品別発注設定','checklist'=>'確認リスト','history'=>'自動発注履歴'] as $key=>$name)
                    <a href="/admin/auto-order/{{ $key }}" class="{{ $screen === $key ? 'active' : '' }}">{{ $name }}</a>
                @endforeach
            </nav>
            @if(!$this->confirmation && !$this->showEditor && !$this->showWarehouseEditor && !$this->showCsv && !$detail && $errors->has('operation'))
                <div class="ar-notice warn" role="alert">{{ $errors->first('operation') }}</div>
            @endif
            @if(!$warehouse)
                <div class="ar-notice warn">右上の倉庫選択から、自動発注を管理する実倉庫を選択してください。</div>
            @else
            @if(!$control || $control->reset_state !== 'COMPLETE')
                <div class="ar-notice warn">初回リリースの設定初期化が未完了です。初期化完了後に商品を設定してください。</div>
            @elseif(!$control->enabled || !config('replenishment.enabled'))
                <div class="ar-notice">全体の自動発注は停止中です。設定・試算は利用できます。</div>
            @endif
            @if($control?->reset_completed_at)<p class="ar-help">初回初期化完了 {{ \Carbon\Carbon::parse($control->reset_completed_at)->format('Y/m/d H:i') }} / 全体 {{ $control->enabled ? 'ON' : 'OFF' }}</p>@endif
            @if($screen === 'settings')
                <div class="ar-toolbar">
                    <span>商品のON／OFFは「設定を編集」で変更できます。</span>
                    @if(count($this->selectedVersions))
                        <button class="ar-btn primary" wire:click="confirmSelectedState(true)" @disabled($selectedOffCount === 0)>選択{{ count($this->selectedVersions) }}件をON</button>
                        <button class="ar-btn" wire:click="confirmSelectedState(false)" @disabled($selectedOnCount === 0)>選択{{ count($this->selectedVersions) }}件をOFF</button>
                        <button class="ar-btn" wire:click="clearSelection">選択を解除</button>
                    @endif
                    <button class="ar-btn" wire:click="exportCsv">このページをCSV出力</button>
                    <button class="ar-btn" wire:click="$set('showCsv', true)" @disabled(!$control || $control->reset_state !== 'COMPLETE')>CSV取込</button>
                    <button class="ar-btn" wire:click="editWarehouse" @disabled(!$control || $control->reset_state !== 'COMPLETE')>倉庫の自動発注設定</button>
                    <button class="ar-btn" wire:click="requestConfirmation('{{ $control?->enabled ? 'global-off' : 'global-on' }}')" @disabled(!$control || $control->reset_state !== 'COMPLETE')>全体 {{ $control?->enabled ? 'OFFにする' : 'ONにする' }}</button>
                    <button class="ar-btn primary" wire:click="editPolicy" @disabled(!$control || $control->reset_state !== 'COMPLETE')>商品設定を追加</button>
                </div>
            @elseif($screen === 'checklist')
                <div class="ar-toolbar"><div><b>試算・未実行</b> @if($run) / {{ $run->created_at->format('m/d H:i') }} / {{ $run->status === 'RUNNING' ? '計算中' : '試算完了' }} @endif</div><button class="ar-btn" wire:click="exportCsv">このページをCSV出力</button><button class="ar-btn primary" wire:click="preview" wire:loading.attr="disabled" @disabled(!$control || $control->reset_state !== 'COMPLETE')>再チェック</button></div>
                <div class="ar-notice">倉庫・商品がOFFでも試算できます。発注・JX・倉庫移動データは生成しません。正常な自動発注に毎回の手動承認は不要です。</div>
            @else
                <div class="ar-notice">自動発注で生成したデータだけを表示します。EOSは入荷予定とJXの生成、物流は倉庫移動データの作成で完了です。JX送信・入荷の状態は別に確認できます。</div>
            @endif
            @foreach($errors->get('operation') as $error)<div class="ar-notice warn">{{ $error }}</div>@endforeach
            <div class="ar-filters">
                <label>商品CD・商品名<input type="search" wire:model.live.debounce.400ms="search" placeholder="CDまたは商品名"></label>
                <label>発注方式<select wire:model.live="channel"><option value="">すべて</option><option value="EOS">EOS発注</option><option value="LOGISTICS">物流発注（店間）</option></select></label>
                <label>状態<select wire:model.live="status"><option value="">すべて</option>
                    @if($screen === 'settings')<option value="ON">商品ON</option><option value="OFF">商品OFF</option>@else @foreach($labels as $key=>$name)<option value="{{ $key }}">{{ $name }}</option>@endforeach @endif
                </select></label>
                <span class="ar-loading" wire:loading>読み込み中…</span>
            </div>
            @if($screen === 'history')<div class="ar-toolbar"><button class="ar-btn" wire:click="$set('historyView','lines')">商品明細</button><button class="ar-btn" wire:click="$set('historyView','runs')">実行履歴</button>@if($this->historyRunId)<button class="ar-btn" wire:click="$set('historyRunId',null)">実行の絞込を解除</button>@endif</div>@endif
            @if($screen === 'checklist' && $run)<nav class="ar-tabs" aria-label="判定結果の絞込">@foreach([''=>'すべて','READY'=>'生成対象','NO_ORDER'=>'発注不要','HOLD'=>'保留・設定不備'] as $key=>$name)<button type="button" class="ar-btn {{ $this->status === $key ? 'primary' : '' }}" wire:click="$set('status','{{ $key }}')">{{ $name }} {{ $key === '' ? $phaseCounts->sum() : ($phaseCounts[$key] ?? 0) }}</button>@endforeach</nav>@endif
            <section class="ar-card"><header><b>[{{ $warehouse?->code }}] {{ $warehouse?->name }}</b><span>{{ $screen === 'settings' ? '数量はすべて総バラ・最大発注数は1回の上限' : '100件ごとに表示' }}</span></header>
                @if($screen === 'history' && $this->historyView === 'runs')
                <div class="ar-table-wrap"><table><thead><tr><th>実行ID</th><th>実行日時</th><th>結果</th><th>対象商品</th><th>生成</th><th>保留</th><th>失敗</th><th class="ar-sticky">操作</th></tr></thead><tbody>
                    @forelse($rows as $row)<tr><td class="ar-code">{{ $row->run_key }}</td><td>{{ $row->created_at->format('m/d H:i') }}</td><td>{{ ['RUNNING'=>'処理中','PARTIAL'=>'一部未完了','COMPLETED'=>'完了','NO_OP'=>'対象なし','FAILED'=>'失敗'][$row->status] ?? $row->status }}</td><td>{{ $row->scanned_count }}</td><td>{{ $row->generated_count }}</td><td>{{ $row->held_count }}</td><td>{{ $row->failed_count }}</td><td class="ar-sticky"><button class="ar-row-btn" wire:click="openRun({{ $row->id }})">生成明細</button></td></tr>@empty<tr><td colspan="8"><div class="ar-empty">自動発注の実行履歴はまだありません。</div></td></tr>@endforelse
                </tbody></table></div>
                @else
                <div class="ar-table-wrap"><table><thead><tr>
                    @if($screen === 'settings')<th>選択</th>@endif<th>商品CD</th><th class="product">商品名</th><th>方式</th>
                    @if($screen === 'settings')<th>発注先CD</th><th>発注先名</th><th>移動元CD</th><th>移動元名</th><th>発注点</th><th>最大発注数</th><th>最小発注数</th><th>最小単位</th><th>更新日時</th><th>状態</th>
                    @else<th>現在庫</th><th>未入荷</th><th>候補確保</th><th>発注総バラ</th><th>発注先CD</th><th>発注先名</th><th>入荷日</th>@if($screen === 'history')<th>出庫日</th><th>関連番号・後続状態</th>@endif<th>結果</th><th>{{ $screen === 'history' ? '生成日時' : '判定理由' }}</th>@endif
                    <th class="ar-sticky">操作</th></tr></thead><tbody>
                    @forelse($rows as $row)
                        <tr wire:key="{{ $screen }}-{{ $row->id }}">@if($screen === 'settings')<td><input type="checkbox" @checked(isset($this->selectedVersions[(string)$row->id])) wire:click="selectPolicy({{ $row->id }}, {{ $row->version }})" aria-label="商品{{ $row->item_code }}を選択"></td>@endif<td class="ar-code">{{ $row->item_code ?? '—' }}</td><td class="product">{{ $row->item_name ?? '設定確認が必要です' }}</td><td><span class="ar-badge">{{ $row->channel === 'EOS' ? 'EOS' : '物流' }}</span></td>
                            @if($screen === 'settings')
                                <td class="ar-code">{{ $row->contractor_code ?? '—' }}</td><td>{{ $row->contractor_name ?? '—' }}</td><td class="ar-code">{{ $row->source_code ?? '—' }}</td><td>{{ $row->source_name ?? '—' }}</td>
                                @foreach(['reorder_point_pieces','max_order_pieces','min_order_pieces','order_multiple_pieces'] as $col)<td class="num">{{ $row->$col === null ? '未設定' : number_format($row->$col) }}</td>@endforeach
                                <td>{{ $row->updated_at->format('m/d H:i') }}</td><td>@if(!$row->is_configured)<span class="ar-badge">未設定</span>@else<button class="ar-switch {{ $row->is_enabled ? 'on' : '' }}" wire:click="editPolicy({{ $row->id }})" aria-label="商品{{ $row->item_code }}の自動発注設定を編集" title="設定画面でON／OFFを変更">{{ $row->is_enabled ? 'ON' : 'OFF' }}</button>@endif</td>
                                <td class="ar-sticky"><div class="ar-row-actions"><button class="ar-row-btn" wire:click="editPolicy({{ $row->id }})">設定を編集</button><button type="button" class="ar-row-btn" wire:click="openPolicyTest({{ $row->id }})" aria-label="商品{{ $row->item_code }}の自動発注をテスト">テスト</button></div></td>
                            @else
                                <td class="num">{{ $row->stock ?? '—' }}</td><td class="num">{{ $row->incoming ?? '—' }}</td><td class="num">{{ $row->claims ?? '—' }}</td><td class="num">{{ number_format($row->quantity_pieces) }}</td><td class="ar-code">{{ $row->contractor_code ?? '—' }}</td><td>{{ $row->contractor_name ?? '—' }}</td>
                                <td>{{ $row->output_arrival ? \Carbon\Carbon::createFromFormat('ymd', $row->output_arrival)->format('m/d') : (($row->transfer_arrival ?: $row->arrival_date) ? \Carbon\Carbon::parse($row->transfer_arrival ?: $row->arrival_date)->timezone('Asia/Tokyo')->format('m/d') : '—') }}</td>
                                @if($screen === 'history')<td>{{ $row->picking_date ? \Carbon\Carbon::parse($row->picking_date)->timezone('Asia/Tokyo')->format('m/d') : '—' }}</td><td>
                                    @foreach($listLinks->get($row->id, collect()) as $listLink)@php $lt=$listTargets[$listLink->artifact_type]->get($listLink->artifact_id); @endphp<div>{{ ['incoming'=>'入荷','jx'=>'JX','stock_transfer'=>'移動'][$listLink->artifact_type] }} {{ $listLink->business_number ?: '#'.$listLink->artifact_id }} <span class="ar-badge">{{ $lt ? ($lt->status ?? ($lt->is_active ? '有効' : '無効')) : '関連データ削除済' }}</span></div>@endforeach
                                </td>@endif
                                <td><span class="ar-badge {{ in_array($row->phase,['EOS_JX_READY','TRANSFER_CREATED']) ? 'green' : (in_array($row->phase,['HOLD','RECONCILIATION_REQUIRED','FAILED_RETRYABLE']) ? 'amber' : '') }}">{{ $labels[$row->phase] ?? $row->phase }}</span></td>
                                <td>{{ $screen === 'history' ? $row->generated_at?->format('m/d H:i') : $row->reason }}</td><td class="ar-sticky"><button class="ar-row-btn" wire:click="showDetail({{ $row->id }})">{{ $screen === 'history' ? '履歴詳細' : '根拠を見る' }}</button></td>
                            @endif
                        </tr>
                    @empty<tr><td colspan="{{ $screen === 'settings' ? 15 : ($screen === 'history' ? 15 : 13) }}"><div class="ar-empty"><b>{{ $screen === 'settings' ? '商品別発注設定はまだありません' : '表示するデータがありません' }}</b><p>{{ $screen === 'settings' ? '商品設定を追加し、発注条件を登録してください。' : ($screen === 'checklist' ? '商品を設定して再チェックしてください。' : '自動生成した履歴は、関連データの削除後もここに残ります。') }}</p></div></td></tr>@endforelse
                </tbody></table></div>@endif<footer>{{ $rows->links() }}</footer>
            </section>
            @endif
        </main>
    </div>
    @if($this->testingId)
        @include('filament.pages.auto-replenishment-test')
    @endif
    @if($this->showEditor)
        @php
            $helperItem=$selectedItem;
            $recommendationReady=$helperItem && !empty($this->form['contractor_id']) && !empty($this->form['supplier_id'])
                && (($this->form['channel'] ?? 'EOS') === 'EOS' || (!empty($this->form['source_warehouse_id']) && !empty($this->form['delivery_course_id'])));
        @endphp
        <div class="ar-backdrop" wire:click="$set('showEditor', false)"></div><section class="ar-drawer ar-editor" role="dialog" aria-modal="true" aria-label="商品別発注設定" x-data="{ enabled: $wire.entangle('form.is_enabled').live }" x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('showEditor', false)">
            <header><div><b>商品別発注設定</b><small>[{{ $warehouse?->code }}] {{ $warehouse?->name }}</small></div><button type="button" wire:click="$set('showEditor', false)" aria-label="閉じる">×</button></header><div class="ar-drawer-body">
                <div class="ar-editor-product-row">
                    <div class="ar-editor-product"><span class="ar-code">{{ $helperItem?->code }}</span><strong>{{ $helperItem?->name ?? '商品を選択してください' }}</strong></div>
                    @if(!$this->editingId && $helperItem && !$this->showItemPicker)<button type="button" class="ar-btn" wire:click="$set('showItemPicker', true)">商品を変更</button>@endif
                    <label class="ar-product-enable ar-product-enable-inline" :class="{ 'is-on': enabled }">
                        <strong>自動発注</strong>
                        <span class="ar-enable-control"><input type="checkbox" role="switch" aria-label="この商品の自動発注" x-model="enabled"><span class="ar-enable-track" aria-hidden="true"></span><b x-text="enabled ? 'ON' : 'OFF'" aria-hidden="true"></b></span>
                    </label>
                </div>
                @if(!$this->editingId)@include('filament.pages.auto-replenishment-item-picker')@endif
                <section class="ar-editor-section">
                    <div class="ar-editor-section-heading"><h3>発注数量 <small>すべて総バラ</small></h3><span>ケース {{ $helperItem?->capacity_case ?? '—' }}入 ／ ボール {{ $helperItem?->capacity_carton ?? '—' }}入</span></div>
                    @if(($this->form['channel'] ?? '') === 'LOGISTICS')@include('filament.pages.auto-replenishment-pallet')@endif
                    <div class="ar-recommend-actions">
                        <div class="ar-recommend-copy"><strong>実績から数量の候補を作成</strong></div>
                        <label>発注単位の選び方<select wire:model.change="recommendationUnitMode" @disabled(in_array($this->form['shipping_unit_mode'] ?? '', ['PALLET','SHIPMENT'], true))><option value="AUTO">{{ ($this->form['shipping_unit_mode'] ?? '') === 'SHIPMENT' ? '登録済み出荷数量候補' : (($this->form['shipping_unit_mode'] ?? '') === 'PALLET' ? '登録済みパレット' : '実績に合わせる') }}</option><option value="PIECE">バラ主体</option><option value="CASE">ケース単位</option></select></label>
                        <label class="ar-review-days">売上基準の日数<input type="number" min="1" max="28" step="1" wire:model.blur="recommendationReviewDays" aria-label="売上基準の日数"></label>
                        <button type="button" class="ar-btn primary ar-recommend-button" wire:click="calculateSettings" wire:loading.attr="disabled" wire:target="calculateSettings,selectRecommendationCandidate,recommendationUnitMode,recommendationReviewDays,savePalletUnit,saveShippingQuantities,registerShippingHistory,form.shipping_unit_mode,selectItem,form.item_id,form.channel,form.contractor_id,form.supplier_id,form.source_warehouse_id,form.delivery_course_id" @disabled(!$recommendationReady)><x-heroicon-o-sparkles aria-hidden="true" />おすすめ設定</button>
                    </div>
                    @if(!$recommendationReady)<p class="ar-help">商品選択後に発注先・配送を自動入力します。特定できない場合は商品発注管理・移動配送コース設定を確認してください。</p>@endif
                    <p class="ar-loading" role="status" wire:loading wire:target="calculateSettings,selectRecommendationCandidate,recommendationUnitMode,recommendationReviewDays,savePalletUnit,saveShippingQuantities,registerShippingHistory,form.shipping_unit_mode,selectItem,form.item_id,form.channel,form.contractor_id,form.supplier_id,form.source_warehouse_id,form.delivery_course_id">出荷・入荷実績を確認して、おすすめ設定を計算しています…</p>
                    <fieldset class="ar-quantity-input-panel">
                        <legend>数量の入力</legend>
                        <div class="ar-quantity-input-content">
                            <div class="ar-editor-quantities">@foreach(['reorder_point_pieces'=>'発注点','max_order_pieces'=>'最大発注数','min_order_pieces'=>'最小発注数','order_multiple_pieces'=>'最小発注単位'] as $key=>$label)<label>{{ $label }}<input type="number" min="1" step="1" wire:model.blur="form.{{ $key }}" @readonly($key === 'order_multiple_pieces' && in_array($this->form['shipping_unit_mode'] ?? '', ['PALLET','SHIPMENT'], true))></label>@endforeach<label>補充目標（任意）<input type="number" min="1" wire:model.blur="form.target_stock_pieces" placeholder="未入力は発注点"></label></div>
                            <div class="ar-quantity-help">
                                @include('filament.pages.auto-replenishment-setting-help')
                                <details class="ar-editor-helper" @if($errors->hasAny(['field','type','quantity'])) open @endif><summary>ケース・バラから総バラに換算</summary><div class="ar-grid four"><label>換算先<select wire:model="quantityHelper.field">@foreach(['reorder_point_pieces'=>'発注点','max_order_pieces'=>'最大発注数','min_order_pieces'=>'最小発注数','order_multiple_pieces'=>'最小発注単位'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label><label>数量<input type="number" min="1" wire:model="quantityHelper.quantity"></label><label>単位<select wire:model="quantityHelper.type">@foreach(\App\Enums\QuantityType::cases() as $qt)<option value="{{ $qt->value }}">{{ $qt->name() }}</option>@endforeach</select></label><button type="button" class="ar-btn" wire:click="applyQuantityHelper" @disabled(!$helperItem)>総バラに換算して入力</button></div></details>
                            </div>
                        </div>
                    </fieldset>
                    @if($this->recommendation)@include('filament.pages.auto-replenishment-candidates')@endif
                    @if($this->recommendation)
                        @include('filament.pages.auto-replenishment-recommendation')
                    @endif
                    <label class="ar-strategy-label">発注方法<select wire:model.change="form.strategy_key"><option value="arrival_adaptive_v1">通常量をまとめて補充（欠品見込時は小口）</option><option value="arrival_batch_v1">まとめ発注（1回量固定）</option><option value="threshold_capped_v1">商品上限内の1回分を発注し、次回再判定</option><option value="threshold_v1">必要数が上限を超えたら全量保留</option></select></label>
                </section>
                <section class="ar-editor-section">
                    <div class="ar-editor-section-heading"><h3>発注先・配送</h3></div>
                    <div class="ar-editor-routes"><label>方式<select disabled wire:model.live="form.channel"><option value="EOS">EOS発注</option><option value="LOGISTICS">物流発注（店間）</option></select></label><label>発注先<select disabled wire:key="ar-contractor-{{ $this->form['item_id'] ?? 0 }}-{{ $this->form['channel'] ?? 'EOS' }}-{{ $this->form['contractor_id'] ?? 0 }}" wire:model.live="form.contractor_id"><option value="">発注先を選択</option>@foreach($routeOptions->unique('contractor_id') as $r)<option value="{{ $r->contractor_id }}" @selected((int)($this->form['contractor_id'] ?? 0) === (int)$r->contractor_id)>[{{ $r->code }}]{{ $r->name }}</option>@endforeach</select></label><label>仕入先<select disabled wire:key="ar-supplier-{{ $this->form['item_id'] ?? 0 }}-{{ $this->form['contractor_id'] ?? 0 }}-{{ $this->form['supplier_id'] ?? 0 }}" wire:model.live="form.supplier_id"><option value="">仕入先を選択</option>@foreach($routeOptions->where('contractor_id', $this->form['contractor_id'] ?? 0) as $r)<option value="{{ $r->supplier_id }}" @selected((int)($this->form['supplier_id'] ?? 0) === (int)$r->supplier_id)>[{{ $r->supplier_code }}]{{ $r->supplier_name }}</option>@endforeach</select></label></div>
                    @if(($this->form['channel'] ?? '') === 'LOGISTICS')<div class="ar-grid"><label>移動元倉庫<select disabled wire:model.live="form.source_warehouse_id"><option value="">移動元を選択</option>@foreach($warehouses->where('id','!=',$this->warehouseId) as $w)<option value="{{ $w->id }}">[{{ $w->code }}]{{ $w->name }}</option>@endforeach</select></label><label>配送コース<select disabled wire:model.live="form.delivery_course_id"><option value="">コースを選択</option>@foreach($courseOptions as $c)<option value="{{ $c->delivery_course_id }}">[{{ $c->code }}]{{ $c->name }} / 出庫LT {{ $c->picking_lead_days ?? '顧客既定' }}日</option>@endforeach</select></label></div>@endif
                    <p class="ar-help">商品発注管理・移動配送コース設定に従います。この画面では変更できません。</p>
                </section>
                @foreach($errors->all() as $error)<p class="ar-error" role="alert">{{ $error }}</p>@endforeach
            </div><footer><span class="ar-editor-save-state">保存後の自動発注：<b x-text="enabled ? 'ON' : 'OFF'"></b></span><button type="button" class="ar-btn" wire:click="$set('showEditor', false)">変更せず閉じる</button><button type="button" class="ar-btn danger" wire:click="savePolicy" wire:loading.attr="disabled" wire:target="savePolicy,calculateSettings,selectRecommendationCandidate,recommendationUnitMode,recommendationReviewDays,savePalletUnit,saveShippingQuantities,registerShippingHistory,form.shipping_unit_mode,selectItem,form.item_id,form.channel,form.contractor_id,form.supplier_id,form.source_warehouse_id,form.delivery_course_id">設定を保存</button></footer>
        </section>
    @endif
    @if($this->showWarehouseEditor)
        <div class="ar-backdrop" wire:click="$set('showWarehouseEditor', false)"></div><section class="ar-drawer" role="dialog" aria-modal="true" aria-label="倉庫の自動発注設定" x-data="{ enabled: $wire.entangle('warehouseForm.enabled') }" @keydown.escape.window="$wire.set('showWarehouseEditor', false)"><header><b>倉庫の自動発注設定</b><button wire:click="$set('showWarehouseEditor', false)" aria-label="閉じる">×</button></header><div class="ar-drawer-body">
            <label class="ar-warehouse-enable" :class="{ 'is-on': enabled }">
                <span class="ar-enable-copy"><strong>[{{ $warehouse?->code }}] {{ $warehouse?->name }}の自動発注</strong><span x-text="enabled ? 'ONに設定します' : 'OFFに設定します'"></span><small>「倉庫設定を保存」で反映します。商品未登録でも倉庫をONにできます。発注は全体ON・商品ONの対象だけに行います。</small></span>
                <span class="ar-enable-control"><input type="checkbox" role="switch" aria-label="この倉庫の自動発注" x-model="enabled"><span class="ar-enable-track" aria-hidden="true"></span><b x-text="enabled ? 'ON' : 'OFF'" aria-hidden="true"></b></span>
            </label>
            <h3>自動発注データの生成時刻（日本時間）</h3>
            <div class="ar-grid"><label>月〜土の生成時刻<input type="time" wire:model="warehouseForm.execution_time"></label><label>日曜日の生成時刻<input type="time" wire:model="warehouseForm.sunday_execution_time"></label></div>
            <p class="ar-help">実行曜日・時刻を変更した場合、新方式の商品は「おすすめ設定」で再計算・保存するまで自動発注を保留します。</p>
            <div class="ar-days">実行曜日 @foreach(['日','月','火','水','木','金','土'] as $day=>$name)<label><input type="checkbox" value="{{ $day }}" wire:model="warehouseForm.weekdays">{{ $name }}</label>@endforeach</div>
            <p class="ar-help">推奨：月〜土10:00・日曜22:00。EOS・物流発注ともにこの時刻に生成します。EOSの送信は発注先の送信設定、物流の入荷日・出庫日は既存の倉庫別ルールに従います。</p>
            <p class="ar-help">発注数量は、商品別発注設定の最大発注数で制限します。</p>
            @foreach($errors->all() as $error)<p class="ar-error">{{ $error }}</p>@endforeach
        </div><footer><button class="ar-btn" wire:click="$set('showWarehouseEditor', false)">変更せず閉じる</button><button class="ar-btn danger" wire:click="saveWarehouse">倉庫設定を保存</button></footer></section>
    @endif
    @if($this->showCsv)
        <div class="ar-backdrop" wire:click="$set('showCsv', false)"></div><section class="ar-drawer" role="dialog" aria-modal="true" aria-label="CSV取込"><header><b>CSV取込・変更内容確認</b><button wire:click="$set('showCsv', false)" aria-label="閉じる">×</button></header><div class="ar-drawer-body">
            <p>このページから出力したCSVを編集してください。100行まで、保存後はすべて商品OFFになります。入荷日・出庫日は既存設定に従って自動計算します。</p><input type="file" wire:model="csvFile" accept=".csv,text/csv"><button class="ar-btn" wire:click="previewCsv">変更内容を確認</button>
            @foreach($errors->all() as $error)<p class="ar-error">{{ $error }}</p>@endforeach
            @if($this->csvPreview)<p>{{ count($this->csvPreview) }}件を取り込みます。</p><table><thead><tr><th>商品CD</th><th>商品名</th><th>発注点（前→後）</th><th>最大数（前→後）</th></tr></thead><tbody>@foreach($this->csvPreview as $change)<tr><td>{{ $change['item_code'] }}</td><td>{{ $change['item_name'] }}</td><td>{{ $change['before']['reorder_point_pieces'] ?? '未設定' }} → {{ $change['after']['reorder_point_pieces'] }}</td><td>{{ $change['before']['max_order_pieces'] ?? '未設定' }} → {{ $change['after']['max_order_pieces'] }}</td></tr>@endforeach</tbody></table>@endif
        </div><footer><button class="ar-btn" wire:click="$set('showCsv', false)">取込せず閉じる</button>@if($this->csvPreview)<button class="ar-btn danger" wire:click="requestConfirmation('csv')">確認内容で取込</button>@endif</footer></section>
    @endif
    @if($detail)
        @php $s=$detail->confirmation_snapshot ?: $detail->proposal_snapshot; $p=$s['policy'] ?? []; @endphp
        <div class="ar-backdrop" wire:click="$set('detailId', null)"></div><section class="ar-drawer" role="dialog" aria-modal="true" aria-label="発注内容の詳細"><header><b>{{ $screen === 'history' ? '自動発注履歴' : '試算の根拠' }}</b><button wire:click="$set('detailId', null)" aria-label="閉じる">×</button></header><div class="ar-drawer-body">
            <h2>[{{ $s['item_code'] ?? '' }}] {{ $s['item_name'] ?? '設定確認が必要です' }}</h2><span class="ar-badge">{{ $labels[$detail->phase] ?? $detail->phase }}</span><p>{{ $detail->reason }}</p>
            <div class="ar-grid three">@foreach(['stock'=>'現在庫','incoming'=>'未入荷残','claims'=>'他の候補確保','arrival_date'=>'入荷予定日','business_date'=>'業務発注日','observed_at'=>'確認時刻'] as $key=>$name)<div class="ar-value"><small>{{ $name }}</small><b>{{ $s[$key] ?? '—' }}</b></div>@endforeach</div>
            <div class="ar-grid four">@foreach(['reorder_point_pieces'=>'発注点','max_order_pieces'=>'最大発注数','min_order_pieces'=>'最小発注数','order_multiple_pieces'=>'最小単位'] as $key=>$name)<div class="ar-value"><small>{{ $name }}（総バラ）</small><b>{{ $p[$key] ?? '—' }}</b></div>@endforeach</div>
            <div class="ar-notice">{{ $screen === 'checklist' ? '提案数量' : '発注数量' }}：<b>{{ number_format($detail->quantity_pieces) }}バラ</b><br>発注点未満かを確認し、既発注と候補確保を差し引いて、最小数・単位で丸めた後に上限を検査します。</div>
            @if($detail->artifact_output_snapshot && $detail->channel === 'LOGISTICS')<p>確定した出荷予定日：{{ $detail->artifact_output_snapshot['transfer']['picking_date'] ?? '—' }}</p>@endif
            <h3>関連データ</h3>
            @forelse($links as $link)
                @php $target=$targets[$link->artifact_type][$link->artifact_id] ?? null; $name=['incoming'=>'入荷予定','jx'=>'JX文書','stock_transfer'=>'倉庫移動'][$link->artifact_type] ?? $link->artifact_type; @endphp
                <div class="ar-related"><b>{{ $name }}</b> {{ $link->business_number ?: '#'.$link->artifact_id }}
                    @if(!$target)<span class="ar-badge">関連データ削除済</span>
                    @else <span class="ar-badge">{{ $target->status ?? '作成済' }}</span>
                        @if($link->artifact_type === 'incoming')<a href="/admin/wms-order-incoming-schedules?tableSearch={{ $link->business_number }}" target="_blank" rel="noopener">入荷予定を開く</a>
                        @elseif($link->artifact_type === 'jx')<a href="/admin/wms-order-documents" target="_blank" rel="noopener">JX文書一覧を開く</a>
                        @else<a href="{{ rtrim(config('app.core_url'),'/') }}/stocks/inventory/transfer" target="_blank" rel="noopener">倉庫移動一覧を開く</a>@endif
                    @endif
                </div>
            @empty<p class="ar-help">生成済みの関連データはありません。</p>@endforelse
            @if($screen === 'history' && in_array($detail->phase,['INCOMING_CREATED','TRANSFER_PREPARED','FAILED_RETRYABLE','STOPPED']))
                <div class="ar-notice warn">既存の入荷予定を再作成せず、未完了の工程だけを再開します。</div>@if(in_array($detail->phase,['INCOMING_CREATED','TRANSFER_PREPARED','FAILED_RETRYABLE']))<button class="ar-btn danger" wire:click="requestConfirmation('resume', {{ $detail->id }})">未完了工程を再開</button>@endif
                <label>未公開処理の中止理由<textarea wire:model="abortReason"></textarea></label><button class="ar-btn" wire:click="requestConfirmation('abort', {{ $detail->id }})">未公開処理を中止・照合完了</button>
            @endif
        </div><footer><button class="ar-btn" wire:click="$set('detailId', null)">閉じる</button></footer></section>
    @endif
    @if($this->confirmation)
        @php $confirm = $this->confirmation; @endphp
        <div class="ar-backdrop ar-confirm-backdrop" wire:click="cancelConfirmation"></div>
        <section class="ar-drawer ar-confirm" role="dialog" aria-modal="true" aria-labelledby="ar-confirm-title" x-data x-trap.inert.noscroll="true" @keydown.escape.window.stop="$wire.cancelConfirmation()">
            <header><b id="ar-confirm-title">{{ $confirm['title'] }}</b><button type="button" wire:click="cancelConfirmation" aria-label="確認を閉じる">×</button></header>
            <div class="ar-drawer-body">
                <p>{{ $confirm['message'] }}</p>
                @if($confirm['action'] === 'policies')
                    <div class="ar-notice">選択 {{ count($confirm['rows']) }}件 ／ 変更 {{ $confirm['change_count'] }}件 ／ すでに{{ $confirm['enabled'] ? 'ON' : 'OFF' }} {{ $confirm['unchanged_count'] }}件</div>
                    <div class="ar-confirm-table"><table><thead><tr><th>商品CD</th><th>商品名</th><th>現在</th><th>変更後</th></tr></thead><tbody>
                    @foreach($confirm['rows'] as $entry)<tr><td class="ar-code">{{ $entry['item_code'] }}</td><td class="ar-confirm-name">{{ $entry['item_name'] }}</td><td><span class="ar-badge {{ $entry['enabled'] ? 'green' : '' }}">{{ $entry['enabled'] ? 'ON' : 'OFF' }}</span></td><td><span class="ar-badge {{ $confirm['enabled'] ? 'green' : '' }}">{{ $confirm['enabled'] ? 'ON' : 'OFF' }}</span>@if(!$entry['changes'])<small>変更なし</small>@endif</td></tr>@endforeach
                    </tbody></table></div>
                    <p class="ar-help">設定値は保持します。ONへの変更時はシステムが商品設定・在庫・発注経路を検証します。入荷日・出庫日は既存設定に従います。設定不備がある場合は、選択した商品をすべて変更せずに戻します。</p>
                @elseif($confirm['action'] === 'abort')
                    <div class="ar-notice">中止理由：{{ $confirm['abort_reason'] ?: '未入力' }}</div>
                @endif
                @foreach($errors->all() as $error)<p class="ar-error" role="alert">{{ $error }}</p>@endforeach
            </div>
            <footer><button type="button" class="ar-btn" wire:click="cancelConfirmation">変更せず閉じる</button><button type="button" class="ar-btn danger" wire:click="applyConfirmation" wire:loading.attr="disabled" wire:target="applyConfirmation" @disabled($confirm['action'] === 'policies' && $confirm['change_count'] === 0)>{{ $confirm['confirm_label'] }}</button></footer>
        </section>
    @endif
</x-filament-panels::page>
