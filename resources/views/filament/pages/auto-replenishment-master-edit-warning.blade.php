@if($affectedCount > 0)
    <div role="status" aria-label="マスター変更時の自動発注への注意"
         style="border-left:3px solid #d97706;background:#fffbeb;color:#78350f;padding:10px 12px;border-radius:6px;font-size:13px;line-height:1.5">
        <b>自動発注への影響：関連設定 {{ number_format($affectedCount) }}件（OFFを含む）</b>
        @if(($aggregationSourceCount ?? 0) > 0)
            <p>内訳：この発注先 {{ number_format($directCount) }}件 ／ JX集約元 {{ number_format($aggregationSourceCount) }}件</p>
            <p>JX集約元は送信設定の影響対象です。入荷日は各発注先の設定に従います。</p>
        @endif
        <p>{{ $description }}</p>
        <p>保存済みの発注先・配送は自動更新されません。変更後は商品別設定の「テスト」で確認してください。保存の制限はありません。</p>
    </div>
@endif
