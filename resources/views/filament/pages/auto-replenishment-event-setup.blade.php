{{-- この商品のイベント：学習・適用の設定（商品別発注設定の編集ドロワー内） --}}
@if(($this->eventInfo['installed'] ?? false) && $helperItem)
    <details class="ar-editor-helper ar-event-setup" open>
        <summary>この商品のイベント（年末年始・お盆・GW）@if(!($this->eventInfo['has_profiles'] ?? false))<small class="ar-help">— 未学習。「おすすめ設定」で自動的に学習します</small>@endif</summary>
        <table class="ar-event-setup-table">
            <thead><tr><th>適用</th><th>イベント</th><th>この商品の倍率（本体平均・ピーク／年数）</th><th>次回の登録</th></tr></thead>
            <tbody>
            @foreach($this->eventInfo['types'] as $type => $info)
                <tr @class(['is-suggested' => $info['suggest']])>
                    <td><input type="checkbox" wire:model.live="eventSetup.{{ $type }}" aria-label="{{ $info['label'] }}を適用"></td>
                    <td>{{ $info['label'] }}@if($info['suggest'])<span class="ar-badge amber" style="margin-left:6px">候補</span>@endif</td>
                    <td class="ar-code">
                        @if($info['mean'] !== null){{ number_format($info['mean'], 2) }}倍（{{ number_format($info['peak'], 2) }}）／{{ $info['instances'] }}年
                            @if($info['stage'] === 'CANDIDATE')<small class="ar-help"> 候補（保存で有効）</small>@elseif($info['has_candidate'])<small class="ar-help"> 新しい候補あり（保存で置換）</small>@endif
                        @else — @endif
                    </td>
                    <td>@if($info['event'])<span class="ar-code">{{ substr($info['event']['start'], 0, 10) }}〜{{ substr($info['event']['end'], 5) }}</span> <span class="ar-badge {{ $info['event']['status'] === 'APPROVED' ? 'green' : 'amber' }}">{{ \App\Models\WmsDemandEvent::STATUSES[$info['event']['status']] ?? $info['event']['status'] }}</span>@else<small class="ar-help">未登録（イベント設定で素案を追加）</small>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="ar-event-setup-actions">
            <button type="button" class="ar-btn" wire:click="learnProductEvents" wire:loading.attr="disabled" wire:target="learnProductEvents,calculateSettings">この商品の倍率を学習{{ ($this->eventInfo['has_profiles'] ?? false) ? 'し直す' : 'する' }}</button>
            <span class="ar-help">チェックしたイベントだけが、この商品の予測と平常水準の除外に使われます（承認済みの期間のみ）。チェックを変えたら「おすすめ設定」で再計算し、保存で確定します。</span>
        </div>
        <div class="ar-event-progress ar-help" wire:stream="eventProgress" wire:loading.class="is-busy" wire:target="learnProductEvents,calculateSettings"></div>
    </details>
@endif
