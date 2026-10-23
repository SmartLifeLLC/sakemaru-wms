@php
    $candidates=$this->recommendation['candidates'] ?? [];
    $selectedCandidate=$this->recommendation['basis']['candidate_key'] ?? 'sales';
@endphp
@if($candidates)
    <div class="ar-candidate-comparison" role="group" aria-label="数量設定の候補">
        <div class="ar-candidate-heading"><b>数量の候補を選択</b><span>少数実績のため2案を比較。保存までは変更されません。</span></div>
        <div class="ar-candidate-grid">
            @foreach(['sales'=>'売上基準','history'=>'過去量を参考'] as $candidateKey=>$candidateLabel)
                @php
                    $candidate=$candidates[$candidateKey] ?? ['available'=>false,'basis'=>[],'values'=>[]];
                    $candidateBasis=$candidate['basis'] ?? [];
                    $candidateValues=$candidate['values'] ?? [];
                    $available=!empty($candidate['available']) && !empty($candidateValues);
                    $selected=$available && $selectedCandidate === $candidateKey;
                    $unchanged=$selected && collect($candidateValues)->every(fn($value,$field) => (string)($this->form[$field] ?? '') === (string)$value);
                    $candidateMean=(float)($candidateBasis['mean_daily'] ?? 0);
                    $candidateBatch=(int)($candidateBasis['batch_quantity_pieces'] ?? 0);
                @endphp
                <article class="ar-candidate-card {{ $selected ? 'is-selected' : '' }} {{ !$available ? 'is-disabled' : '' }}" wire:key="ar-candidate-{{ $candidateKey }}">
                    <div class="ar-candidate-title">
                        <div class="ar-candidate-label"><b>{{ $candidateLabel }}</b><span>{{ !$available ? '算定不可' : ($selected ? ($unchanged ? '入力中' : '手修正あり') : ($candidateKey === 'sales' ? '初期候補' : '参考候補')) }}</span></div>
                        <button type="button" class="ar-btn {{ $selected ? 'primary' : '' }}" wire:click="selectRecommendationCandidate('{{ $candidateKey }}')" wire:loading.attr="disabled" wire:target="calculateSettings,selectRecommendationCandidate,recommendationUnitMode,recommendationReviewDays,savePalletUnit,saveShippingQuantities,registerShippingHistory,form.shipping_unit_mode,selectItem,form.item_id,form.channel,form.contractor_id,form.supplier_id,form.source_warehouse_id,form.delivery_course_id" aria-pressed="{{ $selected ? 'true' : 'false' }}" @disabled(!$available || $unchanged)>{{ !$available ? '算定不可' : ($unchanged ? '入力済み' : ($selected ? 'この候補に戻す' : 'この候補を入力')) }}</button>
                    </div>
                    @if($available)
                        <dl class="ar-candidate-values">
                            <div><dt>通常の1回量</dt><dd>{{ number_format($candidateBatch) }}<small>バラ</small></dd></div>
                            @foreach(['min_order_pieces'=>'最小発注数','max_order_pieces'=>'最大発注数','reorder_point_pieces'=>'発注点','order_multiple_pieces'=>'最小発注単位','target_stock_pieces'=>'補充目標'] as $candidateField=>$candidateFieldLabel)
                                <div><dt>{{ $candidateFieldLabel }}</dt><dd>{{ isset($candidateValues[$candidateField]) ? number_format($candidateValues[$candidateField]) : '—' }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="ar-candidate-note">@if($candidateKey === 'sales')平均売上・出荷 {{ $candidateBasis['review_days'] ?? '—' }}日分。@else実績 {{ number_format($candidateBasis['own_receipt_count'] ?? 0) }}回・{{ number_format($candidateBasis['own_receipt_days'] ?? 0) }}日の参考値です。@endif @if($candidateMean > 0)通常量は約{{ number_format($candidateBatch/$candidateMean,1) }}日分。@endif</p>
                    @else<p class="ar-candidate-note">{{ $candidateBasis['reason'] ?? 'この条件では算定できません。' }}</p>@endif
                </article>
            @endforeach
        </div>
        <p class="ar-help">在庫が増える場合があります。発注回数の減少を保証するものではありません。</p>
    </div>
@endif
