@if(!empty($masterWarnings))
    <div class="ar-notice warn" role="status" aria-label="発注先・配送の注意">
        <b>発注先・配送を確認してください</b>
        @foreach($masterWarnings as $warning)<p>{{ $warning }}</p>@endforeach
        <small>この警告だけで発注を停止したり、設定を変更したりすることはありません。</small>
    </div>
@endif
